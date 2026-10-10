<?php

namespace App\Services;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class InventoryStockAllocationService
{
    /**
     * All in-stock raw-material rows that match the anchor item or item name (same SKU or name).
     *
     * @return Collection<int, InventoryItem>
     */
    public function matchingRawMaterialRows(?int $anchorItemId, ?string $itemName): Collection
    {
        $anchor = $anchorItemId !== null
            ? InventoryItem::query()->find($anchorItemId)
            : null;

        $name = trim((string) $itemName);
        if ($anchor !== null) {
            $name = trim((string) ($anchor->name ?: $name));
        }

        $base = InventoryItem::query()
            ->where('quantity', '>', 0)
            ->where(function ($query): void {
                $query->where('type', 'raw_material')
                    ->orWhere('type', 'like', '%raw%');
            });

        $normalizedName = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');

        if ($normalizedName !== '') {
            $byName = $base
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                ->orderByDesc('quantity')
                ->orderBy('id')
                ->get();

            if ($byName->isNotEmpty()) {
                return $byName;
            }
        }

        $sku = $anchor !== null ? trim((string) ($anchor->sku ?? '')) : '';
        if ($sku !== '') {
            return $base
                ->whereRaw('LOWER(TRIM(COALESCE(sku, ""))) = ?', [strtolower($sku)])
                ->orderByDesc('quantity')
                ->orderBy('id')
                ->get();
        }

        if ($anchor !== null && (int) $anchor->quantity > 0) {
            return new Collection([$anchor]);
        }

        return new Collection;
    }

    public function totalAvailable(?int $anchorItemId, ?string $itemName): int
    {
        return (int) $this->matchingRawMaterialRows($anchorItemId, $itemName)->sum('quantity');
    }

    /**
     * Split requested quantity across matching inventory rows (highest stock first).
     *
     * @return array<int, array{inventory_item_id: int, quantity: int}>
     */
    public function buildAllocationLines(
        ?int $anchorItemId,
        ?string $itemName,
        float $requestedQty,
    ): array {
        $needed = (int) ceil($requestedQty);
        if ($needed < 1) {
            $needed = 1;
        }

        $rows = $this->matchingRawMaterialRows($anchorItemId, $itemName);
        $available = (int) $rows->sum('quantity');

        $label = trim((string) $itemName);
        if ($label === '' && $rows->isNotEmpty()) {
            $label = (string) $rows->first()->name;
        }
        if ($label === '') {
            $label = 'material';
        }

        if ($available < $needed) {
            throw ValidationException::withMessages([
                'quantity' => [
                    "Insufficient stock for {$label}. Available: {$available}, requested: {$needed}.",
                ],
            ]);
        }

        $remaining = $needed;
        $lines = [];

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $onHand = (int) $row->quantity;
            if ($onHand < 1) {
                continue;
            }

            $take = min($remaining, $onHand);
            $lines[] = [
                'inventory_item_id' => (int) $row->id,
                'quantity' => $take,
            ];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'quantity' => [
                    "Insufficient stock for {$label}. Available: {$available}, requested: {$needed}.",
                ],
            ]);
        }

        return $lines;
    }
}
