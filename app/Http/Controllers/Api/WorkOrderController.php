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
use App\Support\DisplayValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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

        $validated = $this->enforceItemCodeForRouteCard($validated);
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

        $validated = $this->enforceItemCodeForRouteCard($validated);
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
        $validated = $request->validate([
            'workflow_id' => 'nullable|integer',
            'workflow_name' => 'nullable|string|max:255',
            'client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'item_id' => 'nullable|integer|exists:items,id',
            'product' => 'nullable|string|max:255',
            'item_code' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'route_card_no' => 'nullable|string|max:255',
            'quantity' => 'nullable|integer|min:1',
            'work_order_quantity' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string|max:2000',
            'priority' => 'nullable|string|in:normal,urgent',
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
            'production_batch_id' => 'nullable|integer|exists:production_batches,id',
        ]);

        $stages = $this->normalizeStages($validated['stages']);
        if ($stages === []) {
            return response()->json([
                'message' => 'Add at least one machine stage with a name',
            ], 422);
        }

        $targetBatchId = isset($validated['production_batch_id'])
            ? (int) $validated['production_batch_id']
            : null;

        if ($blocked = $this->rejectRouteEditUnlessAllowed($workOrder, $targetBatchId)) {
            return $blocked;
        }

        $materials = $validated['materials'] ?? [];
        unset($validated['materials'], $validated['stages'], $validated['production_batch_id']);

        if ($targetBatchId !== null && $targetBatchId > 0) {
            return $this->updateRouteForProductionBatch($workOrder, $targetBatchId, $stages, $validated);
        }

        if ($this->workOrderHasActiveQuantitySplits($workOrder)) {
            return response()->json([
                'message' => 'This route card has split movement lines. Open Edit Route on the main line or split line (WO-… / WO-…-P1) in Product Movement.',
            ], 422);
        }

        if (array_key_exists('route_card_no', $validated) && blank($validated['route_card_no'])) {
            unset($validated['route_card_no']);
        }

        $itemFields = array_intersect_key($validated, array_flip([
            'item_id', 'item_code', 'product',
        ]));
        if ($itemFields !== []) {
            $validated = $this->fillAndRequireItemCode(array_merge([
                'item_id' => $workOrder->item_id,
                'item_code' => $workOrder->item_code,
                'product' => $workOrder->product,
            ], $validated));
        }

        $preserveBatchQuantities = $this->workOrderHasActiveQuantitySplits($workOrder);
        if ($preserveBatchQuantities) {
            unset($validated['quantity']);
        }

        $workOrder->update([
            ...$validated,
            'stages' => $stages,
            'is_job_work' => collect($stages)->contains(
                fn (array $stage) => (bool) ($stage['is_job_work'] ?? false),
            ),
        ]);

        if ($itemFields !== []) {
            $workOrder->refresh();
            $resolvedCode = $this->resolveItemCodeForWorkOrder($workOrder);
            if ($resolvedCode !== '') {
                $productPatch = [
                    'product' => $resolvedCode,
                    'item_code' => $resolvedCode,
                ];
                if ($workOrder->item_id !== null) {
                    $productPatch['item_id'] = $workOrder->item_id;
                }
                $workOrder->update($productPatch);

                ProductionBatch::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('status', '!=', 'completed')
                    ->update([
                        'product_name' => $resolvedCode,
                    ]);
            }
        }

        if (
            ! $preserveBatchQuantities
            && array_key_exists('quantity', $validated)
            && $validated['quantity'] !== null
        ) {
            $plannedQty = max(1, (int) $validated['quantity']);
            ProductionBatch::query()
                ->where('work_order_id', $workOrder->id)
                ->where('status', '!=', 'completed')
                ->update(['quantity' => $plannedQty]);
        }

        if ($this->materialsTableExists()) {
            $workOrder->materials()->delete();
            if ($materials !== []) {
                $this->taskMaterials->attachMaterialsForWorkOrder($workOrder, $materials);
            }
            $workOrder->load(['materials.inventoryItem']);
        }

        if (in_array($workOrder->supervisor_status, ['wip', 'rejection', 'quality_check'], true)) {
            $stageCount = count($stages);
            ProductionBatch::query()
                ->where('work_order_id', $workOrder->id)
                ->where('status', '!=', 'completed')
                ->get()
                ->each(function (ProductionBatch $batch) use ($stages, $stageCount, $workOrder) {
                    $stageOrder = max(1, min((int) $batch->stage_order, $stageCount));
                    $currentStage = $stages[$stageOrder - 1]['name'] ?? $batch->current_stage;
                    $updates = [
                        'total_stages' => $stageCount,
                        'stage_order' => $stageOrder,
                        'current_stage_index' => $stageOrder - 1,
                        'current_stage' => $currentStage,
                    ];
                    if ($workOrder->workflow_id !== null) {
                        $updates['workflow_id'] = $workOrder->workflow_id;
                    }
                    $batch->update($updates);
                });

            if ($this->priorityColumnExists() && isset($validated['priority'])) {
                ProductionBatch::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('status', '!=', 'completed')
                    ->update(['priority' => $validated['priority']]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Route card updated',
            'data' => $this->format($workOrder->fresh(
                $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
            )),
        ]);
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

        $this->assertWorkOrderHasItemCode($workOrder);

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
                'message' => 'Only admin can delete route cards',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($workOrder->supervisor_status === 'finished_goods') {
            return response()->json([
                'message' => 'Completed work orders cannot be deleted',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        ProductionBatch::query()
            ->where('work_order_id', $workOrder->id)
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
        $this->assertWorkOrderHasItemCode($workOrder);

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
            $row = [
                ...$stage,
                'name' => $name,
                'machine_name' => trim((string) ($stage['machine_name'] ?? $name)),
                'order' => (int) ($stage['order'] ?? ($index + 1)),
            ];
            if (array_key_exists('rate', $stage) && $stage['rate'] !== null && $stage['rate'] !== '') {
                $row['rate'] = (float) $stage['rate'];
            }
            $normalized[] = $row;
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
        return 'WO-'.str_replace('-', '', (string) $workOrderId);
    }

    private function ensureProductionBatchForWorkOrder(WorkOrder $workOrder): void
    {
        if ($this->resolveItemCodeForWorkOrder($workOrder) === '') {
            Log::warning('[API][work-orders] skip batch — missing item code', [
                'work_order_id' => $workOrder->id,
            ]);

            return;
        }

        $hasActiveBatch = ProductionBatch::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasActiveBatch) {
            return;
        }

        // Do not spawn WO-*-R2/R3 cards when production already ran for this route card.
        if (ProductionBatch::query()->where('work_order_id', $workOrder->id)->exists()) {
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
    private function rejectRouteEditUnlessAllowed(
        WorkOrder $workOrder,
        ?int $batchId,
    ): ?JsonResponse {
        $status = (string) ($workOrder->supervisor_status ?? 'pending');
        $allowedWorkOrder = ['draft', 'pending', 'wip', 'rejection', 'quality_check'];
        if (in_array($status, $allowedWorkOrder, true)) {
            return null;
        }

        if ($batchId !== null && $batchId > 0) {
            $batch = ProductionBatch::query()->find($batchId);
            if (
                $batch !== null
                && (int) $batch->work_order_id === (int) $workOrder->id
                && $batch->status !== 'completed'
            ) {
                return null;
            }
        }

        return response()->json(
            ['message' => 'This route card cannot be edited in its current status'],
            422,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function updateRouteForProductionBatch(
        WorkOrder $workOrder,
        int $batchId,
        array $stages,
        array $validated,
    ): JsonResponse {
        $batch = ProductionBatch::query()->findOrFail($batchId);
        if ((int) $batch->work_order_id !== (int) $workOrder->id) {
            return response()->json([
                'message' => 'This movement line does not belong to the route card.',
            ], 422);
        }

        if ($batch->status === 'completed') {
            return response()->json([
                'message' => 'Completed movement lines cannot be edited.',
            ], 422);
        }

        if (! Schema::hasColumn('production_batches', 'route_stages')) {
            return response()->json([
                'message' => 'Server migration required: route_stages on production_batches.',
            ], 503);
        }

        $stageCount = count($stages);
        $stageOrder = max(1, min((int) $batch->stage_order, $stageCount));
        $currentStage = $stages[$stageOrder - 1]['name'] ?? $batch->current_stage;

        $batchUpdates = [
            'route_stages' => $stages,
            'total_stages' => $stageCount,
            'stage_order' => $stageOrder,
            'current_stage_index' => $stageOrder - 1,
            'current_stage' => $currentStage,
        ];

        if (array_key_exists('quantity', $validated) && $validated['quantity'] !== null) {
            $batchUpdates['quantity'] = max(1, (int) $validated['quantity']);
        }

        $batch->update($batchUpdates);

        if (
            array_key_exists('work_order_quantity', $validated)
            && $validated['work_order_quantity'] !== null
        ) {
            $workOrder->update([
                'quantity' => max(1, (int) $validated['work_order_quantity']),
            ]);
        }

        if ($this->priorityColumnExists() && isset($validated['priority'])) {
            $batch->update(['priority' => $validated['priority']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Route updated for '.$batch->batch_number,
            'data' => [
                'work_order' => $this->format($workOrder->fresh(
                    $this->materialsTableExists() ? ['materials.inventoryItem'] : [],
                )),
                'production_batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
            ],
        ]);
    }

    /**
     * Partial forward creates multiple active batches on one route card — route edits
     * must not overwrite each line's quantity with the work order total.
     */
    private function workOrderHasActiveQuantitySplits(WorkOrder $workOrder): bool
    {
        $activeBatchIds = ProductionBatch::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->pluck('id');

        if ($activeBatchIds->count() > 1) {
            return true;
        }

        if ($activeBatchIds->isEmpty()) {
            return false;
        }

        $batchId = (int) $activeBatchIds->first();

        if (
            Schema::hasColumn('production_batches', 'parent_batch_id')
            && ProductionBatch::query()
                ->where('id', $batchId)
                ->whereNotNull('parent_batch_id')
                ->exists()
        ) {
            return true;
        }

        if (Schema::hasColumn('production_batches', 'parent_batch_id')) {
            return ProductionBatch::query()
                ->where('parent_batch_id', $batchId)
                ->where('status', '!=', 'completed')
                ->exists();
        }

        return false;
    }

    private function filterWorkOrderAttributes(array $validated): array
    {
        if (! $this->priorityColumnExists()) {
            unset($validated['priority']);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function enforceItemCodeForRouteCard(array $validated): array
    {
        if (($validated['supervisor_status'] ?? 'pending') === 'draft') {
            return $validated;
        }

        return $this->fillAndRequireItemCode($validated);
    }

    private function assertWorkOrderHasItemCode(WorkOrder $workOrder): void
    {
        if ($this->resolveItemCodeForWorkOrder($workOrder) === '') {
            throw ValidationException::withMessages([
                'item_code' => 'Item code is required to create a route card. Select an item from the catalog.',
            ]);
        }
    }

    private function resolveItemCodeForWorkOrder(WorkOrder $workOrder): string
    {
        return $this->resolveItemCode(
            $workOrder->item_code,
            $workOrder->item_id !== null ? (int) $workOrder->item_id : null,
        );
    }

    private function resolveItemCode(?string $itemCode, ?int $itemId): string
    {
        $code = DisplayValue::normalize($itemCode) ?? '';

        if ($code === '' && $itemId !== null && $itemId > 0) {
            $item = Item::query()->find($itemId);
            if ($item !== null) {
                $code = DisplayValue::normalize($item->code) ?? '';
            }
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fillAndRequireItemCode(array $validated): array
    {
        $itemId = ! empty($validated['item_id']) ? (int) $validated['item_id'] : null;
        $code = $this->resolveItemCode(
            isset($validated['item_code']) ? (string) $validated['item_code'] : null,
            $itemId,
        );

        if ($code === '') {
            throw ValidationException::withMessages([
                'item_code' => 'Item code is required to create a route card. Select an item from the catalog.',
            ]);
        }

        $validated['item_code'] = $code;

        if ($itemId === null) {
            $item = Item::query()
                ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                ->first();
            if ($item !== null) {
                $validated['item_id'] = $item->id;
            }
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
            'item_id' => 'nullable|integer|exists:items,id',
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
        $productLabel = trim((string) $order->product);
        $catalogName = $item?->name ? trim((string) $item->name) : '';
        if ($catalogName === '' || strcasecmp($catalogName, $displayCode) === 0) {
            if ($productLabel !== '' && strcasecmp($productLabel, $displayCode) !== 0) {
                $catalogName = $productLabel;
            }
        }

        return [
            'name' => $displayCode,
            'product' => $displayCode,
            'item_id' => $order->item_id ?? $item?->id,
            'item_code' => $order->item_code ?: $item?->code,
            'description' => $item?->description,
            'catalog_name' => $catalogName !== '' && strcasecmp($catalogName, $displayCode) !== 0
                ? $catalogName
                : null,
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
        $itemCode = DisplayValue::normalize($order->item_code)
            ?? DisplayValue::normalize($item?->code)
            ?? DisplayValue::normalize($order->product)
            ?? DisplayValue::normalize($order->workflow_name);
        $displayProduct = $itemCode
            ?? DisplayValue::normalize($order->product)
            ?? DisplayValue::normalize($order->workflow_name);

        return [
            'id' => $order->id,
            'workflow_id' => $order->workflow_id,
            'workflow_name' => DisplayValue::normalize($order->workflow_name) ?? $order->workflow_name,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'vendor_id' => $order->vendor_id,
            'vendor_name' => $order->vendor_name,
            'item_id' => $order->item_id ?? $item?->id,
            'product' => $displayProduct,
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

        $itemCode = DisplayValue::normalize($order->item_code);
        if ($itemCode !== null) {
            $item = Item::query()
                ->whereRaw('LOWER(code) = ?', [strtolower($itemCode)])
                ->first();
            if ($item !== null) {
                return $item;
            }
        }

        $productKey = DisplayValue::normalize($order->product)
            ?? DisplayValue::normalize($order->workflow_name);
        if ($productKey !== null) {
            $key = strtolower($productKey);

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
