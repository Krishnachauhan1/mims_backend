<?php

namespace App\Services;

use App\Http\Controllers\Api\InventoryController;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\ProductionBatch;
use App\Models\QualityInspection;
use App\Models\Rejection;
use App\Models\WorkOrder;
use App\Services\JobWorkFlowService;
use Illuminate\Validation\ValidationException;

class QualityInspectionService
{
    public function __construct(private PoCheckinItemService $poItems) {}

    public function sendCheckinToQuality(
        InventoryTransaction $transaction,
        string $itemName,
        int $quantity,
        string $unit,
    ): QualityInspection {
        $reference = $transaction->po_number
            ?? $transaction->batch_number
            ?? ('CHK-'.$transaction->id);

        $isJobWork = $transaction->flow_source === JobWorkFlowService::FLOW_SOURCE;

        if ($isJobWork) {
            InventoryController::holdFinishedGoodForQuality(
                $itemName,
                $reference,
                $quantity,
                $unit,
            );
        } else {
            InventoryController::holdRawMaterialForQuality([
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
            ], $reference);
        }

        $inspection = QualityInspection::query()->create([
            'production_batch_id' => $transaction->production_batch_id,
            'work_order_id' => $transaction->work_order_id,
            'inventory_transaction_id' => $transaction->id,
            'batch_number' => $reference,
            'product_name' => $itemName,
            'item_name' => $itemName,
            'quantity' => max(1, $quantity),
            'passed_qty' => 0,
            'rejected_qty' => 0,
            'unit' => $unit,
            'status' => 'pending',
            'source_type' => 'checkin',
            'po_number' => $transaction->po_number,
            'mrn_number' => $transaction->mrn_number,
            'pr_number' => $transaction->pr_number,
        ]);

        AppNotificationService::qualityInspectionPending($inspection);

        return $inspection;
    }

    public function sendBatchToQuality(ProductionBatch $batch): QualityInspection
    {
        $existing = QualityInspection::query()
            ->where('production_batch_id', $batch->id)
            ->where('status', 'pending')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $quantity = max(1, (int) $batch->quantity);
        $unit = $batch->unit ?? 'pcs';

        $inspection = QualityInspection::query()->create([
            'production_batch_id' => $batch->id,
            'work_order_id' => $batch->work_order_id,
            'batch_number' => $batch->batch_number,
            'product_name' => $batch->product_name,
            'quantity' => $quantity,
            'passed_qty' => 0,
            'rejected_qty' => 0,
            'unit' => $unit,
            'status' => 'pending',
            'source_type' => 'production',
            'workflow_name' => $batch->workflow?->name,
            'pr_number' => $batch->pr_number,
        ]);

        FinishedGood::query()->updateOrCreate(
            ['batch_number' => $batch->batch_number],
            [
                'product_name' => $batch->product_name,
                'quantity' => $quantity,
                'stock' => 0,
                'dispatched' => 0,
                'status' => 'Pending QC',
                'unit' => $unit,
                'warehouse' => 'Quality Hold',
                'location' => 'Quality Inspection',
                'production_date' => now()->toDateString(),
                'source_batch_id' => $batch->id,
                'flow_step' => 'quality_queue',
                'quality_status' => 'pending',
                'pr_number' => $batch->pr_number,
                'workflow' => $batch->workflow?->name,
            ],
        );

        InventoryController::holdFinishedGoodForQuality(
            $batch->product_name,
            $batch->batch_number,
            $quantity,
            $unit,
        );

        if ($batch->work_order_id !== null) {
            WorkOrder::query()
                ->where('id', $batch->work_order_id)
                ->update(['supervisor_status' => 'quality_check']);
        }

        AppNotificationService::qualityInspectionPending($inspection);

        return $inspection;
    }

