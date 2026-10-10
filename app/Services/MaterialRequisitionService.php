<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\MaterialRequisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialRequisitionService
{
    public function __construct(
        private InventoryStockAllocationService $stockAllocation,
        private TaskMaterialService $taskMaterials,
    ) {}

    public function resolveItemName(?int $inventoryItemId, string $fallbackName): array
    {
        $itemName = trim($fallbackName);
        $unit = 'pcs';

        if ($inventoryItemId !== null) {
            $item = InventoryItem::query()->find($inventoryItemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => ['Selected inventory item was not found.'],
                ]);
            }

            $this->assertRawMaterial($item);
            $itemName = $item->name ?: $itemName;
            $unit = $item->unit ?: $unit;
        }

        if ($itemName === '') {
            throw ValidationException::withMessages([
                'item_name' => ['Item name is required.'],
            ]);
        }

        return [$itemName, $unit];
    }

    public function issue(MaterialRequisition $requisition, int $inventoryItemId): MaterialRequisition
    {
        if ($requisition->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be issued.'],
            ]);
        }

        return DB::transaction(function () use ($requisition, $inventoryItemId) {
            $lines = $this->stockAllocation->buildAllocationLines(
                $inventoryItemId ?? $requisition->inventory_item_id,
                $requisition->item_name,
                (float) $requisition->quantity,
            );

            $primary = InventoryItem::query()->find($lines[0]['inventory_item_id'] ?? null);
            if ($primary !== null) {
                $this->assertRawMaterial($primary);
            }

            $destination = trim((string) ($requisition->machine_name ?? ''));
            if ($destination === '') {
                $destination = trim((string) ($requisition->route_card_no ?? 'Material issue'));
            }

            $this->taskMaterials->consumeInventoryLines(
                $lines,
                "Material requisition #{$requisition->id}",
                $destination,
            );

            $requisition->update([
                'inventory_item_id' => $lines[0]['inventory_item_id'],
                'item_name' => $primary?->name ?: $requisition->item_name,
                'unit' => $primary?->unit ?: $requisition->unit,
                'status' => 'issued',
            ]);

            return $requisition->fresh(['inventoryItem', 'recipient', 'requestedBy']);
        });
    }

    private function assertRawMaterial(InventoryItem $item): void
    {
        $type = strtolower(trim((string) $item->type));
        if ($type !== 'raw_material' && $type !== 'raw material') {
            throw ValidationException::withMessages([
                'inventory_item_id' => ['Only raw material inventory items can be issued from this screen.'],
            ]);
        }
    }
}
