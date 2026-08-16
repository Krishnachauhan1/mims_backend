<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Models\WorkOrder;
use App\Models\Workflow;
use App\Services\InventoryCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesTrackingController extends Controller
{
    public function __construct(private InventoryCostService $inventoryCosts) {}

    public function summary(): JsonResponse
    {
        $inventoryByProduct = InventoryItem::query()
            ->where('type', 'finished_goods')
            ->where('status', 'available')
            ->get()
            ->groupBy(fn (InventoryItem $item) => $this->productKey($item->name))
            ->map(function ($items) {
                return [
                    'available_qty' => (int) $items->sum('quantity'),
                    'min_stock' => (int) $items->max('min_stock'),
                    'unit' => $items->first()->unit ?? 'pcs',
                ];
            });

        $catalog = $this->manufacturedProductCatalog();
        $rawMaterialKeys = $this->rawMaterialProductKeys();
        $openOrders = SalesOrder::query()
            ->whereIn('status', ['open', 'in_production', 'partial'])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (SalesOrder $order) => $this->productKey($order->product_name));

        $keys = collect($catalog->keys())
            ->merge($inventoryByProduct->keys())
            ->merge($openOrders->keys())
            ->unique()
            ->reject(fn (string $key) => $rawMaterialKeys->contains($key))
            ->values();

        $rows = $keys->map(function (string $key) use ($catalog, $inventoryByProduct, $openOrders) {
            $catalogRow = $catalog->get($key, []);
            $inventory = $inventoryByProduct->get($key, [
                'available_qty' => 0,
                'min_stock' => 0,
                'unit' => $catalogRow['unit'] ?? 'pcs',
            ]);

            $orders = $openOrders->get($key, collect());
            $orderedQty = (int) $orders->sum(
                fn (SalesOrder $order) => max(0, (int) $order->quantity - (int) ($order->fulfilled_qty ?? 0)),
            );
            $availableQty = (int) ($inventory['available_qty'] ?? 0);
            $minStock = (int) ($inventory['min_stock'] ?? 0);
            $shortfall = max(0, $orderedQty - $availableQty);
            $isLow = $availableQty <= $minStock && $minStock > 0;
            $needsProduction = $shortfall > 0 || $isLow;
            $manufacturingCost = $this->inventoryCosts->manufacturingUnitCostForProduct(
                $catalogRow['name'] ?? $orders->first()?->product_name ?? Str::title($key),
            );

            return [
                'product_key' => $key,
                'product_name' => $catalogRow['name'] ?? $orders->first()?->product_name ?? Str::title($key),
                'item_id' => $catalogRow['item_id'] ?? $orders->first()?->item_id,
                'unit' => $catalogRow['unit'] ?? $inventory['unit'] ?? 'pcs',
                'available_qty' => $availableQty,
                'min_stock' => $minStock,
                'ordered_qty' => $orderedQty,
                'shortfall' => $shortfall,
                'is_low_stock' => $isLow,
                'needs_production' => $needsProduction,
                'is_new' => empty($catalogRow) && $availableQty === 0,
                'is_sellable' => true,
                'manufacturing_cost' => $manufacturingCost,
                'stock_value' => $manufacturingCost !== null && $availableQty > 0
                    ? round($manufacturingCost * $availableQty, 2)
                    : null,
                'workflow_id' => $catalogRow['workflow_id'] ?? null,
                'workflow_name' => $catalogRow['workflow_name'] ?? null,
                'stages' => $catalogRow['stages'] ?? [],
                'source' => $catalogRow['source'] ?? ($availableQty > 0 ? 'inventory' : 'sales_order'),
                'open_orders' => $orders->map(fn (SalesOrder $order) => $this->formatOrder($order))->values(),
                'product' => $catalogRow,
            ];
        })->sortBy('product_name')->values();

        return response()->json([
            'products' => $rows,
            'stats' => [
                'total_products' => $rows->count(),
                'needs_production' => $rows->where('needs_production', true)->count(),
                'open_orders' => SalesOrder::query()->whereIn('status', ['open', 'in_production', 'partial'])->count(),
                'fulfilled_orders' => SalesOrder::query()->where('status', 'fulfilled')->count(),
                'total_sold_qty' => (int) SalesOrder::query()->sum('fulfilled_qty'),
                'total_available' => (int) $inventoryByProduct->sum('available_qty'),
            ],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = SalesOrder::query()->latest();

        if ($request->filled('status') && $request->string('status') !== 'all') {
            $query->where('status', $request->string('status'));
        }

        $rawMaterialKeys = $this->rawMaterialProductKeys();

        return response()->json(
            $query->limit(100)->get()
                ->reject(fn (SalesOrder $order) => $rawMaterialKeys->contains(
                    $this->productKey($order->product_name),
                ))
                ->map(fn (SalesOrder $order) => $this->formatOrder($order))
                ->values(),
        );
    }

    public function quickSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'item_id' => 'nullable|integer',
            'product_name' => 'required|string|max:255',
            'unit' => 'nullable|string|max:50',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'fulfill_now' => 'nullable|boolean',
        ]);

        $productKey = $this->productKey($data['product_name']);
        $this->assertSellableProduct($productKey);
        $available = $this->availableQtyForProduct($productKey);
        $qty = (int) $data['quantity'];
        $shortfall = max(0, $qty - $available);
        $fulfillNow = ($data['fulfill_now'] ?? true) && $shortfall === 0;

        $order = SalesOrder::query()->create([
            'order_number' => $this->nextOrderNumber(),
            'client_id' => $data['client_id'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'item_id' => $data['item_id'] ?? null,
            'product_name' => trim($data['product_name']),
            'unit' => $data['unit'] ?? 'pcs',
            'quantity' => $qty,
            'fulfilled_qty' => 0,
            'unit_price' => $data['unit_price'] ?? null,
            'available_at_order' => $available,
            'shortfall' => $shortfall,
            'status' => 'open',
            'order_date' => now()->toDateString(),
            'delivery_date' => $data['delivery_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->name,
        ]);

        if ($fulfillNow) {
            $order = $this->fulfillSalesOrder(
                $order,
                $qty,
                $request->user()?->id,
                $request->user()?->name,
            );
        }

        return response()->json([
            ...$this->formatOrder($order),
            'inventory_deducted' => $fulfillNow,
            'can_fulfill_now' => $available >= $qty,
        ], 201);
    }

    public function fulfillOrder(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if (in_array($salesOrder->status, ['fulfilled', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['This sales order cannot be fulfilled.'],
            ]);
        }

        $data = $request->validate([
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
        ]);

        $remaining = max(0, (int) $salesOrder->quantity - (int) $salesOrder->fulfilled_qty);
        if ($remaining < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['This sales order is already fully sold.'],
            ]);
        }

        $toSell = isset($data['quantity']) ? min((int) $data['quantity'], $remaining) : $remaining;

        if (isset($data['unit_price'])) {
            $salesOrder->unit_price = $data['unit_price'];
        }

        $order = $this->fulfillSalesOrder(
            $salesOrder,
            $toSell,
            $request->user()?->id,
            $request->user()?->name,
        );

        return response()->json($this->formatOrder($order));
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'item_id' => 'nullable|integer',
            'product_name' => 'required|string|max:255',
            'unit' => 'nullable|string|max:50',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $productKey = $this->productKey($data['product_name']);
        $this->assertSellableProduct($productKey);
        $available = (int) InventoryItem::query()
            ->where('type', 'finished_goods')
            ->get()
            ->filter(fn (InventoryItem $item) => $this->productKey($item->name) === $productKey)
            ->sum('quantity');

        $qty = (int) $data['quantity'];
        $shortfall = max(0, $qty - $available);

        $order = SalesOrder::query()->create([
            'order_number' => $this->nextOrderNumber(),
            'client_id' => $data['client_id'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'item_id' => $data['item_id'] ?? null,
            'product_name' => trim($data['product_name']),
            'unit' => $data['unit'] ?? 'pcs',
            'quantity' => $qty,
            'fulfilled_qty' => 0,
            'unit_price' => $data['unit_price'] ?? null,
            'available_at_order' => $available,
            'shortfall' => $shortfall,
            'status' => 'open',
            'order_date' => now()->toDateString(),
            'delivery_date' => $data['delivery_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->name,
        ]);

        return response()->json($this->formatOrder($order), 201);
    }

    public function updateOrderStatus(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:open,in_production,partial,fulfilled,cancelled',
        ]);

        $salesOrder->update(['status' => $data['status']]);

        return response()->json($this->formatOrder($salesOrder->fresh()));
    }

    private function manufacturedProductCatalog()
    {
        $byProduct = [];

        $orders = WorkOrder::query()
            ->whereNotNull('product')
            ->where('product', '!=', '')
            ->orderByDesc('updated_at')
            ->get();

        $rawMaterialKeys = $this->rawMaterialProductKeys();

        foreach ($orders->groupBy(fn (WorkOrder $order) => $this->productKey($order->product)) as $key => $productOrders) {
            if ($key === '' || $rawMaterialKeys->contains($key)) {
                continue;
            }
            $best = $productOrders->first(fn (WorkOrder $order) => ! empty($order->stages)) ?? $productOrders->first();
            $byProduct[$key] = $this->productOptionFromWorkOrder($best);
        }

        $finished = FinishedGood::query()
            ->whereNotNull('product_name')
            ->where('product_name', '!=', '')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($finished as $fg) {
            $key = $this->productKey($fg->product_name);
            if ($key === '' || isset($byProduct[$key]) || $rawMaterialKeys->contains($key)) {
                continue;
            }

            $stages = [];
            $workflowId = null;
            $workflowName = $fg->workflow;
            if (filled($fg->workflow)) {
                $workflow = Workflow::query()->where('name', $fg->workflow)->first();
                if ($workflow) {
                    $workflowId = $workflow->id;
                    $stages = $workflow->stages ?? [];
                }
            }

            $byProduct[$key] = [
                'name' => $fg->product_name,
                'product' => $fg->product_name,
                'unit' => $fg->unit ?? 'pcs',
                'item_id' => null,
                'client_id' => $fg->client_id,
                'workflow_id' => $workflowId,
                'workflow_name' => $workflowName,
                'stages' => $stages,
                'source' => 'finished_good',
            ];
        }

        $items = Item::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('category', 'product')
                    ->orWhere('category', 'finished_goods')
                    ->orWhere('category', 'finished goods');
            })
            ->get();

        foreach ($items as $item) {
            $key = $this->productKey($item->name);
            if ($key === '' || isset($byProduct[$key]) || $rawMaterialKeys->contains($key)) {
                continue;
            }
            $byProduct[$key] = [
                'name' => $item->name,
                'product' => $item->name,
                'item_id' => $item->id,
                'item_code' => $item->code,
                'unit' => $item->unit ?? 'pcs',
                'source' => 'item_master',
                'stages' => [],
            ];
        }

        return collect($byProduct)->reject(
            fn ($_, string $key) => $rawMaterialKeys->contains($key),
        );
    }

    private function rawMaterialProductKeys()
    {
        $fromInventory = InventoryItem::query()
            ->where('type', 'raw_material')
            ->pluck('name')
            ->map(fn (?string $name) => $this->productKey($name));

        $fromItems = Item::query()
            ->where(function ($query) {
                $query->where('category', 'raw_material')
                    ->orWhere('category', 'raw material');
            })
            ->pluck('name')
            ->map(fn (?string $name) => $this->productKey($name));

        return $fromInventory
            ->merge($fromItems)
            ->filter(fn (string $key) => $key !== '')
            ->unique()
            ->values();
    }

    private function assertSellableProduct(string $productKey): void
    {
        if ($this->rawMaterialProductKeys()->contains($productKey)) {
            throw ValidationException::withMessages([
                'product_name' => ['Raw materials cannot be sold from Sales Tracking.'],
            ]);
        }
    }

    private function productOptionFromWorkOrder(WorkOrder $order): array
    {
        $stages = $order->stages ?? [];
        if (empty($stages) && $order->workflow_id) {
            $workflow = Workflow::query()->find($order->workflow_id);
            $stages = $workflow?->stages ?? [];
        }

        return [
            'name' => $order->product,
            'product' => $order->product,
            'item_id' => $order->item_id,
            'item_code' => $order->item_code,
            'unit' => $order->unit ?? 'pcs',
            'client_id' => $order->client_id,
            'workflow_id' => $order->workflow_id,
            'workflow_name' => $order->workflow_name,
            'route_card_no' => $order->route_card_no,
            'stages' => $stages,
            'source' => 'work_order',
        ];
    }

    private function fulfillSalesOrder(
        SalesOrder $order,
        int $quantity,
        ?int $userId,
        ?string $userName,
    ): SalesOrder {
        $remaining = max(0, (int) $order->quantity - (int) $order->fulfilled_qty);
        $toSell = min($quantity, $remaining);

        if ($toSell < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['Nothing left to sell on this order.'],
            ]);
        }

        $deducted = $this->deductFinishedGoodsInventory(
            $order->product_name,
            $toSell,
            $order->client_name,
            $userId,
            $order->id,
            $order->order_number,
        );

        if ($deducted < $toSell) {
            throw ValidationException::withMessages([
                'quantity' => [
                    "Insufficient stock. Available: {$deducted}, requested: {$toSell}.",
                ],
            ]);
        }

        $order->fulfilled_qty = (int) $order->fulfilled_qty + $deducted;
        $order->sold_at = now();
        $order->sold_by = $userName;
        $order->status = $order->fulfilled_qty >= $order->quantity ? 'fulfilled' : 'partial';
        $order->save();

        if ($order->client_id) {
            Client::query()
                ->where('id', $order->client_id)
                ->increment('total_orders');
        }

        return $order->fresh();
    }

    private function deductFinishedGoodsInventory(
        string $productName,
        int $quantity,
        ?string $clientName,
        ?int $userId,
        ?int $salesOrderId,
        ?string $orderNumber,
    ): int {
        $productKey = $this->productKey($productName);
        $remaining = $quantity;
        $deducted = 0;

        DB::transaction(function () use (
            $productKey,
            &$remaining,
            &$deducted,
            $clientName,
            $userId,
            $salesOrderId,
            $orderNumber,
            $productName,
        ): void {
            $items = InventoryItem::query()
                ->where('type', 'finished_goods')
                ->where('status', 'available')
                ->where('quantity', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (InventoryItem $item) => $this->productKey($item->name) === $productKey);

            foreach ($items as $item) {
                if ($remaining < 1) {
                    break;
                }

                $take = min($remaining, (int) $item->quantity);
                if ($take < 1) {
                    continue;
                }

                $item->update(['quantity' => $item->quantity - $take]);

                if (filled($item->batch_number)) {
                    $finished = FinishedGood::query()
                        ->where('batch_number', $item->batch_number)
                        ->first();

                    if ($finished !== null) {
                        $finished->update([
                            'stock' => max(0, (int) $finished->stock - $take),
                            'dispatched' => (int) $finished->dispatched + $take,
                            'status' => max(0, (int) $finished->stock - $take) > 0
                                ? $finished->status
                                : 'Dispatched',
                        ]);
                    }
                }

                $transactionData = [
                    'type' => 'checkout',
                    'material_name' => $productName,
                    'destination' => $clientName ?: 'Customer Sale',
                    'quantity' => $take,
                    'unit' => $item->unit ?? 'pcs',
                    'batch_number' => $item->batch_number,
                    'notes' => 'Sales order '.($orderNumber ?? '#'.$salesOrderId),
                    'status' => 'completed',
                    'user_id' => $userId,
                ];

                if (Schema::hasColumn('inventory_transactions', 'flow_source')) {
                    $transactionData['flow_source'] = 'sales';
                }

                InventoryTransaction::query()->create($transactionData);

                $remaining -= $take;
                $deducted += $take;
            }
        });

        return $deducted;
    }

    private function availableQtyForProduct(string $productKey): int
    {
        return (int) InventoryItem::query()
            ->where('type', 'finished_goods')
            ->where('status', 'available')
            ->get()
            ->filter(fn (InventoryItem $item) => $this->productKey($item->name) === $productKey)
            ->sum('quantity');
    }

    private function formatOrder(SalesOrder $order): array
    {
        $fulfilledQty = (int) ($order->fulfilled_qty ?? 0);
        $remainingQty = max(0, (int) $order->quantity - $fulfilledQty);
        $availableNow = $this->availableQtyForProduct($this->productKey($order->product_name));
        $manufacturingCost = $this->inventoryCosts->manufacturingUnitCostForProduct($order->product_name);
        $unitPrice = $order->unit_price !== null ? (float) $order->unit_price : null;
        $orderQty = (int) $order->quantity;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'item_id' => $order->item_id,
            'product_name' => $order->product_name,
            'unit' => $order->unit,
            'quantity' => $order->quantity,
            'fulfilled_qty' => $fulfilledQty,
            'remaining_qty' => $remainingQty,
            'unit_price' => $order->unit_price,
            'manufacturing_cost' => $manufacturingCost,
            'total_manufacturing_cost' => $manufacturingCost !== null
                ? round($manufacturingCost * $orderQty, 2)
                : null,
            'line_revenue' => $unitPrice !== null
                ? round($unitPrice * $orderQty, 2)
                : null,
            'gross_profit' => ($unitPrice !== null && $manufacturingCost !== null)
                ? round(($unitPrice - $manufacturingCost) * $orderQty, 2)
                : null,
            'is_sellable' => ! $this->rawMaterialProductKeys()->contains(
                $this->productKey($order->product_name),
            ),
            'available_at_order' => $order->available_at_order,
            'available_now' => $availableNow,
            'shortfall' => $order->shortfall,
            'can_fulfill' => $remainingQty > 0 && $availableNow > 0
                && in_array($order->status, ['open', 'in_production', 'partial'], true),
            'can_fulfill_full' => $remainingQty > 0 && $availableNow >= $remainingQty
                && in_array($order->status, ['open', 'in_production', 'partial'], true),
            'status' => $order->status,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'delivery_date' => $order->delivery_date?->format('Y-m-d'),
            'sold_at' => $order->sold_at?->format('Y-m-d H:i'),
            'notes' => $order->notes,
            'created_by' => $order->created_by,
            'sold_by' => $order->sold_by,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function nextOrderNumber(): string
    {
        $year = now()->year;
        $prefix = "SO-$year-";
        $maxSeq = SalesOrder::query()
            ->where('order_number', 'like', $prefix.'%')
            ->get()
            ->map(function (SalesOrder $order) use ($prefix) {
                return (int) Str::after($order->order_number, $prefix);
            })
            ->max();

        return $prefix.str_pad((string) (($maxSeq ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }

    private function productKey(?string $name): string
    {
        return Str::lower(trim((string) $name));
    }
}
