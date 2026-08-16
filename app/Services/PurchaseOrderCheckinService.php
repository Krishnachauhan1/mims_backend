<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;

class PurchaseOrderCheckinService
{
    public function __construct(private PoCheckinItemService $poItems) {}

    public function syncApprovedOrders(): void
    {
        PurchaseOrder::query()
            ->where('status', 'approved')
            ->get()
            ->each(fn (PurchaseOrder $po) => $this->queueForCheckin($po));
    }

    public function queueForCheckin(PurchaseOrder $po): void
    {
        if ($po->status !== 'approved') {
            return;
        }

        $existing = InventoryTransaction::query()
            ->where('type', 'checkin')
            ->where('po_number', $po->po_number)
            ->where(function ($query): void {
                $query->whereNull('flow_source')
                    ->orWhere('flow_source', 'purchase_order');
            })
            ->first();

        $items = $this->poItems->normalizeTrackedItems(
            $po->items ?? [],
            $existing?->items,
        );

        if ($items === []) {
            return;
        }

        $phase = $this->poItems->derivePoPhase($items);
        $totalReceived = $this->poItems->totalReceivedQuantity($items);

        if ($existing !== null) {
            $existing->update([
                'material_name' => $po->po_number,
                'supplier' => $po->vendor_name,
                'quantity' => $totalReceived,
                'unit' => 'mixed',
                'batch_number' => $po->po_number,
                'pr_number' => $po->pr_number,
                'items' => $items,
                'flow_source' => 'purchase_order',
                'receipt_phase' => $phase,
                'status' => $phase,
            ]);

            return;
        }

        InventoryTransaction::query()->create([
            'type' => 'checkin',
            'po_number' => $po->po_number,
            'flow_source' => 'purchase_order',
            'material_name' => $po->po_number,
            'supplier' => $po->vendor_name,
            'quantity' => $totalReceived,
            'unit' => 'mixed',
            'batch_number' => $po->po_number,
            'pr_number' => $po->pr_number,
            'items' => $items,
            'notes' => 'Awaiting material arrival from approved PO',
            'status' => 'order_placed',
            'receipt_phase' => 'order_placed',
        ]);
    }
}
