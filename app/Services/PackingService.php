<?php

namespace App\Services;

use App\Http\Controllers\Api\InventoryController;
use App\Models\FinishedGood;
use App\Models\PackingEntry;
use App\Models\QualityInspection;
use App\Models\Rejection;
use App\Models\WorkOrder;
use App\Services\AppNotificationService;

class PackingService
{
    /**
     * Ensure packing queue rows exist for production QC that passed.
     * Raw-material (check-in) inspections are excluded.
     */
    public function syncFromPassedProductionInspections(): int
    {
        $created = 0;

        QualityInspection::query()
            ->where('source_type', 'production')
            ->whereIn('status', ['passed', 'partial_pass'])
            ->whereDoesntHave('packingEntry')
            ->with('workOrder')
            ->orderBy('id')
            ->each(function (QualityInspection $inspection) use (&$created): void {
                $this->createFromInspection($inspection);
                $created++;
            });

        return $created;
    }

    public function createFromInspection(QualityInspection $inspection): PackingEntry
    {
        $inspection->loadMissing('workOrder');

        $passQty = (int) ($inspection->passed_qty ?: $inspection->remainingQty());
        $workOrder = $inspection->workOrder;

        return PackingEntry::query()->firstOrCreate(
            ['quality_inspection_id' => $inspection->id],
            [
                'production_batch_id' => $inspection->production_batch_id,
                'work_order_id' => $inspection->work_order_id,
                'batch_number' => (string) $inspection->batch_number,
                'product_name' => (string) $inspection->product_name,
                'item_name' => $inspection->item_name ?: $inspection->product_name,
                'item_code' => $workOrder?->item_code,
                'client_name' => $workOrder?->client_name,
                'quantity' => max(1, $passQty),
                'packed_qty' => 0,
                'unit' => $inspection->unit ?? 'pcs',
                'status' => 'pending',
                'inspected_by' => $inspection->inspected_by,
                'inspected_at' => $inspection->inspected_at,
            ],
        );
    }

    public function releasePackedStockToInventory(PackingEntry $packing, int $packedQty): void
    {
        $packedQty = max(1, min($packedQty, max(1, (int) $packing->quantity)));
        $batchNumber = (string) $packing->batch_number;
        $productName = (string) $packing->product_name;
        $unit = $packing->unit ?? 'pcs';

        InventoryController::releaseFinishedGoodFromQuality(
            $productName,
            $batchNumber,
            $packedQty,
            $unit,
        );

        FinishedGood::query()
            ->where('batch_number', $batchNumber)
            ->update([
                'quantity' => $packedQty,
                'stock' => $packedQty,
                'status' => 'In Stock',
                'warehouse' => 'Finished Goods Warehouse A',
                'location' => 'Finished Goods Warehouse A',
                'flow_step' => 'finished_goods',
            ]);

        if ($packing->work_order_id !== null) {
            WorkOrder::query()
                ->where('id', $packing->work_order_id)
                ->update(['supervisor_status' => 'finished_goods']);
        }
    }

    public function handlePackingRejected(PackingEntry $packing): void
    {
        InventoryController::markFinishedGoodPackingRejected(
            (string) $packing->product_name,
            (string) $packing->batch_number,
        );

        FinishedGood::query()
            ->where('batch_number', $packing->batch_number)
            ->update([
                'status' => 'Packing Rejected',
                'stock' => 0,
                'quality_status' => 'rejected',
            ]);
    }

    public function remainingQty(PackingEntry $packing): int
    {
        return max(
            0,
            (int) $packing->quantity
            - (int) $packing->packed_qty
            - (int) ($packing->rejected_qty ?? 0)
            - (int) ($packing->hold_qty ?? 0),
        );
    }

    /**
     * Reject part of the batch at packing and release the rest to inventory.
     *
     * @return Rejection|null
     */
    public function handlePartialPackingRejectAndPack(
        PackingEntry $packing,
        int $rejectQty,
        int $packedQty,
        string $rejectReason,
        ?string $reworkAssigneeName,
        ?int $reworkAssigneeUserId,
        ?string $reportedBy,
    ): ?Rejection {
        $rejectQty = max(0, $rejectQty);
        $packedQty = max(0, $packedQty);

        if ($rejectQty < 1 && $packedQty < 1) {
            return null;
        }

        $rejection = null;

        if ($rejectQty > 0) {
            $itemCode = trim((string) ($packing->item_code ?: $packing->product_name));
            $rejection = Rejection::query()->create([
                'quality_inspection_id' => $packing->quality_inspection_id,
                'production_batch_id' => $packing->production_batch_id,
                'work_order_id' => $packing->work_order_id,
                'batch_number' => $packing->batch_number,
                'product_name' => $packing->product_name,
                'item_code' => $itemCode !== '' ? $itemCode : $packing->product_name,
                'quantity' => $rejectQty,
                'reason' => trim($rejectReason),
                'notes' => 'Rejected at packing',
                'stage' => 'Packing',
                'rework_possible' => true,
                'reported_by' => $reportedBy,
                'rejection_date' => now()->toDateString(),
                'total_produced' => (int) $packing->quantity,
                'rework_assigned_to' => filled($reworkAssigneeName) ? trim($reworkAssigneeName) : null,
                'rework_assigned_to_user_id' => $reworkAssigneeUserId,
                'rework_status' => filled($reworkAssigneeName) ? 'in_rework' : 'pending',
            ]);

            if (filled($rejection->rework_assigned_to)) {
                AppNotificationService::reworkAssigned($rejection, $reportedBy ?? 'User');
            }
        }

        if ($packedQty > 0) {
            $this->releasePackedStockToInventory($packing, $packedQty);

            if ($rejectQty > 0) {
                FinishedGood::query()
                    ->where('batch_number', $packing->batch_number)
                    ->update(['quality_status' => 'partial']);
            }
        } elseif ($rejectQty > 0) {
            $this->handlePackingRejected($packing);
        }

        return $rejection;
    }
}
