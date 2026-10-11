<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Services\CatalogInventorySyncService;
use App\Services\InventoryCostService;
use App\Support\InventoryStockStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->filled('type') ? $request->string('type')->toString() : null;

        $query = InventoryItem::query()->latest();

        // Finished goods in QC / packing hold are not sellable stock yet.
        $query->where(function ($builder): void {
            $builder
                ->where('type', '!=', 'finished_goods')
                ->orWhereNotIn('status', ['pending_qc', 'pending_packing']);
        });

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('quantity', '<=', 'min_stock')
                ->where('min_stock', '>', 0);
        }

        try {
            $items = $query->get();
            $costService = $this->resolveCostService();
            if ($costService !== null) {
                $costService->warmFor($items);
            }

            $rows = $items->map(fn (InventoryItem $item) => $this->format($item, $costService));

            if ($type !== 'wip' && class_exists(CatalogInventorySyncService::class)) {
                try {
                    $virtual = app(CatalogInventorySyncService::class)
                        ->virtualRowsForMissing($items, $type);

                    if ($request->boolean('low_stock')) {
                        $virtual = array_values(array_filter(
                            $virtual,
                            fn (array $row) => (int) ($row['min_stock'] ?? 0) > 0
                                && (int) ($row['quantity'] ?? 0) <= (int) ($row['min_stock'] ?? 0),
                        ));
                    }

                    if ($virtual !== []) {
                        $rows = $rows->concat($virtual);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return response()->json(array_values($rows->all()));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not load inventory',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $item = InventoryItem::create($this->validatedPayload($request));

        return response()->json($this->format($item, app(InventoryCostService::class)), 201);
    }

    public function update(Request $request, InventoryItem $inventory): JsonResponse
    {
        $inventory->fill($this->validatedPayload($request, $inventory));
        $inventory->status = InventoryStockStatus::forItem($inventory);
        $inventory->save();

        return response()->json($this->format($inventory->fresh(), app(InventoryCostService::class)));
    }

    public function adjust(Request $request, InventoryItem $inventory): JsonResponse
    {
        $data = $request->validate([
            'quantity' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $previous = (int) $inventory->quantity;
        $inventory->quantity = max(0, $previous + (int) $data['quantity']);
        $inventory->status = InventoryStockStatus::forItem($inventory);
        $inventory->save();

        return response()->json([
            'success' => true,
            'message' => 'Stock adjusted successfully',
            'data' => $this->format($inventory->fresh(), app(InventoryCostService::class)),
            'previous_quantity' => $previous,
            'adjustment' => (int) $data['quantity'],
        ]);
    }

    public static function receivePurchaseOrderItem(array $item, string $poNumber): InventoryItem
    {
        $name = $item['item_name'];
        $unit = $item['unit'] ?? 'pcs';
        $quantity = (int) ($item['quantity'] ?? 0);
        $skuBase = Str::upper(Str::slug($name, '-'));
        $sku = Str::limit($skuBase, 40, '').'-'.Str::upper(Str::slug($poNumber, ''));

        $inventory = InventoryItem::query()->firstOrNew([
            'name' => $name,
            'batch_number' => $poNumber,
            'type' => 'raw_material',
            'status' => 'available',
        ]);

        $inventory->fill([
            'sku' => $inventory->sku ?: Str::limit($sku, 50, ''),
            'type' => 'raw_material',
            'status' => 'available',
            'unit' => $unit,
            'location' => $inventory->location ?: 'Gate Entry',
            'min_stock' => $inventory->min_stock ?? 0,
        ]);

        if ($inventory->exists) {
            $inventory->quantity = (int) $inventory->quantity + $quantity;
        } else {
            $inventory->quantity = $quantity;
        }

        $inventory->save();

        return $inventory;
    }

    public static function holdRawMaterialForQuality(array $item, string $poNumber): InventoryItem
    {
        $name = $item['item_name'];
        $unit = $item['unit'] ?? 'pcs';
        $quantity = (int) ($item['quantity'] ?? 0);
        $skuBase = Str::upper(Str::slug($name, '-'));
        $sku = Str::limit($skuBase, 40, '').'-'.Str::upper(Str::slug($poNumber, ''));

        $inventory = InventoryItem::query()->firstOrNew([
            'name' => $name,
            'batch_number' => $poNumber,
            'type' => 'raw_material',
            'status' => 'pending_qc',
        ]);

        $inventory->fill([
            'sku' => $inventory->sku ?: Str::limit($sku, 50, '').'-QC',
            'type' => 'raw_material',
            'status' => 'pending_qc',
            'unit' => $unit,
            'location' => 'Quality Hold',
            'min_stock' => $inventory->min_stock ?? 0,
        ]);

        if ($inventory->exists) {
            $inventory->quantity = (int) $inventory->quantity + $quantity;
        } else {
            $inventory->quantity = $quantity;
        }

        $inventory->save();

        return $inventory;
    }

    public static function releaseRawMaterialFromQuality(
        string $itemName,
        string $poNumber,
        int $quantity,
        string $unit = 'pcs',
    ): void {
        if ($quantity < 1) {
            return;
        }

        $hold = InventoryItem::query()
            ->where('name', $itemName)
            ->where('batch_number', $poNumber)
            ->where('type', 'raw_material')
            ->where('status', 'pending_qc')
            ->first();

        if ($hold !== null) {
            $remaining = max(0, (int) $hold->quantity - $quantity);
            if ($remaining === 0) {
                $hold->delete();
            } else {
                $hold->update(['quantity' => $remaining]);
            }
        }

        self::receivePurchaseOrderItem([
            'item_name' => $itemName,
            'quantity' => $quantity,
            'unit' => $unit,
        ], $poNumber);
    }

    public static function reduceRawMaterialQualityHold(
        string $itemName,
        string $poNumber,
        int $quantity,
    ): void {
        if ($quantity < 1) {
            return;
        }

        $hold = InventoryItem::query()
            ->where('name', $itemName)
            ->where('batch_number', $poNumber)
            ->where('type', 'raw_material')
            ->where('status', 'pending_qc')
            ->first();

        if ($hold === null) {
            return;
        }

        $remaining = max(0, (int) $hold->quantity - $quantity);
        if ($remaining === 0) {
            $hold->delete();
        } else {
            $hold->update(['quantity' => $remaining]);
        }
    }

    public static function receiveFinishedGoodFromBatch(
        string $productName,
        string $batchNumber,
        int $quantity,
        string $unit = 'pcs',
        ?string $location = null,
    ): InventoryItem {
        return self::upsertFinishedGoodInventory(
            $productName,
            $batchNumber,
            $quantity,
            $unit,
            'available',
            $location ?? 'Finished Goods Warehouse',
        );
    }

    public static function holdFinishedGoodForQuality(
        string $productName,
        string $batchNumber,
        int $quantity,
        string $unit = 'pcs',
    ): InventoryItem {
        return self::upsertFinishedGoodInventory(
            $productName,
            $batchNumber,
            $quantity,
            $unit,
            'pending_qc',
            'Quality Inspection',
        );
    }

    public static function releaseFinishedGoodFromQuality(
        string $productName,
        string $batchNumber,
        int $quantity,
        string $unit = 'pcs',
    ): InventoryItem {
        return self::upsertFinishedGoodInventory(
            $productName,
            $batchNumber,
            $quantity,
            $unit,
            'available',
            'Finished Goods Warehouse',
        );
    }

    /** After production QC pass; stock stays hidden from inventory until packing completes. */
    public static function holdFinishedGoodForPacking(
        string $productName,
        string $batchNumber,
        int $quantity,
        string $unit = 'pcs',
    ): InventoryItem {
        return self::upsertFinishedGoodInventory(
            $productName,
            $batchNumber,
            $quantity,
            $unit,
            'pending_packing',
            'Packing Queue',
        );
    }

    public static function markFinishedGoodPackingRejected(
        string $productName,
        string $batchNumber,
    ): void {
        InventoryItem::query()
            ->where('type', 'finished_goods')
            ->where('name', $productName)
            ->where('batch_number', $batchNumber)
            ->update([
                'status' => 'rejected',
                'quantity' => 0,
            ]);
    }

    public static function syncFromFinishedGood(FinishedGood $good): InventoryItem
    {
        $productName = trim((string) $good->product_name);
        $batchNumber = self::normalizeImportPlaceholder((string) ($good->batch_number ?? ''));
        if ($batchNumber === '' || strcasecmp($batchNumber, 'no') === 0) {
            $batchNumber = 'AUTO-'.Str::upper(Str::slug($productName, '-'));
            $batchNumber = Str::limit($batchNumber, 64, '') ?: ('FG-'.str_pad((string) $good->id, 6, '0', STR_PAD_LEFT));
            if ($good->batch_number !== $batchNumber) {
                $good->update(['batch_number' => $batchNumber]);
            }
        }

        $quantity = max(0, (int) ($good->stock ?? $good->quantity ?? 0));
        $unit = self::normalizeImportPlaceholder((string) ($good->unit ?? '')) ?: 'pcs';
        $location = self::normalizeImportPlaceholder((string) ($good->warehouse ?? $good->location ?? ''))
            ?: 'Finished Goods Warehouse';
        $status = self::inventoryStatusForFinishedGood($good);

        $skuBase = Str::upper(Str::slug($productName, '-'));
        $generatedSku = Str::limit($skuBase, 40, '').'-FG-'.Str::upper(Str::slug($batchNumber, ''));
        $generatedSku = Str::limit($generatedSku, 50, '');

        $inventory = InventoryItem::query()->firstOrNew([
            'name' => $productName,
            'batch_number' => $batchNumber,
            'type' => 'finished_goods',
        ]);

        $sku = $generatedSku;
        if ($inventory->exists && self::isUsableInventorySku((string) $inventory->sku)) {
            $sku = (string) $inventory->sku;
        } elseif (self::isUsableInventorySku(self::normalizeImportPlaceholder((string) ($good->sku ?? '')))) {
            $candidate = self::normalizeImportPlaceholder((string) $good->sku);
            $skuTaken = InventoryItem::query()
                ->where('sku', $candidate)
                ->when($inventory->exists, fn ($query) => $query->where('id', '!=', $inventory->id))
                ->exists();
            if (! $skuTaken) {
                $sku = $candidate;
            }
        }

        $inventory->fill([
            'sku' => $sku,
            'type' => 'finished_goods',
            'status' => $status,
            'unit' => $unit,
            'location' => $location,
            'min_stock' => $inventory->min_stock ?? 0,
            'quantity' => $quantity,
        ]);

        $inventory->save();

        return $inventory;
    }

    private static function normalizeImportPlaceholder(string $value): string
    {
        $value = trim(str_replace("\n", ' ', $value));
        if ($value === '') {
            return '';
        }

        $normalized = strtolower(rtrim($value, '.'));

        if (in_array($normalized, ['no', 'n/a', 'na', 'nil', 'null', 'none', '-', '—', '___'], true)) {
            return '';
        }

        return $value;
    }

    private static function isUsableInventorySku(string $sku): bool
    {
        $sku = self::normalizeImportPlaceholder($sku);

        return $sku !== '';
    }

    private static function inventoryStatusForFinishedGood(FinishedGood $good): string
    {
        $quality = strtolower(trim((string) ($good->quality_status ?? '')));
        if (in_array($quality, ['pending', 'pending_qc'], true)) {
            return 'pending_qc';
        }
        if (in_array($quality, ['rejected', 'failed'], true)) {
            return 'rejected';
        }

        $status = strtolower(trim((string) ($good->status ?? '')));
        if (str_contains($status, 'packing')) {
            return 'pending_packing';
        }
        if (str_contains($status, 'pending') || str_contains($status, 'quality')) {
            return 'pending_qc';
        }
        if (str_contains($status, 'reject') || str_contains($status, 'fail')) {
            return 'rejected';
        }
        if (str_contains($status, 'dispatch')) {
            return 'available';
        }

        return 'available';
    }

    private static function upsertFinishedGoodInventory(
        string $productName,
        string $batchNumber,
        int $quantity,
        string $unit,
        string $status,
        string $location,
    ): InventoryItem {
        $skuBase = Str::upper(Str::slug($productName, '-'));
        $sku = Str::limit($skuBase, 40, '').'-FG-'.Str::upper(Str::slug($batchNumber, ''));

        $inventory = InventoryItem::query()->firstOrNew([
            'name' => $productName,
            'batch_number' => $batchNumber,
            'type' => 'finished_goods',
        ]);

        $inventory->fill([
            'sku' => $inventory->sku ?: Str::limit($sku, 50, ''),
            'type' => 'finished_goods',
            'status' => $status,
            'unit' => $unit,
            'location' => $location,
            'min_stock' => $inventory->min_stock ?? 0,
            'quantity' => max(1, $quantity),
        ]);

        $inventory->save();

        return $inventory;
    }

    private function validatedPayload(Request $request, ?InventoryItem $inventory = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:255|unique:inventory_items,sku,'.($inventory?->id ?? 'NULL'),
            'type' => 'required|string|max:100',
            'status' => 'nullable|string|max:100',
            'quantity' => 'required|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'batch_number' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'min_stock' => 'nullable|integer|min:0',
        ]);
    }

    private function format(InventoryItem $item, ?InventoryCostService $costService = null): array
    {
        $base = [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'type' => $item->type,
            'status' => InventoryStockStatus::forItem($item),
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'batch_number' => $item->batch_number,
            'location' => $item->location,
            'min_stock' => $item->min_stock,
        ];

        $costService ??= $this->resolveCostService();
        if ($costService === null) {
            return [
                ...$base,
                'item_code' => null,
                'description' => null,
                'catalog_name' => null,
                'unit_cost' => null,
                'total_value' => null,
                'cost_type' => null,
                'manufacturing_cost' => null,
            ];
        }

        try {
            $costService->warmFor(collect([$item]));

            return [
                ...$base,
                ...$costService->catalogDisplayFor($item),
                ...$costService->costFieldsFor($item),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                ...$base,
                'item_code' => null,
                'description' => null,
                'catalog_name' => null,
                'unit_cost' => null,
                'total_value' => null,
                'cost_type' => null,
                'manufacturing_cost' => null,
            ];
        }
    }

    private function resolveCostService(): ?InventoryCostService
    {
        if (! class_exists(InventoryCostService::class)) {
            return null;
        }

        return app(InventoryCostService::class);
    }
}
