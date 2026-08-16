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
use App\Services\AppNotificationService;
use App\Services\JobWorkFlowService;
use App\Services\TaskMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductionController extends Controller
{
    public function __construct(
        private JobWorkFlowService $jobWorkFlow,
        private TaskMaterialService $taskMaterials,
    ) {}
    public function batches(): JsonResponse
    {
        return response()->json(
            ProductionBatch::with('workflow')
                ->latest()
                ->get()
                ->map(fn (ProductionBatch $batch) => $this->format($batch)),
        );
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

        $this->taskMaterials->consumeForProductionBatch($batch, $request->user()?->id);

        return response()->json($this->format($batch->fresh('workflow')), 201);
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
            $batchQty = max(1, (int) $batch->quantity);

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

                return response()->json(
                    $this->advancePartialForward($batch, $moveQty, $request->user()),
                );
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

        $splitBatch = ProductionBatch::create([
            'batch_number' => $this->nextSplitBatchNumber($batch),
            'product_name' => $batch->product_name,
            'workflow_id' => $batch->workflow_id,
            'current_stage' => $nextStageName,
            'current_stage_index' => $nextStageOrder - 1,
            'stage_order' => $nextStageOrder,
            'total_stages' => $batch->total_stages,
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
            ->with('productionBatch')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
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

        if ($data['action'] === 'approve' && $materialRequest->inventory_item_id !== null) {
            $qty = (int) ceil((float) $materialRequest->quantity);
            if ($qty < 1) {
                $qty = 1;
            }

            $this->taskMaterials->attachAndConsume(
                $batch,
                [
                    [
                        'inventory_item_id' => (int) $materialRequest->inventory_item_id,
                        'quantity' => $qty,
                    ],
                ],
                "Material request #{$materialRequest->id}",
                'Production batch '.$batch->batch_number,
                $request->user()?->id,
                $batch->id,
                $batch->work_order_id,
            );
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
            'rework_possible' => 'nullable|boolean',
        ]);

        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $itemCode = trim($data['item_code']);
        $operator = $request->user()?->name ?? 'Operator';

        $rejection = Rejection::create([
            'production_batch_id' => $batch->id,
            'work_order_id' => $batch->work_order_id,
            'batch_number' => $batch->batch_number,
            'product_name' => $batch->product_name,
            'item_code' => $itemCode,
            'stage' => $batch->current_stage,
            'quantity' => $quantity,
            'reason' => trim($data['reason']),
            'notes' => $data['notes'] ?? null,
            'rework_status' => 'pending',
            'rework_possible' => $data['rework_possible'] ?? true,
            'user_id' => $request->user()?->id,
            'reported_by' => $operator,
            'rework_assigned_to' => $operator,
            'total_produced' => max(1, (int) $batch->quantity),
            'rejection_date' => now(),
        ]);

        $activityLog = $batch->activity_log ?? [];
        if (! is_array($activityLog)) {
            $activityLog = [];
        }
        $activityLog[] = [
            'action' => 'item_rejected',
            'message' => "{$operator} rejected {$quantity} unit(s) of {$itemCode} — {$data['reason']}",
            'at' => now()->toIso8601String(),
        ];
        $batch->update(['activity_log' => $activityLog]);

        try {
            AppNotificationService::batchItemRejected(
                $batch->fresh('workflow'),
                $rejection,
                $operator,
            );
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

        if ($this->isMachineOperator($user)) {
            $this->assertMachineOperatorCanUpdateStage($user, $batch);
        }
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
     * @return array<int, array<string, mixed>>
     */
    private function resolveStagesForBatch(ProductionBatch $batch): array
    {
        $stages = [];

        if ($batch->work_order_id) {
            $order = WorkOrder::query()->find($batch->work_order_id);
            $orderStages = $order?->stages ?? [];
            if (is_array($orderStages) && $orderStages !== []) {
                $stages = $orderStages;
            }
        }

        if ($stages === []) {
            $workflowStages = $batch->workflow?->stages ?? [];
            if (is_array($workflowStages) && $workflowStages !== []) {
                $stages = $workflowStages;
            }
        }

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
        usort($stages, fn (array $left, array $right) => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)));

        return array_values($stages);
    }

    private function format(ProductionBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'product' => $batch->product_name,
            'product_name' => $batch->product_name,
            'quantity' => $batch->quantity,
            'workflow_id' => $batch->workflow_id,
            'workflow' => $batch->workflow?->name,
            'current_stage' => $batch->current_stage,
            'stage_order' => $batch->stage_order,
            'total_stages' => $batch->total_stages,
            'status' => $batch->status,
            'started_at' => $batch->started_at?->format('Y-m-d H:i'),
            'stage_started_at' => $batch->stage_started_at?->format('Y-m-d H:i'),
            'completed_at' => $batch->completed_at?->format('Y-m-d H:i'),
            'awaiting_final_completion_at' => $batch->awaiting_final_completion_at?->format('Y-m-d H:i'),
            'awaiting_final_completion' => $batch->awaiting_final_completion_at !== null,
            'is_last_stage' => $this->isAtLastStage($batch),
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
            'parent_batch_id' => $batch->parent_batch_id,
            'stage_history' => $batch->stage_history ?? [],
            'activity_log' => $batch->activity_log ?? [],
            'job_work_flow_state' => $this->jobWorkFlow->flowState($batch),
            'is_job_work_stage' => $this->jobWorkFlow->isBatchAtJobWorkStage($batch),
            'stages' => $this->resolveStagesForBatch($batch),
        ];
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
                : ProductionBatch::query()->find($request->production_batch_id);

            $payload['batch_number'] = $batch?->batch_number;
            $payload['product_name'] = $batch?->product_name;
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
