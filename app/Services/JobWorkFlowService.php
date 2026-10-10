<?php

namespace App\Services;

use App\Http\Controllers\Api\InventoryController;
use App\Models\FinishedGood;
use App\Models\InventoryTransaction;
use App\Models\JobWork;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class JobWorkFlowService
{
    public const FLOW_SOURCE = 'job_work';

    public const CHECKOUT_STATUS_SENT = 'sent_for_job_work';

    public const CHECKIN_PHASE_AWAITING = 'job_work_awaiting_return';

    public const CHECKIN_PHASE_RECEIVED = 'job_work_received';

    public function __construct(private PermissionService $permissions) {}

    public function schemaReady(): bool
    {
        return Schema::hasTable('inventory_transactions')
            && Schema::hasColumn('inventory_transactions', 'production_batch_id')
            && Schema::hasColumn('inventory_transactions', 'flow_source');
    }

    public function assertSchemaReady(): void
    {
        if (! $this->schemaReady()) {
            throw new HttpException(
                503,
                'Job work flow database update pending — run: php artisan migrate --force',
            );
        }
    }

    public function isJobWorkStage(mixed $stage): bool
    {
        if (! is_array($stage) || $stage === []) {
            return false;
        }

        if ($this->flagIsTrue($stage['is_job_work'] ?? false)) {
            return true;
        }

        $processType = strtolower(str_replace([' ', '-'], '_', (string) ($stage['process_type'] ?? '')));
        if ($processType === 'job_work') {
            return true;
        }

        $name = strtolower(trim((string) ($stage['name'] ?? '')));

        return $name === 'job work' || str_contains($name, 'job work');
    }

    public function isBatchAtJobWorkStage(ProductionBatch $batch): bool
    {
        $stages = $this->resolveStagesForBatch($batch);
        $order = max(1, (int) $batch->stage_order);
        $current = $stages[$order - 1] ?? null;

        if ($this->isJobWorkStage($current)) {
            return true;
        }

        $currentName = strtolower(trim((string) $batch->current_stage));

        return $currentName === 'job work' || str_contains($currentName, 'job work');
    }

    public function flowState(ProductionBatch $batch): string
    {
        if (! $this->isBatchAtJobWorkStage($batch)) {
            return 'not_job_work';
        }

        return $this->flowStateFromTransactions($batch);
    }

    /**
     * Job-work movement state from checkout/checkin rows (ignores stage-name guards).
     */
    public function flowStateFromTransactions(ProductionBatch $batch): string
    {
        if (! $this->schemaReady()) {
            return 'ready_to_send';
        }

        $visitIndex = $this->jobWorkVisitIndex($batch);
        $checkouts = InventoryTransaction::query()
            ->where('production_batch_id', $batch->id)
            ->where('flow_source', self::FLOW_SOURCE)
            ->where('type', 'checkout')
            ->orderBy('id')
            ->get();

        $checkins = InventoryTransaction::query()
            ->where('production_batch_id', $batch->id)
            ->where('flow_source', self::FLOW_SOURCE)
            ->where('type', 'checkin')
            ->orderBy('id')
            ->get();

        if ($checkouts->count() < $visitIndex) {
            return 'ready_to_send';
        }

        $checkin = $checkins->get($visitIndex - 1);
        if ($checkin === null) {
            return 'sent_out';
        }

        $phase = $checkin->receipt_phase ?? $checkin->status;

        return $phase === self::CHECKIN_PHASE_RECEIVED
            ? 'returned'
            : 'awaiting_checkin';
    }

    public function displayFlowState(ProductionBatch $batch, bool $isJobWorkStage): string
    {
        if (! $isJobWorkStage) {
            return 'not_job_work';
        }

        return $this->flowStateFromTransactions($batch);
    }

    private function jobWorkVisitIndex(ProductionBatch $batch): int
    {
        $stages = $this->resolveStagesForBatch($batch);
        $order = max(1, (int) $batch->stage_order);
        $count = 0;

        foreach ($stages as $index => $stage) {
            if ($index + 1 > $order) {
                break;
            }

            if ($this->isJobWorkStage($stage)) {
                $count++;
            }
        }

        return max(1, $count);
    }

    public function sendForJobWork(ProductionBatch $batch, ?User $user): array
    {
        $this->assertSchemaReady();

        if (! $this->isBatchAtJobWorkStage($batch)) {
            throw new HttpException(422, 'Batch is not at a Job Work stage');
        }

        if ($this->flowStateFromTransactions($batch) !== 'ready_to_send') {
            throw new HttpException(422, 'Batch has already been sent for job work');
        }

        $result = DB::transaction(function () use ($batch, $user) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! $this->isBatchAtJobWorkStage($batch)) {
                throw new HttpException(422, 'Batch is not at a Job Work stage');
            }

            if ($this->flowStateFromTransactions($batch) !== 'ready_to_send') {
                throw new HttpException(422, 'Batch has already been sent for job work');
            }

            $jobWork = $this->resolveJobWork($batch);
            $vendor = $this->vendorForCurrentStage($batch, $jobWork);

            $checkout = InventoryTransaction::query()->create(
                $this->checkoutPayload($batch, $jobWork, $vendor, $user),
            );

            $checkin = InventoryTransaction::query()->create(
                $this->checkinPayload($batch, $jobWork, $vendor, $user),
            );

            if ($jobWork !== null) {
                $jobWork->update([
                    'status' => 'in_progress',
                    'sent_date' => now(),
                    'batch_id' => $jobWork->batch_id ?? $batch->id,
                ]);
            }

            return [
                'batch' => $batch->fresh('workflow'),
                'checkout' => $checkout,
                'checkin' => $checkin,
                'vendor' => $vendor,
            ];
        });

        try {
            AppNotificationService::jobWorkSentOut(
                $result['batch'],
                $result['vendor'],
                $user?->name,
            );
        } catch (\Throwable) {
            // Keep checkout/checkin saved even if push notification fails.
        }

        unset($result['vendor']);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(
        ProductionBatch $batch,
        ?JobWork $jobWork,
        string $vendor,
        ?User $user,
    ): array {
        return [
            'type' => 'checkout',
            'material_name' => $batch->product_name,
            'destination' => $vendor,
            'quantity' => max(1, (int) $batch->quantity),
            'unit' => $batch->unit ?? 'pcs',
            'batch_number' => $batch->batch_number,
            'notes' => "Sent for job work — {$batch->batch_number}",
            'status' => self::CHECKOUT_STATUS_SENT,
            'flow_source' => self::FLOW_SOURCE,
            'production_batch_id' => $batch->id,
            'job_work_id' => $jobWork?->id,
            'work_order_id' => $batch->work_order_id,
            'user_id' => $user?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkinPayload(
        ProductionBatch $batch,
        ?JobWork $jobWork,
        string $vendor,
        ?User $user,
    ): array {
        return [
            'type' => 'checkin',
            'material_name' => $batch->product_name,
            'supplier' => $vendor,
            'quantity' => max(1, (int) $batch->quantity),
            'unit' => $batch->unit ?? 'pcs',
            'batch_number' => $batch->batch_number,
            'po_number' => $batch->batch_number,
            'notes' => "Awaiting return from job work — {$batch->batch_number}",
            'status' => self::CHECKIN_PHASE_AWAITING,
            'receipt_phase' => self::CHECKIN_PHASE_AWAITING,
            'flow_source' => self::FLOW_SOURCE,
            'production_batch_id' => $batch->id,
            'job_work_id' => $jobWork?->id,
            'work_order_id' => $batch->work_order_id,
            'user_id' => $user?->id,
            'items' => [
                [
                    'item_name' => $batch->product_name,
                    'ordered_quantity' => max(1, (int) $batch->quantity),
                    'received_quantity' => 0,
                    'pending_quantity' => max(1, (int) $batch->quantity),
                    'unit' => $batch->unit ?? 'pcs',
                    'status' => 'pending',
                    'receipt_history' => [],
                ],
            ],
        ];
    }

    /**
     * @return array{batch: ProductionBatch, split_batch: ProductionBatch, quantity_advanced: int}
     */
    public function receivePartialJobWorkReturn(
        ProductionBatch $batch,
        InventoryTransaction $checkin,
        int $quantity,
        ?User $user,
    ): array {
        $this->assertSchemaReady();
        $this->assertJobWorkCheckin($batch, $checkin);

        if ($quantity < 1) {
            throw new HttpException(422, 'Quantity must be at least 1');
        }

        $result = DB::transaction(function () use ($batch, $checkin, $quantity, $user) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $batchQty = max(0, (int) $batch->quantity);
            $advanceQty = min($quantity, $batchQty);

            if ($advanceQty < 1) {
                throw new HttpException(422, 'No batch quantity available to advance');
            }

            $operator = $user?->name ?? 'Operator';
            $now = now()->format('Y-m-d H:i');
            $remaining = max(0, $batchQty - $advanceQty);

            $sourceActivity = $batch->activity_log ?? [];
            array_unshift($sourceActivity, [
                'action' => "Returned {$advanceQty} units from job work (partial) — sent to next stage",
                'user' => $operator,
                'time' => $now,
            ]);

            $batch->update([
                'quantity' => $remaining,
                'activity_log' => $sourceActivity,
            ]);

            $splitBatch = $this->createSplitBatchFromJobWorkReturn(
                $batch,
                $advanceQty,
                $operator,
                $now,
            );

            $jobWork = $this->resolveJobWork($batch);
            if ($jobWork !== null && $jobWork->status !== 'completed') {
                $jobWork->update(['status' => 'in_progress']);
            }

            return [
                'batch' => $batch->fresh('workflow'),
                'split_batch' => $splitBatch,
                'quantity_advanced' => $advanceQty,
            ];
        });

        try {
            AppNotificationService::jobWorkReturned(
                $result['split_batch'],
                $user?->name,
                $result['split_batch']->status === 'completed',
            );
        } catch (\Throwable) {
            // Keep return saved even if push notification fails.
        }

        return $result;
    }

    public function receiveJobWorkReturn(ProductionBatch $batch, InventoryTransaction $checkin, ?User $user): ProductionBatch
    {
        $this->assertSchemaReady();
        $this->assertJobWorkCheckin($batch, $checkin);

        $result = DB::transaction(function () use ($batch, $checkin, $user) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $checkin->update([
                'status' => self::CHECKIN_PHASE_RECEIVED,
                'receipt_phase' => self::CHECKIN_PHASE_RECEIVED,
                'user_id' => $user?->id ?? $checkin->user_id,
            ]);

            $jobWork = $this->resolveJobWork($batch);
            if ($jobWork !== null) {
                $jobWork->update([
                    'status' => 'completed',
                    'actual_return_date' => now(),
                ]);
            }

            $batchQty = max(0, (int) $batch->quantity);
            $stages = $this->resolveStagesForBatch($batch);
            $stageOrder = max(1, (int) $batch->stage_order);
            $isLastStage = $stageOrder >= count($stages) || $stageOrder >= (int) $batch->total_stages;

            if ($batchQty > 0) {
                if ($isLastStage) {
                    $batch->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'stage_order' => max(1, (int) $batch->total_stages),
                        'current_stage_index' => max(0, (int) $batch->total_stages - 1),
                    ]);

                    app(QualityInspectionService::class)->sendBatchToQuality($batch->fresh('workflow'));
                } else {
                    $nextOrder = min(count($stages), $stageOrder + 1);
                    $nextStage = $stages[$nextOrder - 1]['name'] ?? $batch->current_stage;

                    $batch->update([
                        'stage_order' => $nextOrder,
                        'current_stage_index' => $nextOrder - 1,
                        'current_stage' => $nextStage,
                        'stage_started_at' => now(),
                        'status' => 'in_progress',
                    ]);
                }
            } else {
                $batch->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            return [
                'batch' => $batch->fresh('workflow'),
                'is_last_stage' => $isLastStage,
            ];
        });

        try {
            AppNotificationService::jobWorkReturned(
                $result['batch'],
                $user?->name,
                $result['is_last_stage'],
            );
        } catch (\Throwable) {
            // Keep return saved even if push notification fails.
        }

        return $result['batch'];
    }

    private function assertJobWorkCheckin(ProductionBatch $batch, InventoryTransaction $checkin): void
    {
        if ($checkin->type !== 'checkin' || $checkin->flow_source !== self::FLOW_SOURCE) {
            throw new HttpException(422, 'Invalid job work check-in record');
        }

        if ((int) $checkin->production_batch_id !== (int) $batch->id) {
            throw new HttpException(422, 'Check-in does not belong to this batch');
        }

        $phase = $checkin->receipt_phase ?? $checkin->status;
        if ($phase === self::CHECKIN_PHASE_RECEIVED) {
            throw new HttpException(422, 'Job work return already checked in');
        }
    }

    private function createSplitBatchFromJobWorkReturn(
        ProductionBatch $batch,
        int $quantity,
        string $operator,
        string $now,
    ): ProductionBatch {
        $stages = $this->resolveStagesForBatch($batch);
        $stageOrder = max(1, (int) $batch->stage_order);
        $isLastStage = $stageOrder >= count($stages) || $stageOrder >= (int) $batch->total_stages;
        $currentStageName = $stages[$stageOrder - 1]['name'] ?? $batch->current_stage;

        $splitActivity = [[
            'action' => "Received {$quantity} units from job work (partial return)",
            'user' => $operator,
            'time' => $now,
        ]];

        if ($isLastStage) {
            $splitBatch = ProductionBatch::query()->create([
                'batch_number' => $this->nextSplitBatchNumber($batch),
                'product_name' => $batch->product_name,
                'workflow_id' => $batch->workflow_id,
                'current_stage' => $currentStageName,
                'current_stage_index' => $stageOrder - 1,
                'stage_order' => $stageOrder,
                'total_stages' => $batch->total_stages,
                'quantity' => $quantity,
                'status' => 'completed',
                'started_at' => $batch->started_at,
                'stage_started_at' => now(),
                'completed_at' => now(),
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
                'activity_log' => $splitActivity,
            ]);

            $splitBatch = $splitBatch->fresh('workflow');
            app(QualityInspectionService::class)->sendBatchToQuality($splitBatch);

            return $splitBatch;
        }

        $nextStageOrder = min(count($stages), $stageOrder + 1);
        $nextStageName = $stages[$nextStageOrder - 1]['name'] ?? $batch->current_stage;
        $splitHistory = $this->buildSplitStageHistory(
            $batch,
            $currentStageName,
            $nextStageName,
            $operator,
            $now,
        );

        $splitBatch = ProductionBatch::query()->create([
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
        $this->notifyStageReached($splitBatch);

        return $splitBatch;
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

    public function notifyStageReached(ProductionBatch $batch): void
    {
        if (! $this->isBatchAtJobWorkStage($batch)) {
            return;
        }

        if ($this->flowState($batch) !== 'ready_to_send') {
            return;
        }

        AppNotificationService::jobWorkStageReady($batch);
    }

    private function resolveJobWork(ProductionBatch $batch): ?JobWork
    {
        $stageOrder = max(1, (int) $batch->stage_order);

        if ($batch->work_order_id !== null) {
            $jobNumber = sprintf(
                'JW-WO%s-S%d',
                str_replace('-', '', (string) $batch->work_order_id),
                $stageOrder,
            );

            $byNumber = JobWork::query()->where('job_number', $jobNumber)->first();
            if ($byNumber !== null) {
                return $byNumber;
            }

            $visitIndex = $this->jobWorkVisitIndex($batch);
            $jobWorks = JobWork::query()
                ->where('work_order_id', $batch->work_order_id)
                ->orderBy('id')
                ->get()
                ->values();

            if ($visitIndex <= $jobWorks->count()) {
                return $jobWorks->get($visitIndex - 1);
            }
        }

        return JobWork::query()
            ->where('batch_id', $batch->id)
            ->latest('id')
            ->first();
    }

    private function vendorForCurrentStage(ProductionBatch $batch, ?JobWork $jobWork): string
    {
        $stages = $this->resolveStagesForBatch($batch);
        $order = max(1, (int) $batch->stage_order);
        $current = $stages[$order - 1] ?? null;

        if ($current !== null && $this->isJobWorkStage($current)) {
            $name = trim((string) ($current['vendor_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $batch->vendor_name ?? $jobWork?->vendor_name ?? 'Vendor';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveStagesForBatch(ProductionBatch $batch): array
    {
        if ($batch->work_order_id) {
            $order = WorkOrder::query()->find($batch->work_order_id);
            $orderStages = $order?->stages ?? [];
            if (is_array($orderStages) && $orderStages !== []) {
                return $this->sortStages($orderStages);
            }
        }

        $workflowStages = $batch->workflow?->stages ?? [];
        if (is_array($workflowStages) && $workflowStages !== []) {
            return $this->sortStages($workflowStages);
        }

        return [];
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

    private function flagIsTrue(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes'], true);
        }

        return false;
    }
}