    /**
     * @return array{inspection: QualityInspection, rejection: Rejection}
     */
    public function rejectItem(
        QualityInspection $inspection,
        string $itemCode,
        int $quantity,
        string $reason,
        ?string $inspectorName,
        ?string $notes = null,
        ?string $reworkAssigneeName = null,
        ?int $reworkAssigneeUserId = null,
    ): array {
        if ($inspection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'This inspection has already been completed.',
            ]);
        }

        $itemCode = trim($itemCode);
        if ($itemCode === '' && $inspection->source_type === 'checkin') {
            $itemCode = $inspection->item_name ?? $inspection->product_name;
        }

        if ($itemCode === '') {
            throw ValidationException::withMessages([
                'item_code' => 'Item code is required.',
            ]);
        }

        $quantity = max(1, $quantity);
        $remaining = $inspection->remainingQty();

        if ($quantity > $remaining) {
            throw ValidationException::withMessages([
                'quantity' => "Cannot reject {$quantity} unit(s). Only {$remaining} remaining in this batch.",
            ]);
        }

        $rejection = Rejection::query()->create([
            'quality_inspection_id' => $inspection->id,
            'production_batch_id' => $inspection->production_batch_id,
            'work_order_id' => $inspection->work_order_id,
            'batch_number' => $inspection->batch_number,
            'product_name' => $inspection->product_name,
            'item_code' => $itemCode,
            'quantity' => $quantity,
            'reason' => trim($reason),
            'notes' => $notes,
            'stage' => $inspection->source_type === 'checkin' ? 'Incoming Material QC' : 'Quality Check',
            'rework_possible' => true,
            'reported_by' => $inspectorName,
            'rejection_date' => now()->toDateString(),
            'total_produced' => $inspection->quantity,
            ...$this->reworkAssignmentFields($reworkAssigneeName, $reworkAssigneeUserId),
        ]);

        $rejectedQty = (int) $inspection->rejected_qty + $quantity;
        $inspection->update(['rejected_qty' => $rejectedQty]);
        $inspection = $inspection->fresh();

        if ($inspection->source_type === 'checkin') {
            $this->syncCheckinPendingHold($inspection, $inspection->remainingQty());
        } else {
            $this->syncPendingHold($inspection, $inspection->remainingQty());
        }

        AppNotificationService::qualityItemRejected($inspection, $rejection, $inspectorName);

        if (filled($rejection->rework_assigned_to)) {
            AppNotificationService::reworkAssigned($rejection, $inspectorName);
        }

        $inspection = $inspection->fresh('rejections');
        $this->finalizeInspectionIfComplete($inspection, $inspectorName, $notes);

        return [
            'inspection' => $inspection->fresh('rejections'),
            'rejection' => $rejection,
        ];
    }

    public function passItem(
        QualityInspection $inspection,
        int $quantity,
        ?string $inspectorName,
        ?string $notes = null,
    ): QualityInspection {
        if ($inspection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'This inspection has already been completed.',
            ]);
        }

        $quantity = max(1, $quantity);
        $remaining = $inspection->remainingQty();

        if ($quantity > $remaining) {
            throw ValidationException::withMessages([
                'quantity' => "Cannot pass {$quantity} unit(s). Only {$remaining} remaining in this inspection.",
            ]);
        }

        if ($inspection->source_type === 'checkin') {
            $inspection->update([
                'passed_qty' => (int) $inspection->passed_qty + $quantity,
            ]);
            $inspection = $inspection->fresh();

            $reference = $inspection->batch_number ?: ('CHK-'.$inspection->inventory_transaction_id);
            if ($this->isJobWorkCheckinInspection($inspection)) {
                InventoryController::releaseFinishedGoodFromQuality(
                    $inspection->item_name ?? $inspection->product_name,
                    $reference,
                    $quantity,
                    $inspection->unit ?? 'pcs',
                );
            } else {
                InventoryController::releaseRawMaterialFromQuality(
                    $inspection->item_name ?? $inspection->product_name,
                    $reference,
                    $quantity,
                    $inspection->unit ?? 'pcs',
                );
            }

            $this->syncCheckinItemAfterQc($inspection, $quantity);
            $this->finalizeInspectionIfComplete($inspection->fresh(), $inspectorName, $notes);

            return $inspection->fresh('rejections');
        }

        return $this->approve($inspection, $inspectorName, $notes);
    }

    private function finalizeInspectionIfComplete(
        QualityInspection $inspection,
        ?string $inspectorName,
        ?string $notes = null,
    ): void {
        if ($inspection->status !== 'pending' || $inspection->remainingQty() > 0) {
            return;
        }

        $passed = (int) $inspection->passed_qty;
        $rejected = (int) $inspection->rejected_qty;

        $status = match (true) {
            $passed > 0 && $rejected > 0 => 'partial_pass',
            $passed > 0 => 'passed',
            default => 'failed',
        };

        $updates = [
            'status' => $status,
            'inspected_by' => $inspectorName ?? $inspection->inspected_by,
            'inspected_at' => now(),
        ];

        if ($notes !== null && trim($notes) !== '') {
            $updates['notes'] = $notes;
        }

        if ($status === 'failed') {
            $updates['failure_reason'] = $inspection->source_type === 'checkin'
                ? 'All items rejected at incoming material quality check'
                : 'All items rejected at quality check';
        }

        $inspection->update($updates);
        $inspection = $inspection->fresh();

        if ($status === 'failed') {
            AppNotificationService::qualityInspectionFailed(
                $inspection,
                (string) ($updates['failure_reason'] ?? 'All items rejected'),
            );

            return;
        }

        AppNotificationService::qualityInspectionPassed($inspection);
    }

    public function approve(
        QualityInspection $inspection,
        ?string $inspectorName,
        ?string $notes = null,
    ): QualityInspection {
        if ($inspection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'This inspection has already been reviewed.',
            ]);
        }

        if ($inspection->source_type === 'checkin') {
            return $this->approveCheckinInspection($inspection, $inspectorName, $notes);
        }

        $passQty = $inspection->remainingQty();
        $rejectedQty = (int) $inspection->rejected_qty;

        if ($passQty < 1) {
            return $this->completeAllRejected($inspection, $inspectorName, $notes);
        }

        $status = $rejectedQty > 0 ? 'partial_pass' : 'passed';

        $inspection->update([
            'status' => $status,
            'passed_qty' => $passQty,
            'inspected_by' => $inspectorName,
            'inspected_at' => now(),
            'notes' => $notes,
        ]);

        FinishedGood::query()
            ->where('batch_number', $inspection->batch_number)
            ->update([
                'quantity' => $passQty,
                'stock' => 0,
                'status' => 'Pending Packing',
                'warehouse' => 'Packing Queue',
                'location' => 'Packing Queue',
                'flow_step' => 'packing',
                'quality_status' => $rejectedQty > 0 ? 'partial' : 'approved',
            ]);

        InventoryController::holdFinishedGoodForPacking(
            $inspection->product_name,
            $inspection->batch_number,
            $passQty,
            $inspection->unit ?? 'pcs',
        );

        AppNotificationService::qualityInspectionPassed($inspection->fresh());

        return $inspection->fresh('rejections');
    }

    private function approveCheckinInspection(
        QualityInspection $inspection,
        ?string $inspectorName,
        ?string $notes = null,
    ): QualityInspection {
        $passQty = $inspection->remainingQty();
        $rejectedQty = (int) $inspection->rejected_qty;

        if ($passQty < 1) {
            return $this->completeCheckinAllRejected($inspection, $inspectorName, $notes);
        }

        $status = $rejectedQty > 0 ? 'partial_pass' : 'passed';

        $inspection->update([
            'status' => $status,
            'passed_qty' => $passQty,
            'inspected_by' => $inspectorName,
            'inspected_at' => now(),
            'notes' => $notes,
        ]);

        $reference = $inspection->batch_number ?: ('CHK-'.$inspection->inventory_transaction_id);
        if ($this->isJobWorkCheckinInspection($inspection)) {
            InventoryController::releaseFinishedGoodFromQuality(
                $inspection->item_name ?? $inspection->product_name,
                $reference,
                $passQty,
                $inspection->unit ?? 'pcs',
            );
        } else {
            InventoryController::releaseRawMaterialFromQuality(
                $inspection->item_name ?? $inspection->product_name,
                $reference,
                $passQty,
                $inspection->unit ?? 'pcs',
            );
        }

        $this->syncCheckinItemAfterQc($inspection->fresh(), $passQty);

        AppNotificationService::qualityInspectionPassed($inspection->fresh());

        return $inspection->fresh('rejections');
    }

    private function completeCheckinAllRejected(
        QualityInspection $inspection,
        ?string $inspectorName,
        ?string $notes = null,
    ): QualityInspection {
        $inspection->update([
            'status' => 'failed',
            'passed_qty' => 0,
            'inspected_by' => $inspectorName,
            'inspected_at' => now(),
            'notes' => $notes,
            'failure_reason' => 'All items rejected at incoming material quality check',
        ]);

        $reference = $inspection->batch_number ?: ('CHK-'.$inspection->inventory_transaction_id);
        InventoryController::reduceRawMaterialQualityHold(
            $inspection->item_name ?? $inspection->product_name,
            $reference,
            (int) $inspection->quantity,
        );

        AppNotificationService::qualityInspectionFailed(
            $inspection->fresh(),
            'All items rejected at incoming material quality check',
        );

        return $inspection->fresh('rejections');
    }

    private function syncCheckinItemAfterQc(QualityInspection $inspection, int $passedQty): void
    {
        if ($inspection->inventory_transaction_id === null || $passedQty < 1) {
            return;
        }

        $transaction = InventoryTransaction::query()->find($inspection->inventory_transaction_id);
        if ($transaction === null) {
            return;
        }

        $itemName = $inspection->item_name ?? $inspection->product_name;
        $items = $this->poItems->normalizeTrackedItems($transaction->items ?? []);
        $updatedItems = [];

        foreach ($items as $item) {
            if (($item['item_name'] ?? '') !== $itemName) {
                $updatedItems[] = $item;

                continue;
            }

            $qcPassed = (int) ($item['qc_passed_quantity'] ?? 0) + $passedQty;
            $received = (int) ($item['received_quantity'] ?? 0);
            $ordered = (int) ($item['ordered_quantity'] ?? 0);

            $updatedItems[] = $this->poItems->buildItem(
                $itemName,
                $ordered,
                $received,
                $item['unit'] ?? 'pcs',
                null,
                is_array($item['receipt_history'] ?? null) ? $item['receipt_history'] : [],
                $qcPassed,
            );
        }

        $phase = $transaction->flow_source === JobWorkFlowService::FLOW_SOURCE
            ? $this->poItems->deriveJobWorkPhase($updatedItems)
            : $this->poItems->derivePoPhase($updatedItems);

        $transaction->update([
            'items' => $updatedItems,
            'receipt_phase' => $phase,
            'status' => $phase,
        ]);

        if ($transaction->flow_source === JobWorkFlowService::FLOW_SOURCE && $passedQty > 0) {
            $this->advanceJobWorkAfterQcPass($transaction->fresh(), $updatedItems, $passedQty);
        }
    }

    private function isJobWorkCheckinInspection(QualityInspection $inspection): bool
    {
        if ($inspection->inventory_transaction_id === null) {
            return false;
        }

        $transaction = InventoryTransaction::query()->find($inspection->inventory_transaction_id);

        return $transaction !== null
            && $transaction->flow_source === JobWorkFlowService::FLOW_SOURCE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $updatedItems
     */
    private function advanceJobWorkAfterQcPass(
        InventoryTransaction $transaction,
        array $updatedItems,
        int $passedQty,
    ): void {
        $batchId = $transaction->production_batch_id;
        if ($batchId === null) {
            return;
        }

        $batch = ProductionBatch::query()->find($batchId);
        if ($batch === null) {
            return;
        }

        $user = request()->user();
        $jobWorkFlow = app(JobWorkFlowService::class);

        if ($this->poItems->jobWorkQcComplete($updatedItems)) {
            $jobWorkFlow->receiveJobWorkReturn($batch, $transaction, $user);
        } else {
            $jobWorkFlow->receivePartialJobWorkReturn($batch, $transaction, $passedQty, $user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reworkAssignmentFields(?string $assigneeName, ?int $assigneeUserId): array
    {
        $name = trim((string) ($assigneeName ?? ''));

        if ($name === '') {
            return [
                'rework_assigned_to' => null,
                'rework_assigned_to_user_id' => null,
                'rework_status' => 'pending',
            ];
        }

        return [
            'rework_assigned_to' => $name,
            'rework_assigned_to_user_id' => $assigneeUserId,
            'rework_status' => 'in_rework',
        ];
    }

    private function syncCheckinPendingHold(QualityInspection $inspection, int $pendingQty): void
    {
        $reference = $inspection->batch_number ?: ('CHK-'.$inspection->inventory_transaction_id);
        $itemName = $inspection->item_name ?? $inspection->product_name;

        if ($pendingQty < 1) {
            InventoryController::reduceRawMaterialQualityHold(
                $itemName,
                $reference,
                (int) $inspection->quantity,
            );

            return;
        }

        InventoryController::reduceRawMaterialQualityHold(
            $itemName,
            $reference,
            (int) $inspection->rejected_qty,
        );
    }

    private function completeAllRejected(
        QualityInspection $inspection,
        ?string $inspectorName,
        ?string $notes = null,
    ): QualityInspection {
        $inspection->update([
            'status' => 'failed',
            'passed_qty' => 0,
            'inspected_by' => $inspectorName,
            'inspected_at' => now(),
            'notes' => $notes,
            'failure_reason' => 'All items rejected at quality check',
        ]);

        FinishedGood::query()
            ->where('batch_number', $inspection->batch_number)
            ->update([
                'stock' => 0,
                'status' => 'QC Failed',
                'quality_status' => 'rejected',
                'flow_step' => 'quality_rejected',
            ]);

        InventoryItem::query()
            ->where('type', 'finished_goods')
            ->where('batch_number', $inspection->batch_number)
            ->update([
                'status' => 'rejected',
                'quantity' => 0,
            ]);

        if ($inspection->work_order_id !== null) {
            WorkOrder::query()
                ->where('id', $inspection->work_order_id)
                ->update(['supervisor_status' => 'rejection']);
        }

        AppNotificationService::qualityInspectionFailed(
            $inspection->fresh(),
            'All items rejected at quality check',
        );

        return $inspection->fresh('rejections');
    }

    private function syncPendingHold(QualityInspection $inspection, int $pendingQty): void
    {
        if ($pendingQty < 1) {
            InventoryItem::query()
                ->where('type', 'finished_goods')
                ->where('batch_number', $inspection->batch_number)
                ->update([
                    'status' => 'rejected',
                    'quantity' => 0,
                ]);

            FinishedGood::query()
                ->where('batch_number', $inspection->batch_number)
                ->update([
                    'status' => 'All Items Rejected',
                    'quality_status' => 'rejected',
                ]);

            return;
        }

        InventoryController::holdFinishedGoodForQuality(
            $inspection->product_name,
            $inspection->batch_number,
            $pendingQty,
            $inspection->unit ?? 'pcs',
        );

        FinishedGood::query()
            ->where('batch_number', $inspection->batch_number)
            ->update([
                'quantity' => $pendingQty,
                'status' => 'Pending QC',
                'quality_status' => 'pending',
            ]);
    }
}
