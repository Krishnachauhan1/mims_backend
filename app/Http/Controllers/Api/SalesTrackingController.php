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

    public function summary(Request $request): JsonResponse
    {
        $inventoryByProduct = $this->availableInventoryByProductKey();
        $finishedGoodsStockByProduct = $this->sellableFinishedGoodsStockByProductKey();

        $catalog = $this->manufacturedProductCatalog();
        $rawMaterialKeys = $this->rawMaterialProductKeys();
        $openOrderLines = $this->openOrderLinesByProduct();

        $keys = collect($catalog->keys())
            ->merge($inventoryByProduct->keys())
            ->merge($finishedGoodsStockByProduct->keys())
            ->merge($openOrderLines->keys())
            ->unique()
            ->reject(fn (string $key) => $this->isRawMaterialCatalogKey($key, $rawMaterialKeys))
            ->values();

        $rows = $keys->map(function (string $key) use (
            $catalog,
            $inventoryByProduct,
            $finishedGoodsStockByProduct,
            $openOrderLines,
        ) {
            $catalogRow = $catalog->get($key, []);
            $inventory = $inventoryByProduct->get($key, [
                'available_qty' => 0,
                'min_stock' => 0,
                'unit' => $catalogRow['unit'] ?? 'pcs',
            ]);

            $orderLines = $openOrderLines->get($key, collect());
            $orderedQty = (int) $orderLines->sum(function (array $entry) {
                $line = $entry['line'];

                return max(0, (int) $line['quantity'] - (int) ($line['fulfilled_qty'] ?? 0));
            });
            $availableQty = max(
                (int) ($inventory['available_qty'] ?? 0),
                (int) ($finishedGoodsStockByProduct->get($key, 0)),
            );
            $minStock = (int) ($inventory['min_stock'] ?? 0);
            $shortfall = max(0, $orderedQty - $availableQty);
            $isLow = $availableQty <= $minStock && $minStock > 0;
            $needsProduction = $shortfall > 0 || $isLow;
            $sampleLine = $orderLines->first()['line'] ?? null;
            $manufacturingCost = $this->inventoryCosts->manufacturingUnitCostForProduct(
                $catalogRow['name'] ?? $sampleLine['product_name'] ?? Str::title($key),
            );

            return [
                'product_key' => $key,
                'product_name' => $catalogRow['name'] ?? $sampleLine['product_name'] ?? $this->displayNameForCatalogKey($key),
                'item_id' => $catalogRow['item_id'] ?? $sampleLine['item_id'] ?? null,
                'item_code' => $catalogRow['item_code'] ?? $sampleLine['item_code'] ?? null,
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
                'open_orders' => $orderLines
                    ->map(fn (array $entry) => $this->formatOrder($entry['order']))
                    ->unique('id')
                    ->values(),
                'product' => $catalogRow,
            ];
        })->sortBy(function (array $row) {
            $qty = (int) ($row['available_qty'] ?? 0);

            return [
                $qty > 0 ? 0 : 1,
                -$qty,
                strtolower((string) ($row['product_name'] ?? '')),
            ];
        })->values();

        $stats = [
            'total_products' => $rows->count(),
            'needs_production' => $rows->where('needs_production', true)->count(),
            'open_orders' => SalesOrder::query()->whereIn('status', ['open', 'in_production', 'partial'])->count(),
            'fulfilled_orders' => SalesOrder::query()->where('status', 'fulfilled')->count(),
            'total_sold_qty' => (int) SalesOrder::query()->sum('fulfilled_qty'),
            'total_available' => (int) $inventoryByProduct->sum('available_qty'),
        ];

        $filtered = $rows;

        if ($request->filled('search')) {
            $term = strtolower(trim($request->string('search')->toString()));
            $filtered = $filtered
                ->filter(function (array $row) use ($term) {
                    $haystack = strtolower(implode(' ', array_filter([
                        $row['product_name'] ?? '',
                        $row['item_code'] ?? '',
                    ])));

                    return str_contains($haystack, $term);
                })
                ->values();
        }

        $filter = $request->string('filter', 'all')->toString();
        if ($filter === 'needs_production') {
            $filtered = $filtered->where('needs_production', true)->values();
        } elseif ($filter === 'low_stock') {
            $filtered = $filtered->where('is_low_stock', true)->values();
        } elseif ($filter === 'new') {
            $filtered = $filtered->where('is_new', true)->values();
        }

        $perPage = min(100, max(5, (int) $request->integer('per_page', 20)));
        $page = max(1, (int) $request->integer('page', 1));
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $paginated = $filtered->slice($offset, $perPage)->values();

        return response()->json([
            'products' => $paginated,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : min($offset + $perPage, $total),
            ],
            'stats' => $stats,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $rawMaterialKeys = $this->rawMaterialProductKeys();

        $query = SalesOrder::query()->latest();

        if ($request->filled('status') && $request->string('status') !== 'all') {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('product_name', 'like', $term)
                    ->orWhere('order_number', 'like', $term)
                    ->orWhere('client_name', 'like', $term)
                    ->orWhere('items', 'like', $term);
            });
        }

        if ($rawMaterialKeys->isNotEmpty()) {
            $query->whereNotIn(DB::raw('LOWER(TRIM(product_name))'), $rawMaterialKeys->all());
        }

        $perPage = min(100, max(5, (int) $request->integer('per_page', 20)));
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (SalesOrder $order) => $this->formatOrder($order))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function quickSale(Request $request): JsonResponse
    {
        $data = $this->validateSalePayload($request);
        $items = $this->buildOrderItems($data);
        $totalShortfall = (int) collect($items)->sum('shortfall');
        $fulfillNow = ($data['fulfill_now'] ?? true) && $totalShortfall === 0;

        $rollup = $this->rollupFromItems($items);
        $order = SalesOrder::query()->create([
            'order_number' => $this->nextOrderNumber(),
            'client_id' => $data['client_id'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'items' => $items,
            ...$rollup,
            'status' => 'open',
            'order_date' => now()->toDateString(),
            'delivery_date' => $data['delivery_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->name,
        ]);

        if ($fulfillNow) {
            $order = $this->fulfillSalesOrder(
                $order,
                null,
                $request->user()?->id,
                $request->user()?->name,
            );
        }

        return response()->json([
            ...$this->formatOrder($order),
            'inventory_deducted' => $fulfillNow,
            'can_fulfill_now' => $totalShortfall === 0,
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
            'line_index' => 'nullable|integer|min:0',
        ]);

        $items = $this->orderItems($salesOrder);
        if ($items === []) {
            throw ValidationException::withMessages([
                'quantity' => ['This sales order has no line items.'],
            ]);
        }

        $lineIndex = isset($data['line_index']) ? (int) $data['line_index'] : null;
        if ($lineIndex !== null && ! isset($items[$lineIndex])) {
            throw ValidationException::withMessages([
                'line_index' => ['Invalid line index for this sales order.'],
            ]);
        }

        $remainingTotal = $this->remainingQtyForItems($items);
        if ($remainingTotal < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['This sales order is already fully sold.'],
            ]);
        }

        if (isset($data['unit_price']) && count($items) === 1 && $lineIndex === null) {
            $items[0]['unit_price'] = (float) $data['unit_price'];
            $salesOrder->items = $items;
        }

        $order = $this->fulfillSalesOrder(
            $salesOrder,
            $data['quantity'] ?? null,
            $request->user()?->id,
            $request->user()?->name,
            $lineIndex,
        );

        return response()->json($this->formatOrder($order));
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $this->validateSalePayload($request, requireFulfillFlag: false);
        $items = $this->buildOrderItems($data);
        $rollup = $this->rollupFromItems($items);

        $order = SalesOrder::query()->create([
            'order_number' => $this->nextOrderNumber(),
            'client_id' => $data['client_id'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'items' => $items,
            ...$rollup,
            'status' => 'open',
            'order_date' => now()->toDateString(),
            'delivery_date' => $data['delivery_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->name,
        ]);

        return response()->json($this->formatOrder($order), 201);
    }

    public function syncInventory(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if (! in_array($salesOrder->status, ['fulfilled', 'partial'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Inventory sync is only available for sold or partially sold orders.'],
            ]);
        }

        $deducted = $this->syncInventoryDeductions(
            $salesOrder,
            $request->user()?->id,
            $request->user()?->name,
        );

        if ($deducted < 1) {
            throw ValidationException::withMessages([
                'inventory' => ['No pending inventory deduction found for this order.'],
            ]);
        }

        return response()->json([
            ...$this->formatOrder($salesOrder->fresh()),
            'inventory_deducted' => $deducted,
            'message' => "Reduced inventory by {$deducted} unit(s).",
        ]);
    }

    public function updateOrderStatus(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:open,in_production,partial,fulfilled,cancelled',
        ]);

        if ($data['status'] === 'fulfilled') {
            throw ValidationException::withMessages([
                'status' => ['Use the sell / fulfill action to mark an order as sold and reduce inventory.'],
            ]);
        }

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

        foreach ($orders->groupBy(fn (WorkOrder $order) => $this->canonicalProductKey($order->product, $order->item_id)) as $key => $productOrders) {
            if ($key === '' || $this->isRawMaterialCatalogKey($key, $rawMaterialKeys)) {
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
            $masterItem = $this->resolveItemByNameOrCode($fg->product_name);
            $key = $this->canonicalProductKey($fg->product_name, $masterItem?->id);
            if ($key === '' || $this->isRawMaterialCatalogKey($key, $rawMaterialKeys)) {
                continue;
            }

            if (isset($byProduct[$key])) {
                $this->mergeCatalogItemMetadata($byProduct[$key], $masterItem, $fg->product_name);

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

            $displayName = $this->sellableDisplayName($masterItem, $fg->product_name);

            $byProduct[$key] = [
                'name' => $displayName,
                'product' => $displayName,
                'unit' => $fg->unit ?? 'pcs',
                'item_id' => $masterItem?->id,
                'item_code' => $masterItem?->code,
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
                $query->whereRaw('LOWER(TRIM(category)) IN (?, ?, ?)', [
                    'product',
                    'finished_goods',
                    'finished goods',
                ])->orWhereNull('category')
                    ->orWhereRaw("TRIM(category) = ''");
            })
            ->get();

        foreach ($items as $item) {
            $key = $this->canonicalProductKey($item->name, $item->id);
            if ($key === '' || $this->isRawMaterialCatalogKey($key, $rawMaterialKeys)) {
                continue;
            }

            if (isset($byProduct[$key])) {
                $this->mergeCatalogItemMetadata($byProduct[$key], $item, $item->name);

                continue;
            }

            $displayName = $this->sellableDisplayName($item, $item->name);
            $byProduct[$key] = [
                'name' => $displayName,
                'product' => $displayName,
                'item_id' => $item->id,
                'item_code' => $item->code,
                'unit' => $item->unit ?? 'pcs',
                'source' => 'item_master',
                'stages' => [],
            ];
        }

        return collect($byProduct)->reject(
            fn ($_, string $key) => $this->isRawMaterialCatalogKey($key, $rawMaterialKeys),
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

        $masterItem = $order->item_id
            ? Item::query()->find($order->item_id)
            : $this->resolveItemByNameOrCode($order->product, $order->item_id);
        $displayName = $this->sellableDisplayName($masterItem, $order->product);

        return [
            'name' => $displayName,
            'product' => $displayName,
            'item_id' => $order->item_id ?: $masterItem?->id,
            'item_code' => $order->item_code ?: $masterItem?->code,
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
        ?int $quantity,
        ?int $userId,
        ?string $userName,
        ?int $lineIndex = null,
    ): SalesOrder {
        $items = $this->orderItems($order);
        if ($items === []) {
            throw ValidationException::withMessages([
                'quantity' => ['Nothing left to sell on this order.'],
            ]);
        }

        $anySold = false;
        $singleLine = count($items) === 1 && $lineIndex === null;

        foreach ($items as $index => &$line) {
            if ($lineIndex !== null && $index !== $lineIndex) {
                continue;
            }

            $remaining = max(0, (int) $line['quantity'] - (int) ($line['fulfilled_qty'] ?? 0));
            if ($remaining < 1) {
                continue;
            }

            $toSell = $remaining;
            if ($singleLine && $quantity !== null) {
                $toSell = min($quantity, $remaining);
            }

            if ($toSell < 1) {
                continue;
            }

            $deducted = $this->deductFinishedGoodsInventory(
                $line,
                $toSell,
                $order->client_name,
                $userId,
                $order->id,
                $order->order_number,
            );

            if ($deducted < 1) {
                continue;
            }

            $line['fulfilled_qty'] = (int) ($line['fulfilled_qty'] ?? 0) + $deducted;
            $anySold = true;

            if ($singleLine && $quantity !== null) {
                break;
            }
        }
        unset($line);

        if (! $anySold) {
            throw ValidationException::withMessages([
                'quantity' => ['Insufficient stock to fulfill this order.'],
            ]);
        }

        $rollup = $this->rollupFromItems($items);
        $order->items = $items;
        $order->fill($rollup);
        $order->sold_at = now();
        $order->sold_by = $userName;
        $order->status = $this->resolveOrderStatus($items);
        $order->save();

        if ($order->client_id) {
            Client::query()
                ->where('id', $order->client_id)
                ->increment('total_orders');
        }

        return $order->fresh();
    }

    private function deductFinishedGoodsInventory(
        array $line,
        int $quantity,
        ?string $clientName,
        ?int $userId,
        ?int $salesOrderId,
        ?string $orderNumber,
    ): int {
        $productName = trim((string) ($line['product_name'] ?? ''));
        $remaining = $quantity;
        $deducted = 0;

        DB::transaction(function () use (
            $line,
            &$remaining,
            &$deducted,
            $clientName,
            $userId,
            $salesOrderId,
            $orderNumber,
            $productName,
        ): void {
            $items = $this->resolveInventoryItemsForLine($line, lock: true)
                ->filter(fn (InventoryItem $item) => (int) $item->quantity > 0)
                ->values();

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
                    'material_name' => $productName !== '' ? $productName : $item->name,
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

    private function syncInventoryDeductions(
        SalesOrder $order,
        ?int $userId,
        ?string $userName,
    ): int {
        $items = $this->orderItems($order);
        $totalDeducted = 0;

        foreach ($items as $line) {
            $fulfilledQty = (int) ($line['fulfilled_qty'] ?? 0);
            if ($fulfilledQty < 1) {
                continue;
            }

            $alreadyDeducted = $this->deductedQtyForOrderLine($order, $line);
            $pending = max(0, $fulfilledQty - $alreadyDeducted);
            if ($pending < 1) {
                continue;
            }

            $totalDeducted += $this->deductFinishedGoodsInventory(
                $line,
                $pending,
                $order->client_name,
                $userId,
                $order->id,
                $order->order_number,
            );
        }

        if ($totalDeducted > 0 && $userName) {
            $order->update(['sold_by' => $userName]);
        }

        return $totalDeducted;
    }

    private function deductedQtyForOrderLine(SalesOrder $order, array $line): int
    {
        $orderRef = $order->order_number ?? '#'.$order->id;
        $names = $this->productMatchNames($line);

        return (int) InventoryTransaction::query()
            ->where('type', 'checkout')
            ->where('notes', 'like', '%'.$orderRef.'%')
            ->where(function ($builder) use ($names) {
                foreach ($names as $name) {
                    $builder->orWhere('material_name', $name);
                }
            })
            ->sum('quantity');
    }

    private function availableQtyForProduct(string $productName, ?int $itemId = null): int
    {
        $line = [
            'product_name' => $productName,
            'item_id' => $itemId,
        ];

        return (int) $this->resolveInventoryItemsForLine($line)->sum('quantity');
    }

    private function formatOrder(SalesOrder $order): array
    {
        $items = $this->orderItems($order);
        $formattedItems = collect($items)->map(function (array $line) {
            $fulfilledQty = (int) ($line['fulfilled_qty'] ?? 0);
            $orderQty = (int) $line['quantity'];
            $remainingQty = max(0, $orderQty - $fulfilledQty);
            $availableNow = $this->availableQtyForProduct(
                $line['product_name'],
                isset($line['item_id']) ? (int) $line['item_id'] : null,
            );
            $manufacturingCost = $this->inventoryCosts->manufacturingUnitCostForProduct($line['product_name']);
            $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== null
                ? (float) $line['unit_price']
                : null;

            return [
                ...$line,
                'fulfilled_qty' => $fulfilledQty,
                'remaining_qty' => $remainingQty,
                'available_now' => $availableNow,
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
                'can_fulfill' => $remainingQty > 0 && $availableNow > 0,
                'can_fulfill_full' => $remainingQty > 0 && $availableNow >= $remainingQty,
            ];
        })->values()->all();

        $fulfilledQty = (int) ($order->fulfilled_qty ?? 0);
        $remainingQty = max(0, (int) $order->quantity - $fulfilledQty);
        $isMultiItem = count($formattedItems) > 1;
        $primaryLine = $formattedItems[0] ?? [];
        $productName = $order->product_name;
        $availableNow = $isMultiItem
            ? (int) collect($formattedItems)->min('available_now')
            : (int) ($primaryLine['available_now'] ?? 0);
        $manufacturingCost = $isMultiItem
            ? null
            : ($primaryLine['manufacturing_cost'] ?? null);
        $unitPrice = $isMultiItem
            ? null
            : (isset($primaryLine['unit_price']) ? (float) $primaryLine['unit_price'] : null);
        $orderQty = (int) $order->quantity;
        $totalRevenue = collect($formattedItems)
            ->sum(fn (array $line) => (float) ($line['line_revenue'] ?? 0));
        $totalManufacturingCost = collect($formattedItems)
            ->sum(fn (array $line) => (float) ($line['total_manufacturing_cost'] ?? 0));
        $totalGrossProfit = collect($formattedItems)
            ->sum(fn (array $line) => (float) ($line['gross_profit'] ?? 0));

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'client_id' => $order->client_id,
            'client_name' => $order->client_name,
            'item_id' => $order->item_id,
            'product_name' => $productName,
            'items' => $formattedItems,
            'item_count' => count($formattedItems),
            'is_multi_item' => $isMultiItem,
            'unit' => $order->unit,
            'quantity' => $order->quantity,
            'fulfilled_qty' => $fulfilledQty,
            'remaining_qty' => $remainingQty,
            'unit_price' => $order->unit_price,
            'manufacturing_cost' => $manufacturingCost,
            'total_manufacturing_cost' => $totalManufacturingCost > 0 ? $totalManufacturingCost : null,
            'line_revenue' => $totalRevenue > 0 ? round($totalRevenue, 2) : null,
            'gross_profit' => ($totalRevenue > 0 || $totalManufacturingCost > 0)
                ? round($totalGrossProfit, 2)
                : null,
            'is_sellable' => collect($formattedItems)->every(
                fn (array $line) => ! $this->rawMaterialProductKeys()->contains(
                    $this->productKey($line['product_name']),
                ),
            ),
            'available_at_order' => $order->available_at_order,
            'available_now' => $availableNow,
            'shortfall' => $order->shortfall,
            'can_fulfill' => $remainingQty > 0
                && collect($formattedItems)->contains(fn (array $line) => $line['can_fulfill'] === true)
                && in_array($order->status, ['open', 'in_production', 'partial'], true),
            'can_fulfill_full' => $remainingQty > 0
                && collect($formattedItems)->every(
                    fn (array $line) => (int) ($line['remaining_qty'] ?? 0) < 1 || $line['can_fulfill_full'] === true,
                )
                && collect($formattedItems)->contains(fn (array $line) => (int) ($line['remaining_qty'] ?? 0) > 0)
                && in_array($order->status, ['open', 'in_production', 'partial'], true),
            'status' => $order->status,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'delivery_date' => $order->delivery_date?->format('Y-m-d'),
            'sold_at' => $order->sold_at?->format('Y-m-d H:i'),
            'notes' => $order->notes,
            'created_by' => $order->created_by,
            'sold_by' => $order->sold_by,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'inventory_pending' => $this->pendingInventoryDeduction($order) > 0,
            'inventory_pending_qty' => $this->pendingInventoryDeduction($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSalePayload(Request $request, bool $requireFulfillFlag = true): array
    {
        $rules = [
            'client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required_without:product_name|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.product_name' => 'required_with:items|string|max:255',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'item_id' => 'nullable|integer',
            'product_name' => 'required_without:items|string|max:255',
            'unit' => 'nullable|string|max:50',
            'quantity' => 'required_without:items|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
        ];

        if ($requireFulfillFlag) {
            $rules['fulfill_now'] = 'nullable|boolean';
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function buildOrderItems(array $data): array
    {
        $rawLines = $data['items'] ?? null;
        if (! is_array($rawLines)) {
            $rawLines = [[
                'item_id' => $data['item_id'] ?? null,
                'product_name' => $data['product_name'],
                'unit' => $data['unit'] ?? 'pcs',
                'quantity' => (int) $data['quantity'],
                'unit_price' => $data['unit_price'] ?? null,
            ]];
        }

        $lines = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $productName = trim((string) ($line['product_name'] ?? ''));
            if ($productName === '') {
                continue;
            }

            $this->assertSellableProduct($this->productKey($productName));
            $resolvedItem = $this->resolveItemByNameOrCode($productName, $line['item_id'] ?? null);
            $available = $this->availableQtyForProduct(
                $productName,
                $resolvedItem?->id,
            );
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty < 1) {
                continue;
            }

            $lines[] = [
                'item_id' => $resolvedItem?->id ?? ($line['item_id'] ?? null),
                'item_code' => $resolvedItem?->code,
                'product_name' => $productName,
                'unit' => $line['unit'] ?? 'pcs',
                'quantity' => $qty,
                'fulfilled_qty' => 0,
                'unit_price' => isset($line['unit_price']) ? (float) $line['unit_price'] : null,
                'available_at_order' => $available,
                'shortfall' => max(0, $qty - $available),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one valid product line.'],
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function rollupFromItems(array $items): array
    {
        $count = count($items);
        $first = $items[0];
        $totalQty = (int) collect($items)->sum('quantity');
        $totalFulfilled = (int) collect($items)->sum(fn (array $line) => (int) ($line['fulfilled_qty'] ?? 0));
        $totalShortfall = (int) collect($items)->sum('shortfall');

        $productName = $count === 1
            ? $first['product_name']
            : $first['product_name'].' + '.($count - 1).' more';

        return [
            'item_id' => $count === 1 ? ($first['item_id'] ?? null) : null,
            'product_name' => $productName,
            'unit' => $count === 1 ? ($first['unit'] ?? 'pcs') : 'mixed',
            'quantity' => $totalQty,
            'fulfilled_qty' => $totalFulfilled,
            'unit_price' => $count === 1 ? ($first['unit_price'] ?? null) : null,
            'available_at_order' => (int) collect($items)->sum('available_at_order'),
            'shortfall' => $totalShortfall,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderItems(SalesOrder $order): array
    {
        $stored = $order->items;
        if (is_array($stored) && $stored !== []) {
            return array_values($stored);
        }

        if (filled($order->product_name)) {
            return [[
                'item_id' => $order->item_id,
                'product_name' => $order->product_name,
                'unit' => $order->unit ?? 'pcs',
                'quantity' => (int) $order->quantity,
                'fulfilled_qty' => (int) ($order->fulfilled_qty ?? 0),
                'unit_price' => $order->unit_price !== null ? (float) $order->unit_price : null,
                'available_at_order' => (int) ($order->available_at_order ?? 0),
                'shortfall' => (int) ($order->shortfall ?? 0),
            ]];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function remainingQtyForItems(array $items): int
    {
        return (int) collect($items)->sum(
            fn (array $line) => max(0, (int) $line['quantity'] - (int) ($line['fulfilled_qty'] ?? 0)),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function resolveOrderStatus(array $items): string
    {
        $remaining = $this->remainingQtyForItems($items);
        if ($remaining < 1) {
            return 'fulfilled';
        }

        $fulfilled = (int) collect($items)->sum(fn (array $line) => (int) ($line['fulfilled_qty'] ?? 0));

        return $fulfilled > 0 ? 'partial' : 'open';
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array{order: SalesOrder, line: array<string, mixed>}>>
     */
    private function openOrderLinesByProduct()
    {
        return SalesOrder::query()
            ->whereIn('status', ['open', 'in_production', 'partial'])
            ->orderByDesc('created_at')
            ->get()
            ->flatMap(function (SalesOrder $order) {
                return collect($this->orderItems($order))->map(fn (array $line) => [
                    'order' => $order,
                    'line' => $line,
                ]);
            })
            ->groupBy(function (array $entry) {
                $line = $entry['line'];

                return $this->canonicalProductKey(
                    $line['product_name'] ?? null,
                    isset($line['item_id']) ? (int) $line['item_id'] : null,
                );
            });
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

    private function pendingInventoryDeduction(SalesOrder $order): int
    {
        if (! in_array($order->status, ['fulfilled', 'partial'], true)) {
            return 0;
        }

        $pending = 0;
        foreach ($this->orderItems($order) as $line) {
            $fulfilledQty = (int) ($line['fulfilled_qty'] ?? 0);
            if ($fulfilledQty < 1) {
                continue;
            }

            $pending += max(0, $fulfilledQty - $this->deductedQtyForOrderLine($order, $line));
        }

        return $pending;
    }

    /**
     * @return list<string>
     */
    private function productMatchNames(array $line): array
    {
        $names = [];
        $productName = trim((string) ($line['product_name'] ?? ''));
        if ($productName !== '') {
            $names[] = $productName;
        }

        if (! empty($line['item_code'])) {
            $names[] = trim((string) $line['item_code']);
        }

        $item = $this->resolveItemByNameOrCode($productName, $line['item_id'] ?? null);
        if ($item !== null) {
            if (filled($item->name)) {
                $names[] = trim($item->name);
            }
            if (filled($item->code)) {
                $names[] = trim($item->code);
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * @return \Illuminate\Support\Collection<int, InventoryItem>
     */
    private function resolveInventoryItemsForLine(array $line, bool $lock = false)
    {
        $targetKey = $this->canonicalProductKey(
            $line['product_name'] ?? null,
            isset($line['item_id']) ? (int) $line['item_id'] : null,
        );

        $query = InventoryItem::query()
            ->where('type', 'finished_goods')
            ->where('status', 'available')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query
            ->get()
            ->filter(fn (InventoryItem $item) => $this->canonicalProductKey($item->name) === $targetKey)
            ->values();
    }

    private function resolveItemByNameOrCode(?string $name, ?int $itemId = null): ?Item
    {
        if ($itemId) {
            $byId = Item::query()->find($itemId);
            if ($byId !== null) {
                return $byId;
            }
        }

        $key = $this->productKey($name);
        $normalized = $this->normalizeProductKey($name);
        if ($key === '' && $normalized === '') {
            return null;
        }

        return Item::query()
            ->where(function ($query) use ($key, $normalized) {
                if ($key !== '') {
                    $query->whereRaw('LOWER(TRIM(name)) = ?', [$key])
                        ->orWhereRaw('LOWER(TRIM(code)) = ?', [$key]);
                }

                if ($normalized !== '') {
                    $query->orWhereRaw(
                        "LOWER(REPLACE(REPLACE(REPLACE(code, '-', ''), ' ', ''), '.', '')) = ?",
                        [$normalized],
                    )->orWhereRaw(
                        "LOWER(REPLACE(REPLACE(REPLACE(name, '-', ''), ' ', ''), '.', '')) = ?",
                        [$normalized],
                    );
                }
            })
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<string, array{available_qty: int, min_stock: int, unit: string}>
     */
    private function availableInventoryByProductKey()
    {
        return InventoryItem::query()
            ->where('type', 'finished_goods')
            ->where('status', 'available')
            ->get()
            ->groupBy(function (InventoryItem $item) {
                $master = $this->resolveItemByNameOrCode($item->name);

                return $this->canonicalProductKey($item->name, $master?->id);
            })
            ->map(function ($items) {
                return [
                    'available_qty' => (int) $items->sum('quantity'),
                    'min_stock' => (int) $items->max('min_stock'),
                    'unit' => $items->first()->unit ?? 'pcs',
                ];
            });
    }

    /**
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function sellableFinishedGoodsStockByProductKey()
    {
        return FinishedGood::query()
            ->where('stock', '>', 0)
            ->get()
            ->groupBy(function (FinishedGood $good) {
                $master = $this->resolveItemByNameOrCode($good->product_name);

                return $this->canonicalProductKey($good->product_name, $master?->id);
            })
            ->map(fn ($rows) => (int) $rows->sum('stock'));
    }

    private function sellableDisplayName(?Item $item, ?string $fallback): string
    {
        if ($item !== null) {
            $code = trim((string) ($item->code ?? ''));
            $name = trim((string) ($item->name ?? ''));
            if ($code !== '' && $name !== '' && strcasecmp($code, $name) !== 0) {
                return $code.' — '.$name;
            }
            if ($name !== '') {
                return $name;
            }
            if ($code !== '') {
                return $code;
            }
        }

        return trim((string) $fallback) !== '' ? trim((string) $fallback) : 'Product';
    }

    /**
     * @param  array<string, mixed>  $catalogRow
     */
    private function mergeCatalogItemMetadata(array &$catalogRow, ?Item $item, ?string $fallbackName): void
    {
        if ($item === null) {
            return;
        }

        if (empty($catalogRow['item_id'])) {
            $catalogRow['item_id'] = $item->id;
        }
        if (empty($catalogRow['item_code'])) {
            $catalogRow['item_code'] = $item->code;
        }

        $displayName = $this->sellableDisplayName($item, $fallbackName);
        $catalogRow['name'] = $displayName;
        $catalogRow['product'] = $displayName;
    }

    private function canonicalProductKey(?string $name, ?int $itemId = null): string
    {
        $item = $this->resolveItemByNameOrCode($name, $itemId);
        if ($item !== null) {
            return 'item:'.$item->id;
        }

        $normalized = $this->normalizeProductKey($name);

        return $normalized !== '' ? 'name:'.$normalized : '';
    }

    private function displayNameForCatalogKey(string $key): string
    {
        if (str_starts_with($key, 'item:')) {
            $item = Item::query()->find((int) Str::after($key, 'item:'));
            if ($item !== null) {
                return $item->name ?: $item->code ?: $key;
            }
        }

        if (str_starts_with($key, 'name:')) {
            return Str::upper(Str::after($key, 'name:'));
        }

        return Str::title($key);
    }

    private function isRawMaterialCatalogKey(string $key, $rawMaterialKeys): bool
    {
        if (str_starts_with($key, 'item:')) {
            $item = Item::query()->find((int) Str::after($key, 'item:'));
            if ($item !== null) {
                return $rawMaterialKeys->contains($this->productKey($item->name))
                    || $rawMaterialKeys->contains($this->productKey($item->code));
            }
        }

        if (str_starts_with($key, 'name:')) {
            return $rawMaterialKeys->contains(Str::after($key, 'name:'));
        }

        return $rawMaterialKeys->contains($key);
    }

    private function normalizeProductKey(?string $name): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $name)));
    }

    private function productKey(?string $name): string
    {
        return Str::lower(trim((string) $name));
    }
}
