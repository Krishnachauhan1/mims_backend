<?php

namespace App\Services;

use App\Http\Controllers\Api\InventoryController;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\ProductionBatch;
use App\Models\QualityInspection;
use App\Models\Rejection;
use App\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

class QualityInspectionService
{
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
    ): array {
        if ($inspection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'This inspection has already been completed.',
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
            'item_code' => trim($itemCode),
            'quantity' => $quantity,
            'reason' => trim($reason),
            'notes' => $notes,
            'stage' => 'Quality Check',
            'rework_status' => 'pending',
            'rework_possible' => true,
            'reported_by' => $inspectorName,
            'rejection_date' => now()->toDateString(),
            'total_produced' => $inspection->quantity,
        ]);

        $rejectedQty = (int) $inspection->rejected_qty + $quantity;
        $inspection->update(['rejected_qty' => $rejectedQty]);
        $inspection = $inspection->fresh();

        $this->syncPendingHold($inspection, $inspection->remainingQty());

        AppNotificationService::qualityItemRejected($inspection, $rejection, $inspectorName);

        return [
            'inspection' => $inspection->load('rejections'),
            'rejection' => $rejection,
        ];
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
                'stock' => $passQty,
                'status' => 'In Stock',
                'warehouse' => 'Finished Goods Warehouse A',
                'location' => 'Finished Goods Warehouse A',
                'flow_step' => 'finished_goods',
                'quality_status' => $rejectedQty > 0 ? 'partial' : 'approved',
            ]);

        InventoryController::releaseFinishedGoodFromQuality(
            $inspection->product_name,
            $inspection->batch_number,
            $passQty,
            $inspection->unit ?? 'pcs',
        );

        if ($inspection->work_order_id !== null) {
            WorkOrder::query()
                ->where('id', $inspection->work_order_id)
                ->update(['supervisor_status' => 'finished_goods']);
        }

        AppNotificationService::qualityInspectionPassed($inspection->fresh());

        return $inspection->fresh('rejections');
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
