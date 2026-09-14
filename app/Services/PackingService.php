<?php

namespace App\Services;

use App\Models\PackingEntry;
use App\Models\QualityInspection;

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
}
