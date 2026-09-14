<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedGood;
use App\Models\Item;
use App\Models\JobWork;
use App\Models\Machinery;
use App\Models\ProductionBatch;
use App\Models\WorkOrder;
use App\Models\Workflow;
use App\Services\AppNotificationService;
use App\Services\MaterialSpecResolver;
use App\Services\TaskMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class WorkOrderController extends Controller
{
    public function __construct(
        private TaskMaterialService $taskMaterials,
        private MaterialSpecResolver $materialSpec,
    ) {}

    public function index(): JsonResponse
    {
        Log::info('[API][work-orders] start', ['user_id' => auth()->id()]);

        $query = WorkOrder::query()->latest();

        if ($this->materialsTableExists()) {
            $query->with(['materials.inventoryItem']);
        }

        $orders = $query->get();
        $wipEnsured = 0;

        foreach ($orders as $order) {
            if ($order->supervisor_status === 'wip') {
                try {
                    $this->ensureProductionBatchForWorkOrder($order);
                    $wipEnsured++;
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('[API][work-orders] ensure batch failed', [
                        'work_order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('[API][work-orders] done', [
            'user_id' => auth()->id(),
            'total' => $orders->count(),
            'wip_ensured' => $wipEnsured,
        ]);

        return response()->json(
            $orders->map(fn (WorkOrder $order) => $this->format($order)),
        );
    }

    public function manufacturedProducts(): JsonResponse
    {
        $byProduct = [];

        $query = WorkOrder::query()
            ->whereNotNull('product')
            ->where('product', '!=', '')
            ->where('supervisor_status', '!=', 'draft')
            ->orderByDesc('updated_at');

        if ($this->materialsTableExists()) {
            $query->with(['materials.inventoryItem']);
        }

        $orders = $query->get();

        $ordersByProduct = [];
        foreach ($orders as $order) {
            $key = $this->manufacturedProductKey($order);
            if ($key === '') {
                continue;
            }
            $ordersByProduct[$key][] = $order;
        }

        foreach ($ordersByProduct as $key => $productOrders) {
            $best = $productOrders[0];
            foreach ($productOrders as $order) {
                if (! empty($order->stages)) {
                    $best = $order;
                    break;
                }
            }

            $materialsSource = null;
            foreach ($productOrders as $order) {
                if ($this->materialsTableExists() && $order->materials->isNotEmpty()) {
                    $materialsSource = $order;
                    break;
                }
            }

            $option = $this->formatManufacturedProductOption($best);
            if ($materialsSource !== null) {
                $option['last_materials'] = $this->formatMaterialLines($materialsSource);
                $option['last_materials_at'] = ($materialsSource->updated_at ?? $materialsSource->created_at)
                    ?->format('Y-m-d H:i');
                $option['last_materials_route_card'] = $materialsSource->route_card_no;
                $option['last_materials_work_order_id'] = $materialsSource->id;
            }

            $byProduct[$key] = $option;
        }

        $finished = FinishedGood::query()
            ->whereNotNull('product_name')
            ->where('product_name', '!=', '')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($finished as $fg) {
            $key = strtolower(trim($fg->product_name));
            if ($key === '' || isset($byProduct[$key])) {
                continue;
            }

            $stages = [];
            $workflowId = null;
            if (filled($fg->workflow)) {
                $workflow = Workflow::query()
                    ->where('name', $fg->workflow)
                    ->first();
                if ($workflow) {
                    $workflowId = $workflow->id;
                    $stages = $workflow->stages ?? [];
                }
            }

            $byProduct[$key] = [
                'name' => $fg->product_name,
                'product' => $fg->product_name,
                'unit' => $fg->unit ?? 'pcs',
                'client_id' => $fg->client_id,
                'workflow_id' => $workflowId,
                'workflow_name' => $fg->workflow,
                'stages' => $stages,
                'source' => 'finished_good',
            ];
        }

        return response()->json(array_values($byProduct));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->filterWorkOrderAttributes($this->validatedPayload($request));
        $materials = $validated['materials'] ?? [];
        unset($validated['materials']);

        $validated = $this->hydrateWorkOrderStages($validated);

        $order = WorkOrder::create($validated);

        if ($materials !== []) {
            $this->taskMaterials->attachMaterialsForWorkOrder($order, $materials);
        }

        if ($this->materialsTableExists()) {
            $order->load(['materials.inventoryItem']);
        }

        if (($order->supervisor_status ?? 'pending') !== 'draft') {
            AppNotificationService::workflowAssigned($order);
        }

        return response()->json($this->format($order), 201);
    }

    public function update(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->supervisor_status !== 'draft') {
            return response()->json(
                ['message' => 'Only draft work orders can be edited'],
                422,
            );
        }

        $validated = $this->filterWorkOrderAttributes($this->validatedPayload($request));
        $materials = $validated['materials'] ?? [];
        unset($validated['materials']);

        $nextStatus = $validated['supervisor_status'] ?? 'draft';
        $validated['supervisor_status'] = $nextStatus === 'pending' ? 'pending' : 'draft';

        $validated = $this->hydrateWorkOrderStages($validated);

        $workOrder->update($validated);

        if ($this->materialsTableExists()) {
            $workOrder->materials()->delete();
            if ($materials !== []) {
                $this->taskMaterials->attachMaterialsForWorkOrder($workOrder, $materials);
            }
            $workOrder->load(['materials.inventoryItem']);
        }

        if ($validated['supervisor_status'] === 'pending') {
            AppNotificationService::workflowAssigned($workOrder->fresh());
        }

        return response()->json($this->format($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        )));
    }

    public function updateRoute(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if (! in_array($workOrder->supervisor_status, ['draft', 'pending', 'wip'], true)) {
            return response()->json(
                ['message' => 'Only draft, pending, or WIP route cards can be edited'],
                422,
            );
        }

        $validated = $request->validate([
            'workflow_id' => 'nullable|integer',
            'quantity' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string|max:2000',
            'updated_by' => 'nullable|string|max:255',
            'stages' => 'required|array|min:1',
            'stages.*.name' => 'nullable|string|max:255',
            'stages.*.order' => 'nullable|integer|min:1',
            'stages.*.machine_id' => 'nullable|integer',
            'stages.*.machine_name' => 'nullable|string|max:255',
            'stages.*.machine_code' => 'nullable|string|max:255',
            'stages.*.is_job_work' => 'nullable|boolean',
            'stages.*.vendor_id' => 'nullable|integer',
            'stages.*.vendor_name' => 'nullable|string|max:255',
            'stages.*.rate' => 'nullable|numeric|min:0',
            'materials' => 'nullable|array',
            'materials.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'materials.*.quantity' => 'required|integer|min:1',
            'materials.*.grade' => 'nullable|string|max:50',
            'materials.*.diameter_mm' => 'nullable|numeric|min:0',
        ]);

        $stages = $this->normalizeStages($validated['stages']);
        if ($stages === []) {
            return response()->json([
                'message' => 'Add at least one machine stage with a name',
            ], 422);
        }

        $materials = $validated['materials'] ?? [];
        unset($validated['materials'], $validated['stages']);

        $workOrder->update([
            ...$validated,
            'stages' => $stages,
            'is_job_work' => collect($stages)->contains(
                fn (array $stage) => (bool) ($stage['is_job_work'] ?? false),
            ),
        ]);

        if ($this->materialsTableExists()) {
            $workOrder->materials()->delete();
            if ($materials !== []) {
                $this->taskMaterials->attachMaterialsForWorkOrder($workOrder, $materials);
            }
            $workOrder->load(['materials.inventoryItem']);
        }

        if ($workOrder->supervisor_status === 'wip') {
            ProductionBatch::query()
                ->where('work_order_id', $workOrder->id)
                ->where('status', '!=', 'completed')
                ->update(['total_stages' => count($stages)]);
        }

        return response()->json($this->format($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        )));
    }

    public function updatePriority(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'priority' => 'required|string|in:normal,urgent',
        ]);

        if ($this->priorityColumnExists()) {
            $workOrder->update(['priority' => $data['priority']]);
        }

        if (Schema::hasColumn('production_batches', 'priority')) {
            ProductionBatch::query()
                ->where('work_order_id', $workOrder->id)
                ->update(['priority' => $data['priority']]);
        }

        $formatted = $this->format($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        ));

        if (! $this->priorityColumnExists()) {
            $formatted['priority'] = $data['priority'];
        }

        return response()->json($formatted);
    }

    public function updateStatus(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'supervisor_status' => 'required|string|in:pending,wip,rejection,finished_goods',
            'updated_by' => 'nullable|string|max:255',
        ]);

        $previousStatus = $workOrder->supervisor_status ?? 'pending';

        $workOrder->update([
            'supervisor_status' => $data['supervisor_status'],
            'updated_by' => $data['updated_by'] ?? null,
        ]);

        if ($previousStatus !== $data['supervisor_status']) {
            AppNotificationService::workflowStatusChanged(
                $workOrder->fresh(),
                $previousStatus,
            );
        }

        if ($data['supervisor_status'] === 'wip') {
            try {
                $fresh = $this->backfillWorkOrderStages($workOrder->fresh());
                $this->ensureProductionBatchForWorkOrder($fresh);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('[API][work-orders/status] batch ensure failed', [
                    'work_order_id' => $workOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $formatted = $this->format($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        ));

        if ($data['supervisor_status'] === 'wip') {
            $activeBatch = ProductionBatch::query()
                ->where('work_order_id', $workOrder->id)
                ->where('status', '!=', 'completed')
                ->latest('id')
                ->first();
            if ($activeBatch !== null) {
                $formatted['production_batch_id'] = $activeBatch->id;
                $formatted['production_batch_number'] = $activeBatch->batch_number;
            }
        }

        return response()->json($formatted);
    }

    public function destroy(Request $request, WorkOrder $workOrder): JsonResponse
    {
        if (strcasecmp((string) $request->user()?->role, 'admin') !== 0) {
            return response()->json([
                'message' => 'Only admin can delete work orders',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($workOrder->supervisor_status === 'finished_goods') {
            return response()->json([
                'message' => 'Completed work orders cannot be deleted',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        ProductionBatch::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->delete();

        JobWork::query()
            ->where('work_order_id', $workOrder->id)
            ->update(['work_order_id' => null]);

        $workOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Work order deleted',
        ]);
    }

    public function syncStages(WorkOrder $workOrder): JsonResponse
    {
        $fresh = $this->backfillWorkOrderStages($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        ));

        return response()->json($this->format($fresh));
    }

    public function prepareWip(WorkOrder $workOrder): JsonResponse
    {
        $fresh = $this->backfillWorkOrderStages($workOrder->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        ));

        $stages = $this->resolveStagesForWorkOrder($fresh);
        if ($stages === []) {
            return response()->json([
                'message' => 'No workflow stages found on this route card. '
                    .'Create a workflow in Tracking, then assign machines on the route card.',
            ], 422);
        }

        try {
            $this->ensureProductionBatchForWorkOrder($fresh->fresh());
        } catch (\Throwable $e) {
            report($e);
            Log::warning('[API][work-orders/prepare-wip] batch ensure failed', [
                'work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
            ]);
        }

        $activeBatch = ProductionBatch::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->latest('id')
            ->first();

        if ($activeBatch === null) {
            return response()->json([
                'message' => 'Production batch could not be created for this route card.',
                'work_order' => $this->format($fresh->fresh(
                    $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
                )),
            ], 422);
        }

        $formatted = $this->format($fresh->fresh(
            $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
        ));
        $formatted['production_batch_id'] = $activeBatch->id;
        $formatted['production_batch_number'] = $activeBatch->batch_number;

        return response()->json([
            'work_order' => $formatted,
            'production_batch_id' => $activeBatch->id,
            'production_batch_number' => $activeBatch->batch_number,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveStagesForWorkOrder(WorkOrder $workOrder): array
    {
        $stages = $this->normalizeStages($workOrder->stages ?? []);
        if ($stages !== []) {
            return $stages;
        }

        if ($workOrder->workflow_id) {
            $workflow = Workflow::query()->find($workOrder->workflow_id);
            if ($workflow !== null) {
                $fromWorkflow = $this->normalizeStages($workflow->stages ?? []);
                if ($fromWorkflow !== []) {
                    return $fromWorkflow;
                }
            }
        }

        if (filled($workOrder->workflow_name)) {
            $workflow = Workflow::query()->where('name', $workOrder->workflow_name)->first();
            if ($workflow !== null) {
                $fromWorkflow = $this->normalizeStages($workflow->stages ?? []);
                if ($fromWorkflow !== []) {
                    return $fromWorkflow;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function hydrateWorkOrderStages(array $attributes): array
    {
        $incomingStages = $attributes['stages'] ?? null;
        if (is_array($incomingStages) && $incomingStages !== []) {
            $normalized = $this->normalizeStages($incomingStages);
            if ($normalized !== []) {
                $attributes['stages'] = $normalized;

                return $attributes;
            }
        }

        $preview = WorkOrder::make($attributes);
        $resolved = $this->resolveStagesForWorkOrder($preview);

        if ($resolved !== []) {
            $attributes['stages'] = $resolved;

            if (empty($attributes['workflow_id']) && $preview->workflow_id) {
                $attributes['workflow_id'] = $preview->workflow_id;
            }
        }

        return $attributes;
    }

    private function backfillWorkOrderStages(WorkOrder $workOrder): WorkOrder
    {
        $resolved = $this->resolveStagesForWorkOrder($workOrder);
        if ($resolved === []) {
            $latestWorkflow = Workflow::query()->latest('id')->first();
            if ($latestWorkflow !== null) {
                $fromLatest = $this->normalizeStages($latestWorkflow->stages ?? []);
                if ($fromLatest !== []) {
                    $workOrder->update([
                        'workflow_id' => $latestWorkflow->id,
                        'stages' => $fromLatest,
                    ]);

                    Log::info('[API][work-orders] backfilled stages from latest workflow', [
                        'work_order_id' => $workOrder->id,
                        'workflow_id' => $latestWorkflow->id,
                    ]);

                    return $workOrder->fresh();
                }
            }

            return $workOrder;
        }

        $current = $this->normalizeStages($workOrder->stages ?? []);
        if ($current === [] || $current !== $resolved) {
            $updates = ['stages' => $resolved];

            if ($workOrder->workflow_id === null) {
                $workflowId = filled($workOrder->workflow_name)
                    ? Workflow::query()->where('name', $workOrder->workflow_name)->value('id')
                    : null;
                if ($workflowId === null) {
                    $workflowId = Workflow::query()->latest('id')->value('id');
                }
                if ($workflowId !== null) {
                    $updates['workflow_id'] = $workflowId;
                }
            }

            $workOrder->update($updates);

            return $workOrder->fresh();
        }

        return $workOrder;
    }

    /**
     * @param  array<mixed>|null  $stages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStages(?array $stages): array
    {
        if (! is_array($stages) || $stages === []) {
            return [];
        }

        $machineIds = [];
        foreach ($stages as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $machineId = (int) ($stage['machine_id'] ?? 0);
            if ($machineId > 0) {
                $machineIds[] = $machineId;
            }
        }
        $machineNames = $this->machineNamesById($machineIds);

        $normalized = [];
        foreach (array_values($stages) as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $name = trim((string) ($stage['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($stage['machine_name'] ?? ''));
            }
            if ($name === '') {
                $machineId = (int) ($stage['machine_id'] ?? 0);
                if ($machineId > 0 && isset($machineNames[$machineId])) {
                    $name = $machineNames[$machineId];
                }
            }
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                ...$stage,
                'name' => $name,
                'machine_name' => trim((string) ($stage['machine_name'] ?? $name)),
                'order' => (int) ($stage['order'] ?? ($index + 1)),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, int>  $machineIds
     * @return array<int, string>
     */
    private function machineNamesById(array $machineIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $machineIds),
            fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        return Machinery::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();
    }

    private function resolveBatchNumberForWorkOrder(int $workOrderId): string
    {
        $base = 'WO-'.str_replace('-', '', (string) $workOrderId);

        if (! ProductionBatch::query()->where('batch_number', $base)->exists()) {
            return $base;
        }

        $revision = 2;
        while (ProductionBatch::query()->where('batch_number', "{$base}-R{$revision}")->exists()) {
            $revision++;
        }

        return "{$base}-R{$revision}";
    }

    private function ensureProductionBatchForWorkOrder(WorkOrder $workOrder): void
    {
        $hasActiveBatch = ProductionBatch::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasActiveBatch) {
            return;
        }

        $batchNumber = $this->resolveBatchNumberForWorkOrder((int) $workOrder->id);

        $stages = $this->resolveStagesForWorkOrder($workOrder);
        if ($stages === []) {
            Log::warning('[API][work-orders] skip batch — no stages', [
                'work_order_id' => $workOrder->id,
                'workflow_id' => $workOrder->workflow_id,
                'workflow_name' => $workOrder->workflow_name,
            ]);

            return;
        }

        $firstStage = (string) ($stages[0]['name'] ?? 'Stage 1');
        $workflowId = $workOrder->workflow_id;
        if ($workflowId === null && filled($workOrder->workflow_name)) {
            $workflowId = Workflow::query()->where('name', $workOrder->workflow_name)->value('id');
        }

        $performedBy = $workOrder->updated_by ?: ($workOrder->created_by ?: 'System');
        $now = now();
        $timestamp = $now->format('Y-m-d H:i');

        $batch = ProductionBatch::create([
            'batch_number' => $batchNumber,
            'product_name' => $workOrder->product ?: ($workOrder->workflow_name ?: 'Product'),
            'workflow_id' => $workflowId,
            'current_stage' => $firstStage,
            'current_stage_index' => 0,
            'stage_order' => 1,
            'total_stages' => count($stages),
            'quantity' => max(1, (int) ($workOrder->quantity ?? 1)),
            'status' => 'in_progress',
            'priority' => $this->priorityColumnExists()
                ? ($workOrder->priority ?? 'normal')
                : 'normal',
            'started_at' => $now,
            'stage_started_at' => $now,
            'work_order_id' => $workOrder->id,
            'client_name' => $workOrder->client_name,
            'vendor_name' => $workOrder->vendor_name,
            'pr_number' => $workOrder->pr_number,
            'unit' => $workOrder->unit,
            'source' => 'work_order',
            'performed_by' => $performedBy,
            'user_name' => $performedBy,
            'stage_history' => [[
                'stage' => $firstStage,
                'user' => $performedBy,
                'started' => $timestamp,
                'assigned_at' => $timestamp,
                'completed' => '',
                'duration' => '—',
            ]],
        ]);

        try {
            $this->taskMaterials->consumeForProductionBatch($batch);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('[API][work-orders] batch material consume failed', [
                'batch_id' => $batch->id,
                'work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function materialsTableExists(): bool
    {
        return Schema::hasTable('work_order_materials');
    }

    private function priorityColumnExists(): bool
    {
        return Schema::hasColumn('work_orders', 'priority');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filterWorkOrderAttributes(array $validated): array
    {
        if (! $this->priorityColumnExists()) {
            unset($validated['priority']);
        }

        return $validated;
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'workflow_id' => 'nullable|integer',
            'workflow_name' => 'nullable|string|max:255',
            'client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'vendor_id' => 'nullable|integer',
            'vendor_name' => 'nullable|string|max:255',
            'item_id' => 'nullable|integer',
            'product' => 'nullable|string|max:255',
            'item_code' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'quantity' => 'nullable|integer|min:1',
            'pr_number' => 'nullable|string|max:255',
            'route_card_no' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:2000',
            'stages' => 'nullable|array',
            'stages.*.name' => 'nullable|string|max:255',
            'stages.*.order' => 'nullable|integer|min:1',
            'stages.*.machine_id' => 'nullable|integer',
            'stages.*.machine_name' => 'nullable|string|max:255',
            'stages.*.machine_code' => 'nullable|string|max:255',
            'stages.*.per_piece_time' => 'nullable|numeric|min:0',
            'stages.*.is_job_work' => 'nullable|boolean',
            'stages.*.vendor_id' => 'nullable|integer',
            'stages.*.vendor_name' => 'nullable|string|max:255',
            'stages.*.rate' => 'nullable|numeric|min:0',
            'is_job_work' => 'nullable|boolean',
            'supervisor_status' => 'nullable|string|in:draft,pending,wip,rejection,finished_goods',
            'priority' => 'nullable|string|in:normal,urgent',
            'flow_step' => 'nullable|string|max:100',
            'created_by' => 'nullable|string|max:255',
            'updated_by' => 'nullable|string|max:255',
            'materials' => 'nullable|array',
            'materials.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'materials.*.quantity' => 'required|integer|min:1',
            'materials.*.grade' => 'nullable|string|max:50',
            'materials.*.diameter_mm' => 'nullable|numeric|min:0',
        ]);
    }

    private function formatManufacturedProductOption(WorkOrder $order): array
    {
        $stages = $order->stages ?? [];
        if (empty($stages) && $order->workflow_id) {
            $workflow = Workflow::query()->find($order->workflow_id);
            if ($workflow) {
                $stages = $workflow->stages ?? [];
            }
        }
        if (empty($stages) && filled($order->workflow_name)) {
            $workflow = Workflow::query()
                ->where('name', $order->workflow_name)
                ->first();
            if ($workflow) {
                $stages = $workflow->stages ?? [];
            }
        }

        $item = $this->resolveItemForWorkOrder($order);

        $displayCode = filled($order->item_code)
            ? trim($order->item_code)
            : ($item?->code ?: trim((string) $order->product));

        return [
            'name' => $displayCode,
            'product' => $displayCode,
            'item_id' => $order->item_id ?? $item?->id,
            'item_code' => $order->item_code ?: $item?->code,
            'description' => $item?->description,
            'unit' => $order->unit ?? 'pcs',
            'quantity' => $order->quantity,
            'default_qty' => $order->quantity,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'vendor_id' => $order->vendor_id,
            'vendor_name' => $order->vendor_name,
            'workflow_id' => $order->workflow_id,
            'workflow_name' => $order->workflow_name,
            'route_card_no' => $order->route_card_no ?? null,
            'stages' => $stages,
            'is_job_work' => (bool) $order->is_job_work,
            'source' => 'work_order',
            'last_work_order_id' => $order->id,
            ...$this->itemTimingFields($order->item_id ?? $item?->id, $displayCode),
        ];
    }

    private function manufacturedProductKey(WorkOrder $order): string
    {
        if ($order->item_id) {
            return 'id:'.$order->item_id;
        }

        if (filled($order->item_code)) {
            return 'code:'.strtolower(trim($order->item_code));
        }

        $product = strtolower(trim((string) $order->product));

        return $product === '' ? '' : 'name:'.$product;
    }

    private function format(WorkOrder $order): array
    {
        if ($this->materialsTableExists()) {
            $order->loadMissing(['materials.inventoryItem']);
        }

        $materialLines = $this->materialsTableExists() && $order->relationLoaded('materials')
            ? $this->formatMaterialLines($order)
            : [];

        $item = $this->resolveItemForWorkOrder($order);
        $itemCode = filled($order->item_code)
            ? trim($order->item_code)
            : ($item?->code ? trim($item->code) : null);

        return [
            'id' => $order->id,
            'workflow_id' => $order->workflow_id,
            'workflow_name' => $order->workflow_name,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'vendor_id' => $order->vendor_id,
            'vendor_name' => $order->vendor_name,
            'item_id' => $order->item_id ?? $item?->id,
            'product' => $order->product,
            'item_code' => $itemCode,
            'unit' => $order->unit,
            'quantity' => $order->quantity,
            'pr_number' => $order->pr_number,
            'route_card_no' => $order->route_card_no,
            'remarks' => $order->remarks,
            'stages' => $this->resolveStagesForWorkOrder($order),
            'is_job_work' => $order->is_job_work,
            'supervisor_status' => $order->supervisor_status,
            'priority' => $this->priorityColumnExists()
                ? ($order->priority ?? 'normal')
                : 'normal',
            'flow_step' => $order->flow_step,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'updated_at' => $order->updated_at?->format('Y-m-d H:i'),
            'created_by' => $order->created_by,
            'updated_by' => $order->updated_by,
            'materials' => $materialLines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatMaterialLines(WorkOrder $order): array
    {
        if (! $this->materialsTableExists()) {
            return [];
        }

        $order->loadMissing(['materials.inventoryItem']);

        return $order->materials->map(function ($line) {
            $resolved = $this->materialSpec->forWorkOrderMaterial($line);

            return [
                'inventory_item_id' => $line->inventory_item_id,
                'name' => $line->inventoryItem?->name ?? '—',
                'grade' => $resolved['grade'],
                'diameter_mm' => $resolved['diameter_mm'],
                'quantity' => $line->quantity,
                'unit' => $line->unit ?? $line->inventoryItem?->unit ?? 'pcs',
                'sku' => $line->inventoryItem?->sku,
            ];
        })->values()->all();
    }

    private function resolveItemForWorkOrder(WorkOrder $order): ?Item
    {
        $item = $order->item_id ? Item::query()->find($order->item_id) : null;
        if ($item !== null) {
            return $item;
        }

        if (filled($order->item_code)) {
            $item = Item::query()
                ->whereRaw('LOWER(code) = ?', [strtolower(trim($order->item_code))])
                ->first();
            if ($item !== null) {
                return $item;
            }
        }

        if (filled($order->product)) {
            $key = strtolower(trim($order->product));

            return Item::query()
                ->whereRaw('LOWER(code) = ?', [$key])
                ->orWhereRaw('LOWER(name) = ?', [$key])
                ->first();
        }

        return null;
    }

    /**
     * @return array{per_piece_time: float|null, average_time: float|null}
     */
    private function itemTimingFields(?int $itemId, ?string $productName = null): array
    {
        $item = null;

        if ($itemId !== null) {
            $item = Item::query()->find($itemId);
        }

        if ($item === null && filled($productName)) {
            $key = strtolower(trim($productName));
            $item = Item::query()
                ->whereRaw('LOWER(code) = ?', [$key])
                ->orWhereRaw('LOWER(name) = ?', [$key])
                ->first();
        }

        return [
            'per_piece_time' => $item?->per_piece_time,
            'average_time' => $item?->average_time,
        ];
    }
}
