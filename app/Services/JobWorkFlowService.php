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

    public function isJobWorkStage(?array $stage): bool
    {
        if ($stage === null || $stage === []) {
            return false;
        }

        if (($stage['is_job_work'] ?? false) === true) {
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

        if (! $this->schemaReady()) {
            return 'ready_to_send';
        }

        $checkout = $this->latestCheckout($batch);
        if ($checkout === null) {
            return 'ready_to_send';
        }

        $checkin = $this->latestCheckin($batch);
        if ($checkin === null) {
            return 'sent_out';
        }

        $phase = $checkin->receipt_phase ?? $checkin->status;

        return $phase === self::CHECKIN_PHASE_RECEIVED
            ? 'returned'
            : 'awaiting_checkin';
    }

    public function sendForJobWork(ProductionBatch $batch, ?User $user): array
    {
        $this->assertSchemaReady();

        if (! $this->isBatchAtJobWorkStage($batch)) {
            throw new HttpException(422, 'Batch is not at a Job Work stage');
        }

        if ($this->flowState($batch) !== 'ready_to_send') {
            throw new HttpException(422, 'Batch has already been sent for job work');
        }

        $result = DB::transaction(function () use ($batch, $user) {
            $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $jobWork = $this->resolveJobWork($batch);
            $vendor = $batch->vendor_name ?? $jobWork?->vendor_name ?? 'Vendor';

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
        ];
    }

    public function receiveJobWorkReturn(ProductionBatch $batch, InventoryTransaction $checkin, ?User $user): ProductionBatch
    {
        $this->assertSchemaReady();

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

            $stages = $this->resolveStagesForBatch($batch);
            $stageOrder = max(1, (int) $batch->stage_order);
            $isLastStage = $stageOrder >= count($stages) || $stageOrder >= (int) $batch->total_stages;

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
        $existing = JobWork::query()
            ->where('batch_id', $batch->id)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($batch->work_order_id === null) {
            return null;
        }

        return JobWork::query()
            ->where('work_order_id', $batch->work_order_id)
            ->latest('id')
            ->first();
    }

    private function latestCheckout(ProductionBatch $batch): ?InventoryTransaction
    {
        return InventoryTransaction::query()
            ->where('production_batch_id', $batch->id)
            ->where('flow_source', self::FLOW_SOURCE)
            ->where('type', 'checkout')
            ->latest('id')
            ->first();
    }

    private function latestCheckin(ProductionBatch $batch): ?InventoryTransaction
    {
        return InventoryTransaction::query()
            ->where('production_batch_id', $batch->id)
            ->where('flow_source', self::FLOW_SOURCE)
            ->where('type', 'checkin')
            ->latest('id')
            ->first();
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
        usort($stages, fn (array $left, array $right) => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)));

        return array_values($stages);
    }
}
