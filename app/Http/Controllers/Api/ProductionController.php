<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchMaterialRequest;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\ProductionBatch;
use App\Models\Rejection;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workflow;
use App\Models\Machinery;
use App\Services\AppNotificationService;
use App\Services\BatchStageMachineService;
use App\Services\InventoryStockAllocationService;
use App\Services\JobWorkFlowService;
use App\Services\MaterialSpecResolver;
use App\Services\PermissionService;
use App\Services\TaskMaterialService;
use App\Support\DisplayValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductionController extends Controller
{
    public function __construct(
        private JobWorkFlowService $jobWorkFlow,
        private TaskMaterialService $taskMaterials,
        private MaterialSpecResolver $materialSpec,
        private InventoryStockAllocationService $stockAllocation,
    ) {}
    public function batches(Request $request): JsonResponse
    {
        $payload = [];
        $failedIds = [];

        $query = ProductionBatch::query()
            ->with(['workflow', 'workOrder'])
            ->orderByDesc('id');

        if ($request->boolean('active_only')) {
            $query->where('status', '!=', 'completed');
        }

        $batches = $query->get();

        Log::info('[API][production/batches] start', [
            'user_id' => auth()->id(),
            'batch_count' => $batches->count(),
            'active_only' => $request->boolean('active_only'),
        ]);

        foreach ($batches as $batch) {
            try {
                $payload[] = $this->format($batch);
            } catch (\Throwable $e) {
                report($e);
                $failedIds[] = (int) $batch->id;
                Log::warning('[API][production/batches] format failed', [
                    'batch_id' => (int) $batch->id,
                    'error' => $e->getMessage(),
                ]);
                $fallback = $this->formatBatchFallback((int) $batch->id);
                if ($fallback !== null) {
                    $payload[] = $fallback;
                }
            }
        }

        Log::info('[API][production/batches] done', [
            'user_id' => auth()->id(),
            'returned' => count($payload),
            'failed_ids' => $failedIds,
            'batches' => collect($payload)->map(fn ($b) => [
                'id' => $b['id'] ?? null,
                'batch_number' => $b['batch_number'] ?? null,
                'status' => $b['status'] ?? null,
                'current_stage' => $b['current_stage'] ?? null,
                'work_order_id' => $b['work_order_id'] ?? null,
                'product' => $b['product'] ?? null,
            ])->values()->all(),
        ]);

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_number' => 'required|string|max:255|unique:production_batches,batch_number',
            'product' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'workflow_id' => 'nullable|integer',
            'workflow' => 'nullable|string|max:255',
            'current_stage' => 'nullable|string|max:255',
            'stage_order' => 'nullable|integer|min:1',
            'total_stages' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:100',
            'started_at' => 'nullable|string',
            'stage_started_at' => 'nullable|string',
            'pr_number' => 'nullable|string|max:255',
            'supplier' => 'nullable|string|max:255',
            'vendor_name' => 'nullable|string|max:255',
            'client_name' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:100',
            'performed_by' => 'nullable|string|max:255',
            'user_name' => 'nullable|string|max:255',
            'checkin_id' => 'nullable|integer',
            'work_order_id' => 'nullable|integer',
            'priority' => 'nullable|string|in:normal,urgent',
            'stage_history' => 'nullable|array',
            'activity_log' => 'nullable|array',
        ]);

        $workflowId = $data['workflow_id'] ?? null;
        if ($workflowId === null && ! empty($data['workflow'])) {
            $workflowId = Workflow::query()->where('name', $data['workflow'])->value('id');
        }

        $batch = ProductionBatch::create([
            'batch_number' => $data['batch_number'],
            'product_name' => $data['product'],
            'workflow_id' => $workflowId,
            'current_stage' => $data['current_stage'] ?? null,
            'current_stage_index' => max(0, (($data['stage_order'] ?? 1) - 1)),
            'stage_order' => $data['stage_order'] ?? 1,
            'total_stages' => $data['total_stages'] ?? 1,
            'quantity' => $data['quantity'],
            'status' => $data['status'] ?? 'in_progress',
            'priority' => $data['priority'] ?? 'normal',
            'started_at' => $data['started_at'] ?? null,
            'stage_started_at' => $data['stage_started_at'] ?? null,
            'pr_number' => $data['pr_number'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'vendor_name' => $data['vendor_name'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'unit' => $data['unit'] ?? null,
            'source' => $data['source'] ?? null,
            'performed_by' => $data['performed_by'] ?? null,
            'user_name' => $data['user_name'] ?? null,
            'checkin_id' => $data['checkin_id'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'stage_history' => $data['stage_history'] ?? [],
            'activity_log' => $data['activity_log'] ?? [],
        ]);

        try {
            $this->taskMaterials->consumeForProductionBatch($batch, $request->user()?->id);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('[API][production/batches] material consume failed', [
                'batch_id' => $batch->id,
                'work_order_id' => $batch->work_order_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($this->format($batch->fresh('workflow')), 201);
    }

    public function destroyBatch(Request $request, ProductionBatch $batch): JsonResponse
    {
        if (strcasecmp((string) $request->user()?->role, 'admin') !== 0) {
            return response()->json([
                'message' => 'Only admin can delete production cards',
            ], 403);
        }

        if ($batch->status === 'completed') {
            return response()->json([
                'message' => 'Completed production batches cannot be deleted',
            ], 422);
        }

        ProductionBatch::query()
            ->where('parent_batch_id', $batch->id)
            ->where('status', '!=', 'completed')
            ->delete();

        $batch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Production card removed',
        ]);
    }

    public function advanceStage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => 'required|integer|exists:production_batches,id',
            'direction' => 'required|string|in:forward,backward',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $batch = ProductionBatch::with('workflow')->findOrFail($data['batch_id']);
        $this->assertMachineOperatorCanUpdateStage($request->user(), $batch);
        $this->assertBatchHasGoodUnits($batch);

        if ($data['direction'] === 'forward' && $this->jobWorkFlow->isBatchAtJobWorkStage($batch)) {
            $flowState = $this->jobWorkFlow->flowState($batch);
            if (in_array($flowState, ['ready_to_send', 'sent_out', 'awaiting_checkin'], true)) {
                throw new HttpException(
                    422,
                    'Use Send for Job Work from Product Movement, then check in when returned',
                );
            }
        }

        if ($data['direction'] === 'forward' && isset($data['quantity'])) {
            $moveQty = (int) $data['quantity'];
            $batchQty = max(0, (int) $batch->quantity);

            if ($moveQty > $batchQty) {
                throw new HttpException(422, 'Quantity exceeds available batch quantity');
            }

            if ($moveQty < $batchQty) {
                if ($this->jobWorkFlow->isBatchAtJobWorkStage($batch)) {
                    throw new HttpException(
                        422,
                        'Partial quantity is not supported at Job Work stage — send the full batch',
                    );
                }

                return response()->json([
                    'success' => true,
                    'data' => $this->advancePartialForward($batch, $moveQty, $request->user()),
                ]);
            }
        }

        $stages = $this->resolveStagesForBatch($batch);
        $stageOrder = max(1, (int) $batch->stage_order);

        if ($data['direction'] === 'forward') {
            $stageOrder = min(max(1, (int) $batch->total_stages), $stageOrder + 1);
        } else {
            $stageOrder = max(1, $stageOrder - 1);
        }

        $currentStage = $stages[$stageOrder - 1]['name'] ?? $batch->current_stage;

        $batch->update([
            'stage_order' => $stageOrder,
            'current_stage_index' => $stageOrder - 1,
            'current_stage' => $currentStage,
            'stage_started_at' => now(),
        ]);

        $batch = $batch->fresh('workflow');
        $this->jobWorkFlow->notifyStageReached($batch);

        return response()->json($this->format($batch));
    }

    /**
     * @return array<string, mixed>
     */
    private function advancePartialForward(
        ProductionBatch $batch,
        int $quantity,
        ?User $user,
    ): array {
        $stages = $this->resolveStagesForBatch($batch);
        $stageOrder = max(1, (int) $batch->stage_order);
        $nextStageOrder = min(max(1, (int) $batch->total_stages), $stageOrder + 1);
        $currentStageName = $stages[$stageOrder - 1]['name'] ?? $batch->current_stage;
        $nextStageName = $stages[$nextStageOrder - 1]['name'] ?? $batch->current_stage;
        $operator = $user?->name ?? 'Operator';
        $now = now()->format('Y-m-d H:i');
        $remaining = max(0, (int) $batch->quantity - $quantity);

        $sourceActivity = $batch->activity_log ?? [];
        array_unshift($sourceActivity, [
            'action' => "Sent {$quantity} units to {$nextStageName} (partial)",
            'user' => $operator,
            'time' => $now,
        ]);

        $batch->update([
            'quantity' => $remaining,
            'activity_log' => $sourceActivity,
        ]);

        $splitHistory = $this->buildSplitStageHistory(
            $batch,
            $currentStageName,
            $nextStageName,
            $operator,
            $now,
        );

        $splitActivity = [[
            'action' => "Received {$quantity} units from {$currentStageName} (partial forward)",
            'user' => $operator,
            'time' => $now,
        ]];

        $routeStagesSnapshot = \App\Support\BatchRouteStages::resolveForBatch($batch);

        $splitBatch = ProductionBatch::create([
            'batch_number' => $this->nextSplitBatchNumber($batch),
            'product_name' => $batch->product_name,
            'workflow_id' => $batch->workflow_id,
            'current_stage' => $nextStageName,
            'current_stage_index' => $nextStageOrder - 1,
            'stage_order' => $nextStageOrder,
            'total_stages' => max(count($routeStagesSnapshot), (int) $batch->total_stages),
            'route_stages' => $routeStagesSnapshot !== [] ? $routeStagesSnapshot : null,
            'quantity' => $quantity,
            'status' => $batch->status === 'on_hold' ? 'in_progress' : $batch->status,
            'started_at' => $batch->started_at,
            'stage_started_at' => now(),
            'pr_number' => $batch->pr_number,
            'supplier' => $batch->supplier,
            'vendor_name' => $batch->vendor_name,
            'client_name' => $batch->client_name,
            'unit' => $batch->unit,
            'source' => $batch->source,
            'performed_by' => $operator,
            'user_name' => $operator,
            'checkin_id' => $batch->checkin_id,
            'work_order_id' => $batch->work_order_id,
            'parent_batch_id' => $batch->id,
            'stage_history' => $splitHistory,
            'activity_log' => $splitActivity,
        ]);

        $splitBatch = $splitBatch->fresh('workflow');
        $this->jobWorkFlow->notifyStageReached($splitBatch);

        return [
            'partial' => true,
            'batch' => $this->format($batch->fresh('workflow')),
            'split_batch' => $this->format($splitBatch),
        ];
    }

    private function nextSplitBatchNumber(ProductionBatch $batch): string
    {
        $root = preg_replace('/-P\d+$/', '', $batch->batch_number) ?: $batch->batch_number;
        $count = ProductionBatch::query()
            ->where('batch_number', 'like', $root.'-P%')
            ->count();

        return $root.'-P'.($count + 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSplitStageHistory(
        ProductionBatch $batch,
        string $currentStageName,
        string $nextStageName,
        string $operator,
        string $now,
    ): array {
        $history = array_values($batch->stage_history ?? []);

        if ($history !== []) {
            $lastIndex = count($history) - 1;
            $last = $history[$lastIndex];
            $lastStage = trim((string) ($last['stage'] ?? ''));
            if ($lastStage === '' || $lastStage === $currentStageName) {
                $history[$lastIndex]['completed'] = $now;
                $history[$lastIndex]['duration'] = '—';
                if (empty($history[$lastIndex]['user'])) {
                    $history[$lastIndex]['user'] = $operator;
                }
            }
        } else {
            $history[] = [
                'stage' => $currentStageName,
                'user' => $operator,
                'started' => $batch->stage_started_at?->format('Y-m-d H:i') ?? $now,
                'assigned_at' => $batch->stage_started_at?->format('Y-m-d H:i') ?? $now,
                'completed' => $now,
                'duration' => '—',
            ];
        }

        $history[] = [
            'stage' => $nextStageName,
            'user' => $operator,
            'started' => $now,
            'assigned_at' => $now,
            'completed' => '',
            'duration' => '—',
        ];

        return $history;
    }

    public function sendForJobWork(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => 'required|integer|exists:production_batches,id',
        ]);

        $batch = ProductionBatch::with('workflow')->findOrFail($data['batch_id']);
        $result = $this->jobWorkFlow->sendForJobWork($batch, $request->user());

        return response()->json([
            'batch' => $this->format($result['batch']),
            'checkout' => $this->formatTransaction($result['checkout']),
            'checkin' => $this->formatTransaction($result['checkin']),
        ]);
    }

    public function receiveJobWorkReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => 'required|integer|exists:production_batches,id',
            'checkin_id' => 'required|integer|exists:inventory_transactions,id',
        ]);

        $batch = ProductionBatch::with('workflow')->findOrFail($data['batch_id']);
        $checkin = \App\Models\InventoryTransaction::query()->findOrFail($data['checkin_id']);
        $updated = $this->jobWorkFlow->receiveJobWorkReturn($batch, $checkin, $request->user());

        return response()->json($this->format($updated));
    }

    public function finishLastStage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => 'required|integer|exists:production_batches,id',
        ]);

        $batch = ProductionBatch::with('workflow')->findOrFail($data['batch_id']);
        $this->assertMachineOperatorCanUpdateStage($request->user(), $batch);
        $this->assertBatchHasGoodUnits($batch);

        if ($batch->status === 'completed') {
            throw new HttpException(422, 'Batch is already completed');
        }

        if ($batch->status === 'on_hold') {
            throw new HttpException(422, 'Batch is on hold — resume before finishing the last stage');
        }

        if (! $this->isAtLastStage($batch)) {
            throw new HttpException(422, 'Batch is not at the final stage yet');
        }

        if ($batch->awaiting_final_completion_at !== null) {
            throw new HttpException(422, 'Last stage already finished — waiting for mark complete');
        }

        $batch->update([
            'awaiting_final_completion_at' => now(),
        ]);

        $batch = $batch->fresh('workflow');

        try {
            AppNotificationService::productionAwaitingFinalCompletion(
                $batch,
                $request->user()?->name,
            );
        } catch (\Throwable) {
            // Keep stage completion saved even if push notification fails.
        }

        return response()->json($this->format($batch));
    }

    public function completeBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => 'required|integer|exists:production_batches,id',
        ]);

        $batch = ProductionBatch::with('workflow')->findOrFail($data['batch_id']);
        $this->assertNotMachineOperatorOnly($request->user());
        $this->assertBatchHasGoodUnits($batch);

        if ($batch->status === 'completed') {
            throw new HttpException(422, 'Batch is already completed');
        }

        $batch->update([
            'status' => 'completed',
            'completed_at' => now(),
            'stage_order' => $batch->total_stages,
            'current_stage_index' => max(0, $batch->total_stages - 1),
            'awaiting_final_completion_at' => null,
            'last_completion_reminder_at' => null,
        ]);

        $batch = $batch->fresh('workflow');

        try {
            $this->registerCompletedBatchOutputs($batch);
        } catch (\Throwable) {
            // Batch stays completed even if inventory sync fails.
        }

        try {
            app(\App\Services\ItemAverageTimeService::class)->recalculateForBatch($batch);
        } catch (\Throwable) {
            // Batch stays completed even if average time sync fails.
        }

        try {
            app(\App\Services\ItemMachineTimeService::class)->recalculateAveragesForBatch($batch);
        } catch (\Throwable) {
            // Batch stays completed even if machine average sync fails.
        }

        return response()->json($this->format($batch));
    }

    public function materialRequests(ProductionBatch $batch): JsonResponse
    {
        $requests = $batch->materialRequests()
            ->latest()
            ->get()
            ->map(fn (BatchMaterialRequest $request) => $this->formatMaterialRequest($request, true));

        return response()->json($requests);
    }

    public function indexMaterialRequests(Request $request): JsonResponse
    {
        $query = BatchMaterialRequest::query()
            ->with(['productionBatch.workOrder', 'productionBatch.workflow'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->boolean('mine')) {
            $userId = $request->user()?->id;
            if ($userId !== null) {
                $query->where('requested_by_user_id', $userId);
            }
        }

        $requests = $query->get()->map(
            fn (BatchMaterialRequest $materialRequest) => $this->formatMaterialRequest($materialRequest, true),
        );

        return response()->json($requests);
    }

    public function reviewMaterialRequest(
        Request $request,
        BatchMaterialRequest $materialRequest,
    ): JsonResponse {
        $this->assertNotMachineOperatorOnly($request->user());

        if ($materialRequest->status !== 'pending') {
            throw new HttpException(422, 'This material request was already reviewed');
        }

        $data = $request->validate([
            'action' => 'required|string|in:approve,reject',
            'notes' => 'nullable|string|max:500',
        ]);

        $batch = ProductionBatch::query()->findOrFail($materialRequest->production_batch_id);

        if ($data['action'] === 'approve') {
            $lines = $this->stockAllocation->buildAllocationLines(
                $materialRequest->inventory_item_id,
                $materialRequest->item_name,
                (float) $materialRequest->quantity,
            );

            if ($lines !== []) {
                $this->taskMaterials->attachAndConsume(
                    $batch,
                    $lines,
                    "Material request #{$materialRequest->id}",
                    'Production batch '.$batch->batch_number,
                    $request->user()?->id,
                    $batch->id,
                    $batch->work_order_id,
                );
            }
        }

        $materialRequest->update([
            'status' => $data['action'] === 'approve' ? 'approved' : 'rejected',
            'review_notes' => $data['notes'] ?? null,
            'reviewed_by_user_id' => $request->user()?->id,
            'reviewed_by_name' => $request->user()?->name,
            'reviewed_at' => now(),
        ]);

        $activityLog = $batch->activity_log ?? [];
        if (! is_array($activityLog)) {
            $activityLog = [];
        }
        $reviewer = $request->user()?->name ?? 'Supervisor';
        $actionLabel = $data['action'] === 'approve' ? 'approved' : 'rejected';
        $activityLog[] = [
            'action' => 'material_request_'.$actionLabel,
            'message' => "{$reviewer} {$actionLabel} request for {$materialRequest->quantity} "
                ."{$materialRequest->unit} of {$materialRequest->item_name}",
            'at' => now()->toIso8601String(),
        ];
        $batch->update(['activity_log' => $activityLog]);

        $materialRequest = $materialRequest->fresh('productionBatch');

        try {
            AppNotificationService::batchMaterialRequestReviewed(
                $batch,
                $materialRequest,
                $data['action'],
                $request->user()?->name,
            );
        } catch (\Throwable) {
            // Keep review saved even if notification fails.
        }

        return response()->json($this->formatMaterialRequest($materialRequest, true));
    }

    public function storeMaterialRequest(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanRequestBatchMaterial($request->user(), $batch);

        $data = $request->validate([
            'inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:50',
            'request_type' => 'nullable|string|in:raw_material,tool',
            'notes' => 'nullable|string|max:500',
        ]);

        $inventoryItemId = $data['inventory_item_id'] ?? null;
        $itemName = trim($data['item_name']);
        $unit = $data['unit'] ?? 'pcs';
        $requestType = $data['request_type'] ?? 'raw_material';

        if ($inventoryItemId !== null) {
            $inventoryItem = InventoryItem::query()->find($inventoryItemId);
            if ($inventoryItem !== null) {
                $itemName = $inventoryItem->name;
                $unit = $data['unit'] ?? $inventoryItem->unit ?? 'pcs';
            }
        }

        $materialRequest = BatchMaterialRequest::create([
            'production_batch_id' => $batch->id,
            'inventory_item_id' => $inventoryItemId,
            'item_name' => $itemName,
            'quantity' => $data['quantity'],
            'unit' => $unit,
            'request_type' => $requestType,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'requested_by_user_id' => $request->user()?->id,
            'requested_by_name' => $request->user()?->name,
        ]);

        $activityLog = $batch->activity_log ?? [];
        if (! is_array($activityLog)) {
            $activityLog = [];
        }
        $operator = $request->user()?->name ?? 'Operator';
        $activityLog[] = [
            'action' => 'material_requested',
            'message' => "{$operator} requested {$data['quantity']} {$unit} of {$itemName}",
            'at' => now()->toIso8601String(),
        ];
        $batch->update(['activity_log' => $activityLog]);

        try {
            AppNotificationService::batchMaterialRequested(
                $batch->fresh('workflow'),
                $materialRequest,
                $request->user()?->name,
            );
        } catch (\Throwable) {
            // Keep request saved even if notification fails.
        }

        return response()->json(
            $this->formatMaterialRequest($materialRequest, true),
            201,
        );
    }

    public function storeBatchRejection(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanRequestBatchMaterial($request->user(), $batch);

        if ($batch->status === 'completed') {
            throw new HttpException(422, 'Batch is already completed');
        }

        $data = $request->validate([
            'item_code' => 'required|string|max:255',
            'reason' => 'required|string|max:255',
            'quantity' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:500',
            'stage_label' => 'nullable|string|max:255',
            'rework_possible' => 'nullable|boolean',
            'rework_assigned_to' => 'nullable|string|max:255',
            'rework_assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $itemCode = trim($data['item_code']);
        $operator = $request->user()?->name ?? 'Operator';
        $assigneeName = filled($data['rework_assigned_to'] ?? null)
            ? trim($data['rework_assigned_to'])
            : null;
        $batchQty = max(0, (int) $batch->quantity);

        if ($batchQty < 1) {
            throw new HttpException(422, 'No good units remaining in this batch');
        }

        if ($quantity > $batchQty) {
            throw new HttpException(
                422,
                "Cannot reject {$quantity} unit(s). Only {$batchQty} good unit(s) remaining in this batch.",
            );
        }

        $remainingQty = $batchQty - $quantity;
        $stageLabel = filled($data['stage_label'] ?? null)
            ? trim($data['stage_label'])
            : $this->resolveBatchStageDisplayLabel($batch);

        $rejection = Rejection::create([
            'production_batch_id' => $batch->id,
            'work_order_id' => $batch->work_order_id,
            'batch_number' => $batch->batch_number,
            'product_name' => $batch->product_name,
            'item_code' => $itemCode,
            'stage' => $stageLabel,
            'stage_order' => max(1, (int) $batch->stage_order),
            'quantity' => $quantity,
            'reason' => trim($data['reason']),
            'notes' => $data['notes'] ?? null,
            'rework_possible' => $data['rework_possible'] ?? true,
            'user_id' => $request->user()?->id,
            'reported_by' => $operator,
            'rework_assigned_to' => $assigneeName,
            'rework_assigned_to_user_id' => $data['rework_assigned_to_user_id'] ?? null,
            'rework_status' => $assigneeName !== null ? 'in_rework' : 'pending',
            'total_produced' => $batchQty,
            'rejection_date' => now(),
        ]);

        $activityLog = $batch->activity_log ?? [];
        if (! is_array($activityLog)) {
            $activityLog = [];
        }
        $activityLog[] = [
            'action' => "Rejected at {$stageLabel}",
            'user' => $operator,
            'time' => now()->format('Y-m-d H:i'),
            'message' => "{$quantity} unit(s) of {$itemCode} — {$data['reason']}. "
                ."{$remainingQty} good unit(s) remain in workflow.",
            'stage' => $stageLabel,
            'type' => 'rejection',
            'quantity' => $quantity,
            'item_code' => $itemCode,
        ];

        $batchUpdates = [
            'quantity' => $remainingQty,
            'activity_log' => $activityLog,
        ];

        if ($remainingQty < 1) {
            $batchUpdates['status'] = 'completed';
            $batchUpdates['completed_at'] = now();
            $batchUpdates['awaiting_final_completion_at'] = null;
            $activityLog[] = [
                'action' => 'batch_closed',
                'message' => 'All units rejected — batch closed without final QC',
                'at' => now()->toIso8601String(),
            ];
            $batchUpdates['activity_log'] = $activityLog;
        }

        $batch->update($batchUpdates);

        try {
            AppNotificationService::batchItemRejected(
                $batch->fresh('workflow'),
                $rejection,
                $operator,
            );
            if (filled($rejection->rework_assigned_to)) {
                AppNotificationService::reworkAssigned($rejection, $operator);
            }
        } catch (\Throwable) {
            // Keep rejection saved even if notification fails.
        }

        return response()->json([
            'id' => $rejection->id,
            'production_batch_id' => $rejection->production_batch_id,
            'work_order_id' => $rejection->work_order_id,
            'batch_number' => $rejection->batch_number,
            'product_name' => $rejection->product_name,
            'item_code' => $rejection->item_code,
            'stage' => $rejection->stage,
            'quantity' => $rejection->quantity,
            'reason' => $rejection->reason,
            'rework_status' => $rejection->rework_status,
            'rework_possible' => $rejection->rework_possible,
            'reported_by' => $rejection->reported_by,
            'created_at' => $rejection->created_at?->format('Y-m-d H:i'),
            'remaining_batch_quantity' => $remainingQty,
            'batch' => $this->format($batch->fresh('workflow')),
        ], 201);
    }

    private function registerCompletedBatchOutputs(ProductionBatch $batch): void
    {
        app(\App\Services\QualityInspectionService::class)->sendBatchToQuality($batch);
    }

    public function updateStatus(Request $request, ProductionBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:in_progress,on_hold',
            'hold_reason' => 'nullable|string|max:255',
            'activity_log' => 'nullable|array',
        ]);

        $this->assertNotMachineOperatorOnly($request->user());

        $batch->update([
            'status' => $data['status'],
            'hold_reason' => $data['status'] === 'on_hold' ? ($data['hold_reason'] ?? null) : null,
            'activity_log' => $data['activity_log'] ?? $batch->activity_log,
        ]);

        return response()->json($this->format($batch->fresh('workflow')));
    }

    public function updatePriority(Request $request, ProductionBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'priority' => 'required|string|in:normal,urgent',
        ]);

        $this->assertNotMachineOperatorOnly($request->user());

        $batch->update(['priority' => $data['priority']]);

        return response()->json($this->format($batch->fresh('workflow')));
    }

    private function assertBatchHasGoodUnits(ProductionBatch $batch): void
    {
        if ((int) $batch->quantity < 1) {
            throw new HttpException(
                422,
                'No good units remaining in this batch — rejected items do not continue in the workflow',
            );
        }
    }

    private function assertNotMachineOperatorOnly(?User $user): void
    {
        if ($this->isMachineOperator($user)) {
            throw new HttpException(
                403,
                'Machine operators can only update their assigned machine stage',
            );
        }
    }

    private function assertCanRequestBatchMaterial(?User $user, ProductionBatch $batch): void
    {
        if ($batch->status === 'completed') {
            throw new HttpException(422, 'Batch is already completed');
        }

        if ($user === null) {
            throw new HttpException(401, 'Unauthenticated');
        }

        $permissions = app(PermissionService::class);
        if ($permissions->userCan($user, 'workflow.view')) {
            return;
        }

        if ($this->isMachineOperator($user)) {
            $this->assertMachineOperatorCanUpdateStage($user, $batch);
        }

        throw new HttpException(
            403,
            'You do not have Route Card access to request material',
        );
    }

    private function assertMachineOperatorCanUpdateStage(?User $user, ProductionBatch $batch): void
    {
        if (! $this->isMachineOperator($user)) {
            return;
        }

        if ($user?->machinery_id === null) {
            throw new HttpException(
                403,
                'No machine assigned to your account — contact admin',
            );
        }

        $stages = $this->resolveStagesForBatch($batch);
        $stageOrder = max(1, (int) $batch->stage_order);
        $currentStage = $stages[$stageOrder - 1] ?? null;

        if ($currentStage === null) {
            throw new HttpException(
                403,
                'Stage information is missing for this batch',
            );
        }

        if (! $this->stageMatchesMachinery($currentStage, (int) $user->machinery_id)) {
            throw new HttpException(
                403,
                'You can only update production for your assigned machine',
            );
        }
    }

    private function isAtLastStage(ProductionBatch $batch): bool
    {
        $stages = $this->resolveStagesForBatch($batch);
        $stageOrder = max(1, (int) $batch->stage_order);
        $totalStages = max(count($stages), (int) $batch->total_stages, 1);

        return $stageOrder >= $totalStages;
    }

    private function isMachineOperator(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (in_array($user->designation, ['machine_operator', 'production_operator'], true)) {
            return true;
        }

        return $user->role === 'operator' && $user->machinery_id !== null;
    }

    private function stageMatchesMachinery(array $stage, int $machineryId): bool
    {
        if (isset($stage['machine_id']) && (int) $stage['machine_id'] === $machineryId) {
            return true;
        }

        $machinery = \App\Models\Machinery::query()->find($machineryId);
        if ($machinery === null) {
            return false;
        }

        $machineCode = strtolower(trim((string) ($machinery->machine_code ?? '')));
        $machineName = strtolower(trim((string) ($machinery->name ?? '')));
        $machineDefaultStage = strtolower(trim((string) ($machinery->default_stage ?? '')));
        $stageCode = strtolower(trim((string) ($stage['machine_code'] ?? '')));
        $stageMachineName = strtolower(trim((string) ($stage['machine_name'] ?? '')));
        $stageName = strtolower(trim((string) ($stage['name'] ?? '')));

        if ($machineCode !== '' && $stageCode !== '' && $machineCode === $stageCode) {
            return true;
        }

        if ($machineName !== '' && $stageMachineName !== '' && $machineName === $stageMachineName) {
            return true;
        }

        if ($stageName !== '') {
            if ($machineDefaultStage !== '' && $stageName === $machineDefaultStage) {
                return true;
            }

            if ($machineName !== '' && str_contains($machineName, $stageName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Machine / vendor label shown in tracking — e.g. LATHE-1A, CNC Mill.
     */
    private function resolveBatchStageDisplayLabel(ProductionBatch $batch): string
    {
        $stages = $this->resolveStagesForBatch($batch);
        $order = max(1, (int) $batch->stage_order);
        $stage = $stages[$order - 1] ?? null;

        if (! is_array($stage)) {
            return trim((string) ($batch->current_stage ?? 'Unknown stage'));
        }

        return $this->resolveStageDisplayLabel($stage);
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private function resolveStageDisplayLabel(array $stage): string
    {
        if (! empty($stage['machine_id'])) {
            $machine = \App\Models\Machinery::query()->find((int) $stage['machine_id']);
            if ($machine !== null) {
                $name = trim((string) $machine->name);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $machineName = trim((string) ($stage['machine_name'] ?? ''));
        if ($machineName !== '') {
            return $machineName;
        }

        $machineCode = trim((string) ($stage['machine_code'] ?? ''));
        if ($machineCode !== '') {
            $machine = \App\Models\Machinery::query()
                ->where('machine_code', $machineCode)
                ->first();
            if ($machine !== null) {
                $name = trim((string) $machine->name);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $vendorName = trim((string) ($stage['vendor_name'] ?? ''));
        if ($vendorName !== '') {
            return $vendorName;
        }

        $processName = trim((string) ($stage['name'] ?? ''));
        if ($processName !== '') {
            return $processName;
        }

        return 'Unknown stage';
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     */
    private function resolveNextMachineNameForBatch(ProductionBatch $batch, array $stages): ?string
    {
        if ($batch->status === 'completed') {
            return null;
        }

        $stageOrder = max(1, (int) $batch->stage_order);
        if ($stages === [] || $stageOrder >= count($stages)) {
            return null;
        }

        $next = $stages[$stageOrder] ?? null;
        if (! is_array($next)) {
            return null;
        }

        $label = trim($this->resolveStageDisplayLabel($next));

        return $label !== '' && $label !== 'Unknown stage' ? $label : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveStagesForBatch(ProductionBatch $batch): array
    {
        $stages = \App\Support\BatchRouteStages::resolveForBatch($batch);

        if ($stages === []) {
            return [];
        }

        return $this->enrichStagesWithMachinery($this->sortStages($stages));
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function enrichStagesWithMachinery(array $stages): array
    {
        $machinery = \App\Models\Machinery::query()->get();

        return array_map(function (array $stage) use ($machinery) {
            if (! empty($stage['machine_id'])) {
                if (empty($stage['machine_name']) || empty($stage['machine_code'])) {
                    $machine = $machinery->firstWhere('id', (int) $stage['machine_id']);
                    if ($machine !== null) {
                        return $this->attachMachineToStage($stage, $machine);
                    }
                }

                return $stage;
            }

            $stageCode = strtolower(trim((string) ($stage['machine_code'] ?? '')));
            $stageMachineName = strtolower(trim((string) ($stage['machine_name'] ?? '')));
            $stageName = strtolower(trim((string) ($stage['name'] ?? '')));

            foreach ($machinery as $machine) {
                $machineId = (int) $machine->id;
                $machineCode = strtolower(trim((string) ($machine->machine_code ?? '')));
                $machineName = strtolower(trim((string) ($machine->name ?? '')));
                $defaultStage = strtolower(trim((string) ($machine->default_stage ?? '')));

                if ($stageCode !== '' && $machineCode !== '' && $stageCode === $machineCode) {
                    return $this->attachMachineToStage($stage, $machine);
                }

                if ($stageMachineName !== '' && $machineName !== '' && $stageMachineName === $machineName) {
                    return $this->attachMachineToStage($stage, $machine);
                }

                if ($stageName !== '') {
                    if ($defaultStage !== '' && $stageName === $defaultStage) {
                        return $this->attachMachineToStage($stage, $machine);
                    }

                    if ($machineName !== '' && str_contains($machineName, $stageName)) {
                        return $this->attachMachineToStage($stage, $machine);
                    }
                }
            }

            return $stage;
        }, $stages);
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return array<string, mixed>
     */
    private function attachMachineToStage(array $stage, \App\Models\Machinery $machine): array
    {
        $stage['machine_id'] = $machine->id;
        $stage['machine_name'] = $machine->name;
        $stage['machine_code'] = $machine->machine_code;

        return $stage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function sortStages(array $stages): array
    {
        $stages = array_values(array_filter($stages, 'is_array'));
        usort($stages, fn (array $left, array $right) => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)));

        return array_values($stages);
    }

    private function format(ProductionBatch $batch): array
    {
        $isJobWorkStage = $this->detectJobWorkStage($batch);
        try {
            $jobWorkFlowState = $this->jobWorkFlow->displayFlowState($batch, $isJobWorkStage);
        } catch (\Throwable) {
            $jobWorkFlowState = $isJobWorkStage ? 'ready_to_send' : 'not_job_work';
        }

        try {
            $stages = $this->resolveStagesForBatch($batch);
        } catch (\Throwable) {
            $stages = [];
        }

        try {
            $isLastStage = $this->isAtLastStage($batch);
        } catch (\Throwable) {
            $stageOrder = max(1, (int) ($batch->stage_order ?? 1));
            $totalStages = max(1, (int) ($batch->total_stages ?? 1));
            $isLastStage = $stageOrder >= $totalStages;
        }

        $totalStagesResolved = max(
            count($stages),
            max(1, (int) ($batch->total_stages ?? 1)),
        );
        $nextMachineName = $this->resolveNextMachineNameForBatch($batch, $stages);

        $parentBatchNumber = null;
        if (
            Schema::hasColumn('production_batches', 'parent_batch_id')
            && $batch->parent_batch_id
        ) {
            $parentBatchNumber = ProductionBatch::query()
                ->where('id', $batch->parent_batch_id)
                ->value('batch_number');
        }

        $workOrder = $batch->relationLoaded('workOrder')
            ? $batch->workOrder
            : ($batch->work_order_id
                ? WorkOrder::query()->find($batch->work_order_id)
                : null);

        $workflowName = $batch->workflow?->name;
        if (! filled($workflowName) && $workOrder !== null) {
            $workflowName = $workOrder->route_card_no
                ?: $workOrder->workflow_name
                ?: $workOrder->product;
        }

        $materialSpec = null;
        try {
            $materialSpec = $this->materialSpec->forBatch($batch);
        } catch (\Throwable $e) {
            report($e);
        }

        $resolvedItemCode = DisplayValue::normalize($workOrder?->item_code)
            ?? DisplayValue::normalize($workOrder?->product)
            ?? DisplayValue::normalize($workOrder?->workflow_name);
        $displayProduct = DisplayValue::normalize($batch->product_name) ?? $resolvedItemCode;

        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'product' => $displayProduct,
            'product_name' => $displayProduct,
            'quantity' => $batch->quantity,
            'workflow_id' => $batch->workflow_id,
            'workflow' => $workflowName,
            'current_stage' => $batch->current_stage,
            'stage_order' => $batch->stage_order,
            'total_stages' => $totalStagesResolved,
            'next_machine_name' => $nextMachineName,
            'status' => $batch->status,
            'priority' => $batch->priority ?? 'normal',
            'started_at' => $this->safeDateTime($batch->started_at),
            'stage_started_at' => $this->safeDateTime($batch->stage_started_at),
            'completed_at' => $this->safeDateTime($batch->completed_at),
            'awaiting_final_completion_at' => $this->safeDateTime($batch->awaiting_final_completion_at),
            'awaiting_final_completion' => $batch->awaiting_final_completion_at !== null,
            'is_last_stage' => $isLastStage,
            'hold_reason' => $batch->hold_reason,
            'pr_number' => $batch->pr_number,
            'supplier' => $batch->supplier,
            'vendor_name' => $batch->vendor_name,
            'client_name' => $batch->client_name,
            'unit' => $batch->unit,
            'source' => $batch->source,
            'performed_by' => $batch->performed_by,
            'user_name' => $batch->user_name,
            'checkin_id' => $batch->checkin_id,
            'work_order_id' => $batch->work_order_id,
            'route_card_no' => $workOrder?->route_card_no,
            'item_code' => $resolvedItemCode,
            'item_name' => $resolvedItemCode
                ?? DisplayValue::normalize($workOrder?->item_name),
            'parent_batch_id' => Schema::hasColumn('production_batches', 'parent_batch_id')
                ? $batch->parent_batch_id
                : null,
            'parent_batch_number' => $parentBatchNumber !== null
                ? (string) $parentBatchNumber
                : null,
            'stage_history' => $this->safeJsonArray($batch->stage_history),
            'activity_log' => $this->safeJsonArray($batch->activity_log),
            'rejections' => $this->formatBatchRejections($batch),
            'material_spec' => $materialSpec,
            'job_work_flow_state' => $jobWorkFlowState,
            'is_job_work_stage' => $isJobWorkStage,
            'stages' => $stages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatBatchFallback(int $batchId): ?array
    {
        $row = DB::table('production_batches')->where('id', $batchId)->first();
        if ($row === null) {
            return null;
        }

        $workflowName = null;
        $routeCardNo = null;
        $itemCode = null;
        $itemName = null;
        if (! empty($row->workflow_id)) {
            $workflowName = DB::table('workflows')->where('id', $row->workflow_id)->value('name');
        }
        if (! empty($row->work_order_id)) {
            $workOrder = DB::table('work_orders')->where('id', $row->work_order_id)->first();
            if ($workOrder !== null) {
                $routeCardNo = $workOrder->route_card_no ?? null;
                $itemCode = $workOrder->item_code ?? null;
                $itemName = $workOrder->item_name ?? $workOrder->product ?? null;
                if (! filled($workflowName)) {
                    $workflowName = $routeCardNo
                        ?: ($workOrder->workflow_name ?? null)
                        ?: ($workOrder->product ?? null);
                }
            }
        }

        $stageOrder = (int) ($row->stage_order ?? 1);
        $totalStages = max(1, (int) ($row->total_stages ?? 1));
        [$jobWorkFlowState, $isJobWorkStage] = $this->jobWorkFlagsForFallback($batchId, $row);

        return [
            'id' => (int) $row->id,
            'batch_number' => (string) ($row->batch_number ?? ''),
            'product' => (string) ($row->product_name ?? ''),
            'product_name' => (string) ($row->product_name ?? ''),
            'quantity' => (int) ($row->quantity ?? 0),
            'workflow_id' => $row->workflow_id,
            'workflow' => $workflowName,
            'current_stage' => $row->current_stage,
            'stage_order' => $stageOrder,
            'total_stages' => $totalStages,
            'status' => (string) ($row->status ?? 'in_progress'),
            'priority' => (string) ($row->priority ?? 'normal'),
            'started_at' => $this->safeDateTime($row->started_at ?? null),
            'stage_started_at' => $this->safeDateTime($row->stage_started_at ?? null),
            'completed_at' => $this->safeDateTime($row->completed_at ?? null),
            'awaiting_final_completion_at' => $this->safeDateTime($row->awaiting_final_completion_at ?? null),
            'awaiting_final_completion' => ! empty($row->awaiting_final_completion_at),
            'is_last_stage' => $stageOrder >= $totalStages,
            'hold_reason' => $row->hold_reason ?? null,
            'pr_number' => $row->pr_number ?? null,
            'supplier' => $row->supplier ?? null,
            'vendor_name' => $row->vendor_name ?? null,
            'client_name' => $row->client_name ?? null,
            'unit' => $row->unit ?? null,
            'source' => $row->source ?? null,
            'performed_by' => $row->performed_by ?? null,
            'user_name' => $row->user_name ?? null,
            'checkin_id' => $row->checkin_id ?? null,
            'work_order_id' => $row->work_order_id ?? null,
            'route_card_no' => $routeCardNo,
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'parent_batch_id' => property_exists($row, 'parent_batch_id') ? $row->parent_batch_id : null,
            'stage_history' => $this->safeJsonArray($row->stage_history ?? null),
            'activity_log' => $this->safeJsonArray($row->activity_log ?? null),
            'rejections' => ($batchModel = ProductionBatch::query()->find((int) $row->id))
                ? $this->formatBatchRejections($batchModel)
                : [],
            'job_work_flow_state' => $jobWorkFlowState,
            'is_job_work_stage' => $isJobWorkStage,
            'stages' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatBatchRejections(ProductionBatch $batch): array
    {
        $stages = $this->resolveStagesForBatch($batch);

        return Rejection::query()
            ->where('production_batch_id', $batch->id)
            ->latest()
            ->get()
            ->map(fn (Rejection $rejection) => [
                'id' => $rejection->id,
                'item_code' => $rejection->item_code,
                'stage' => $this->resolveRejectionDisplayStage($batch, $rejection, $stages),
                'stage_order' => $rejection->stage_order,
                'quantity' => $rejection->quantity,
                'reason' => $rejection->reason,
                'rework_status' => $rejection->rework_status,
                'rework_assigned_to' => $rejection->rework_assigned_to,
                'reported_by' => $rejection->reported_by,
                'rejection_date' => $rejection->rejection_date?->format('Y-m-d'),
                'created_at' => $rejection->created_at?->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     */
    private function resolveRejectionDisplayStage(
        ProductionBatch $batch,
        Rejection $rejection,
        array $stages,
    ): string {
        $order = (int) ($rejection->stage_order ?? 0);
        if ($order > 0 && isset($stages[$order - 1])) {
            return $this->resolveStageDisplayLabel($stages[$order - 1]);
        }

        $stored = trim((string) ($rejection->stage ?? ''));
        if ($stored !== '') {
            foreach ($stages as $stage) {
                if (strcasecmp($this->resolveStageDisplayLabel($stage), $stored) === 0) {
                    return $stored;
                }
            }
        }

        $inferredOrder = $this->inferStageOrderAtTime(
            $batch,
            $rejection->created_at,
            $stages,
        );
        if ($inferredOrder !== null && isset($stages[$inferredOrder - 1])) {
            return $this->resolveStageDisplayLabel($stages[$inferredOrder - 1]);
        }

        if ($stored !== '') {
            $storedLower = strtolower($stored);
            $matches = array_values(array_filter(
                $stages,
                fn (array $stage) => strtolower(trim((string) ($stage['name'] ?? ''))) === $storedLower,
            ));

            if (count($matches) === 1) {
                return $this->resolveStageDisplayLabel($matches[0]);
            }

            if (count($matches) > 1) {
                $currentOrder = max(1, (int) $batch->stage_order);
                if (isset($stages[$currentOrder - 1])) {
                    $currentProcess = strtolower(trim((string) ($stages[$currentOrder - 1]['name'] ?? '')));
                    if ($currentProcess === $storedLower) {
                        return $this->resolveStageDisplayLabel($stages[$currentOrder - 1]);
                    }
                }
            }
        }

        return $stored !== '' ? $stored : $this->resolveBatchStageDisplayLabel($batch);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     */
    private function inferStageOrderAtTime(
        ProductionBatch $batch,
        ?\DateTimeInterface $at,
        array $stages,
    ): ?int {
        if ($at === null) {
            return null;
        }

        $history = $batch->stage_history ?? [];
        if (! is_array($history) || $history === []) {
            return null;
        }

        $rejectedAt = \Carbon\Carbon::parse($at);
        $activeProcess = null;
        $targetOccurrence = 0;

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $processName = trim((string) ($entry['stage'] ?? ''));
            if ($processName === '') {
                continue;
            }

            $started = $this->parseActivityTime($entry['started'] ?? $entry['started_at'] ?? null);
            if ($started === null || $rejectedAt->lt($started)) {
                continue;
            }

            $completedRaw = $entry['completed'] ?? $entry['completed_at'] ?? null;
            $completed = $this->parseActivityTime($completedRaw);
            $isOpen = $completedRaw === null
                || (is_string($completedRaw) && trim($completedRaw) === '')
                || ($completed !== null && $rejectedAt->lte($completed));

            if (! $isOpen) {
                continue;
            }

            $activeProcess = $processName;
            $targetOccurrence = 0;
            foreach ($history as $prior) {
                if (! is_array($prior)) {
                    continue;
                }
                if (strcasecmp(trim((string) ($prior['stage'] ?? '')), $activeProcess) === 0) {
                    $targetOccurrence++;
                }
                if ($prior === $entry) {
                    break;
                }
            }
            break;
        }

        if ($activeProcess === null || $targetOccurrence < 1) {
            return null;
        }

        $seen = 0;
        foreach ($stages as $index => $stage) {
            if (strcasecmp(trim((string) ($stage['name'] ?? '')), $activeProcess) !== 0) {
                continue;
            }
            $seen++;
            if ($seen === $targetOccurrence) {
                return $index + 1;
            }
        }

        return null;
    }

    private function parseActivityTime(mixed $value): ?\Carbon\Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \Carbon\Carbon::parse($value);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectJobWorkStage(ProductionBatch $batch): bool
    {
        try {
            if ($this->jobWorkFlow->isBatchAtJobWorkStage($batch)) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the stage-name check.
        }

        $currentName = strtolower(trim((string) ($batch->current_stage ?? '')));

        return $currentName === 'job work' || str_contains($currentName, 'job work');
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function jobWorkFlagsForFallback(int $batchId, object $row): array
    {
        try {
            $model = ProductionBatch::query()->find($batchId);
            if ($model !== null) {
                $isJobWorkStage = $this->detectJobWorkStage($model);

                return [
                    $this->jobWorkFlow->displayFlowState($model, $isJobWorkStage),
                    $isJobWorkStage,
                ];
            }
        } catch (\Throwable) {
            // Use current_stage from the raw row.
        }

        $currentName = strtolower(trim((string) ($row->current_stage ?? '')));
        $isJobWorkStage = $currentName === 'job work' || str_contains($currentName, 'job work');

        return [$isJobWorkStage ? 'ready_to_send' : 'not_job_work', $isJobWorkStage];
    }

    private function safeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i');
            }

            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return is_string($value) && $value !== '' ? $value : null;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function safeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function formatMaterialRequest(BatchMaterialRequest $request, bool $includeBatch = false): array
    {
        $payload = [
            'id' => $request->id,
            'production_batch_id' => $request->production_batch_id,
            'inventory_item_id' => $request->inventory_item_id,
            'item_name' => $request->item_name,
            'quantity' => $request->quantity,
            'unit' => $request->unit,
            'request_type' => $request->request_type,
            'status' => $request->status,
            'notes' => $request->notes,
            'review_notes' => $request->review_notes,
            'requested_by_user_id' => $request->requested_by_user_id,
            'requested_by_name' => $request->requested_by_name,
            'reviewed_by_user_id' => $request->reviewed_by_user_id,
            'reviewed_by_name' => $request->reviewed_by_name,
            'reviewed_at' => $request->reviewed_at?->format('Y-m-d H:i'),
            'created_at' => $request->created_at?->format('Y-m-d H:i'),
        ];

        if ($includeBatch) {
            $batch = $request->relationLoaded('productionBatch')
                ? $request->productionBatch
                : ProductionBatch::query()->with(['workOrder', 'workflow'])->find($request->production_batch_id);

            $payload['batch_number'] = $batch?->batch_number;
            $payload['product_name'] = $batch?->product_name;
            $payload['current_stage'] = $batch?->current_stage;

            $workOrder = $batch?->relationLoaded('workOrder')
                ? $batch->workOrder
                : ($batch?->work_order_id
                    ? WorkOrder::query()->find($batch->work_order_id)
                    : null);
            $payload['route_card_no'] = $workOrder?->route_card_no;

            if ($batch !== null) {
                $stageService = app(BatchStageMachineService::class);
                $stage = $stageService->currentStageForBatch($batch);
                $machineryId = $stage !== null ? $stageService->machineIdForStage($stage) : null;
                if ($machineryId !== null && $machineryId > 0) {
                    $machine = Machinery::query()->find($machineryId);
                    $payload['machinery_id'] = $machineryId;
                    $payload['machine_code'] = $machine?->machine_code;
                    $payload['machine_name'] = $machine?->name;
                }
            }
        }

        return $payload;
    }

    private function formatTransaction(\App\Models\InventoryTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'material_name' => $transaction->material_name,
            'supplier' => $transaction->supplier,
            'destination' => $transaction->destination,
            'quantity' => $transaction->quantity,
            'unit' => $transaction->unit,
            'batch_number' => $transaction->batch_number,
            'notes' => $transaction->notes,
            'status' => $transaction->status,
            'receipt_phase' => $transaction->receipt_phase,
            'flow_source' => $transaction->flow_source,
            'production_batch_id' => $transaction->production_batch_id,
            'job_work_id' => $transaction->job_work_id,
            'work_order_id' => $transaction->work_order_id,
            'datetime' => $transaction->created_at?->format('Y-m-d H:i'),
        ];
    }
}
