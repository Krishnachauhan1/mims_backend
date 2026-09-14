<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogInventorySyncService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function virtualRowsForMissing(Collection $inventoryRows, ?string $inventoryType = null): array
    {
        try {
            $catalogItems = Item::query()
                ->where('status', 'active')
                ->whereIn('category', ['product', 'raw_material'])
                ->get(['id', 'code', 'name', 'description', 'grade', 'diameter_mm', 'unit', 'min_stock_alert', 'purchase_price', 'category']);

            if ($catalogItems->isEmpty()) {
                return [];
            }

            $keysByType = $this->inventoryKeysByType($inventoryRows);
            $virtual = [];

            foreach ($catalogItems as $item) {
                $type = $this->catalogInventoryType($item);
                if ($inventoryType !== null && $inventoryType !== '' && $type !== $inventoryType) {
                    continue;
                }

                $catalogKeys = $this->catalogMatchKeys($item);
                if ($this->hasInventoryMatch($keysByType, $type, $catalogKeys)) {
                    continue;
                }

                $virtual[] = $this->virtualRow($item, $type);
            }

            return $virtual;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function ensureItem(Item $item): void
    {
        if ($item->status !== 'active' || ! $this->isCatalogCategory($item->category)) {
            $this->removePlaceholderForItem($item);
        }
    }

    /**
     * Remove legacy CAT-* placeholder rows (safe chunked delete).
     */
    public function pruneLegacyPlaceholders(): int
    {
        $deleted = 0;

        try {
            do {
                $batch = InventoryItem::query()
                    ->where('sku', 'like', 'CAT-%')
                    ->where('quantity', 0)
                    ->limit(100)
                    ->delete();
                $deleted += $batch;
            } while ($batch > 0);
        } catch (\Throwable $e) {
            report($e);
        }

        return $deleted;
    }

    private function removePlaceholderForItem(Item $item): void
    {
        InventoryItem::query()
            ->where('sku', 'CAT-'.$item->id)
            ->where('quantity', 0)
            ->delete();
    }

    /**
     * @return array<string, list<string>>
     */
    private function inventoryKeysByType(Collection $inventoryRows): array
    {
        $keysByType = [];

        foreach ($inventoryRows as $row) {
            if (! $row instanceof InventoryItem) {
                continue;
            }

            $type = (string) $row->type;
            $keysByType[$type] ??= [];
            foreach ($this->inventoryMatchKeys($row) as $key) {
                $keysByType[$type][$key] = true;
            }
        }

        return array_map(
            fn (array $keys) => array_keys($keys),
            $keysByType,
        );
    }

    /**
     * @param  array<string, list<string>>  $keysByType
     * @param  list<string>  $catalogKeys
     */
    private function hasInventoryMatch(array $keysByType, string $type, array $catalogKeys): bool
    {
        $inventoryKeys = $keysByType[$type] ?? [];
        if ($inventoryKeys === [] || $catalogKeys === []) {
            return false;
        }

        return count(array_intersect($catalogKeys, $inventoryKeys)) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function virtualRow(Item $item, string $type): array
    {
        $purchasePrice = (float) ($item->purchase_price ?? 0);

        $description = filled($item->description) ? trim((string) $item->description) : null;
        if ($description !== null && strcasecmp($description, $this->inventoryDisplayName($item)) === 0) {
            $description = null;
        }

        return [
            'id' => -1 * (int) $item->id,
            'name' => $this->inventoryDisplayName($item),
            'sku' => filled($item->code) ? (string) $item->code : 'ITEM-'.$item->id,
            'type' => $type,
            'status' => 'available',
            'quantity' => 0,
            'unit' => $item->unit ?: ($type === 'raw_material' ? 'kg' : 'pcs'),
            'batch_number' => null,
            'location' => null,
            'min_stock' => max(0, (int) ($item->min_stock_alert ?? 0)),
            'item_id' => $item->id,
            'item_code' => filled($item->code) ? (string) $item->code : null,
            'description' => $description,
            'catalog_name' => filled($item->name) ? trim((string) $item->name) : null,
            'is_catalog_only' => true,
            'purchase_price' => $purchasePrice > 0 ? $purchasePrice : null,
            'unit_cost' => $purchasePrice > 0 ? $purchasePrice : null,
            'cost_type' => $purchasePrice > 0 ? 'catalog' : null,
        ];
    }

    private function isCatalogCategory(?string $category): bool
    {
        return in_array(strtolower(trim((string) $category)), ['product', 'raw_material'], true);
    }

    private function catalogInventoryType(Item $item): string
    {
        return strtolower(trim((string) $item->category)) === 'raw_material'
            ? 'raw_material'
            : 'finished_goods';
    }

    /**
     * @return list<string>
     */
    private function catalogMatchKeys(Item $item): array
    {
        $keys = [];
        foreach ([$item->name, $item->code, $item->description] as $value) {
            if (filled($value)) {
                $keys[] = $this->normalizeKey($value);
            }
        }

        if (filled($item->grade) && (float) ($item->diameter_mm ?? 0) > 0) {
            $dia = (float) $item->diameter_mm;
            $diaLabel = fmod($dia, 1.0) === 0.0 ? (string) (int) $dia : (string) $dia;
            $keys[] = $this->normalizeKey(strtoupper($item->grade).' DIA '.$diaLabel);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @return list<string>
     */
    private function inventoryMatchKeys(InventoryItem $item): array
    {
        $keys = [];
        foreach ([$item->name, $item->sku, $item->batch_number] as $value) {
            if (filled($value)) {
                $keys[] = $this->normalizeKey($value);
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function inventoryDisplayName(Item $item): string
    {
        $name = trim((string) $item->name);
        if ($name !== '' && strcasecmp($name, (string) $item->code) !== 0) {
            return $name;
        }

        $description = trim((string) ($item->description ?? ''));
        if ($description !== '') {
            return $description;
        }

        return $name !== '' ? $name : trim((string) $item->code);
    }

    private function normalizeKey(?string $value): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
    }
}
