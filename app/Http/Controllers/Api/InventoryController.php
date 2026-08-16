<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Services\InventoryCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::query()->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        $items = $query->get();
        $costService = app(InventoryCostService::class);
        $costService->warmFor($items);

        return response()->json(
            $items->map(fn (InventoryItem $item) => $this->format($item, $costService)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $item = InventoryItem::create($this->validatedPayload($request));

        return response()->json($this->format($item, app(InventoryCostService::class)), 201);
    }

    public function update(Request $request, InventoryItem $inventory): JsonResponse
    {
        $inventory->update($this->validatedPayload($request, $inventory));

        return response()->json($this->format($inventory->fresh(), app(InventoryCostService::class)));
    }

    public function adjust(Request $request, InventoryItem $inventory): JsonResponse
    {
        $data = $request->validate([
            'quantity' => 'required|integer',
        ]);

        $inventory->update([
            'quantity' => max(0, $inventory->quantity + $data['quantity']),
        ]);

        return response()->json($this->format($inventory->fresh(), app(InventoryCostService::class)));
    }

    public static function receivePurchaseOrderItem(array $item, string $poNumber): InventoryItem
    {
        $name = $item['item_name'];
        $unit = $item['unit'] ?? 'pcs';
        $quantity = (int) ($item['quantity'] ?? 0);
        $skuBase = Str::upper(Str::slug($name, '-'));
        $sku = Str::limit($skuBase, 40, '').'-'.Str::upper(Str::slug($poNumber, ''));

        $inventory = InventoryItem::firstOrNew([
            'name' => $name,
            'batch_number' => $poNumber,
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
        $costService ??= app(InventoryCostService::class);
        $costService->warmFor(collect([$item]));

        return [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'type' => $item->type,
            'status' => $item->status,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'batch_number' => $item->batch_number,
            'location' => $item->location,
            'min_stock' => $item->min_stock,
            ...$costService->costFieldsFor($item),
        ];
    }
}
