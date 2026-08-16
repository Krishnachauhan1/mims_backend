<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Rejection;
use App\Models\SalesOrder;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function inventory(): JsonResponse
    {
        return response()->json(
            InventoryItem::query()
                ->latest()
                ->get()
                ->map(fn (InventoryItem $item) => [
                    'material' => $item->name,
                    'category' => $this->inventoryCategoryLabel($item->type),
                    'current_stock' => (int) $item->quantity,
                    'consumed' => max(0, (int) $item->min_stock - (int) $item->quantity),
                    'movement' => $item->status ?? 'stable',
                    'warehouse' => $item->location ?? 'Main Warehouse',
                    'status' => $this->stockStatus($item),
                ]),
        );
    }

    public function production(): JsonResponse
    {
        return response()->json(
            ProductionBatch::query()
                ->latest()
                ->get()
                ->map(fn (ProductionBatch $batch) => [
                    'product' => $batch->product_name,
                    'batch' => $batch->batch_number,
                    'stage' => $batch->current_stage ?? '—',
                    'quantity' => (int) $batch->quantity,
                    'completion_time' => ($batch->completed_at ?? $batch->updated_at)?->format('Y-m-d H:i') ?? '—',
                    'status' => $batch->status,
                    'operator' => $batch->assigned_to ?? $batch->user_name ?? '—',
                ]),
        );
    }

    public function vendor(): JsonResponse
    {
        $orders = PurchaseOrder::query()->get();

        return response()->json(
            Vendor::query()
                ->latest()
                ->get()
                ->map(function (Vendor $vendor) use ($orders) {
                    $vendorOrders = $orders->filter(
                        fn (PurchaseOrder $order) => strcasecmp($order->vendor_name ?? '', $vendor->name) === 0,
                    );

                    $totalAmount = $vendorOrders->sum('total_amount');
                    $lastOrder = $vendorOrders
                        ->sortByDesc('created_at')
                        ->first();

                    return [
                        'vendor' => $vendor->name,
                        'contact' => $vendor->contact_person ?? $vendor->phone ?? '—',
                        'total_purchases' => $vendorOrders->count() ?: (int) $vendor->total_orders,
                        'last_order' => ($lastOrder?->created_at ?? $vendor->last_order_date)?->format('Y-m-d') ?? '—',
                        'amount' => '₹'.number_format($totalAmount, 0),
                        'trend' => $vendorOrders->count() >= 3 ? 'Growing' : 'Stable',
                    ];
                }),
        );
    }

    public function customer(): JsonResponse
    {
        $finishedGoods = FinishedGood::query()->with('client')->get();

        return response()->json(
            Client::query()
                ->latest()
                ->get()
                ->map(function (Client $client) use ($finishedGoods) {
                    $clientProducts = $finishedGoods->where('client_id', $client->id);
                    $dispatched = (int) $clientProducts->sum('dispatched');
                    $revenue = $dispatched * 1000;

                    $productNames = $clientProducts
                        ->pluck('product_name')
                        ->unique()
                        ->take(3)
                        ->implode(', ');

                    $lastDispatch = $clientProducts
                        ->sortByDesc('updated_at')
                        ->first();

                    return [
                        'customer' => $client->name,
                        'contact' => $client->contact_person ?? $client->phone ?? '—',
                        'total_sales' => $clientProducts->count() ?: (int) $client->total_orders,
                        'revenue' => '₹'.number_format($revenue, 0),
                        'last_dispatch' => ($lastDispatch?->updated_at ?? $client->last_order_date)?->format('Y-m-d') ?? '—',
                        'products' => $productNames ?: '—',
                    ];
                }),
        );
    }

    public function finishedGoods(): JsonResponse
    {
        return response()->json(
            FinishedGood::query()
                ->latest()
                ->get()
                ->map(fn (FinishedGood $product) => [
                    'product' => $product->product_name,
                    'product_code' => $product->sku,
                    'batch' => $product->batch_number,
                    'produced_date' => $product->production_date?->format('Y-m-d') ?? '—',
                    'quantity' => (int) $product->quantity,
                    'stock' => (int) $product->stock,
                    'dispatched' => (int) $product->dispatched,
                    'warehouse' => $product->warehouse ?? $product->location ?? '—',
                    'status' => $product->status ?? 'In Stock',
                ]),
        );
    }

    public function rejections(): JsonResponse
    {
        return response()->json(
            Rejection::query()
                ->latest()
                ->get()
                ->map(fn (Rejection $rejection) => [
                    'product' => $rejection->product_name,
                    'batch' => $rejection->batch_number,
                    'rejected_quantity' => (int) $rejection->quantity,
                    'total_produced' => (int) ($rejection->total_produced ?: 0),
                    'reason' => $rejection->reason ?? '—',
                    'stage' => $rejection->stage ?? '—',
                    'date' => ($rejection->rejection_date ?? $rejection->created_at)?->format('Y-m-d') ?? '—',
                    'user' => $rejection->reported_by ?? '—',
                    'notes' => $rejection->notes,
                ]),
        );
    }

    public function mostSellingProducts(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : null;
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->endOfDay()
            : null;

        $rows = [];

        $salesQuery = SalesOrder::query();
        if ($from !== null) {
            $salesQuery->where(function ($query) use ($from): void {
                $query->whereDate('sold_at', '>=', $from)
                    ->orWhere(function ($nested) use ($from): void {
                        $nested->whereNull('sold_at')
                            ->whereDate('order_date', '>=', $from);
                    })
                    ->orWhere(function ($nested) use ($from): void {
                        $nested->whereNull('sold_at')
                            ->whereNull('order_date')
                            ->whereDate('created_at', '>=', $from);
                    });
            });
        }
        if ($to !== null) {
            $salesQuery->where(function ($query) use ($to): void {
                $query->whereDate('sold_at', '<=', $to)
                    ->orWhere(function ($nested) use ($to): void {
                        $nested->whereNull('sold_at')
                            ->whereDate('order_date', '<=', $to);
                    })
                    ->orWhere(function ($nested) use ($to): void {
                        $nested->whereNull('sold_at')
                            ->whereNull('order_date')
                            ->whereDate('created_at', '<=', $to);
                    });
            });
        }

        foreach ($salesQuery->get() as $order) {
            $this->accumulateMostSellingRow(
                $rows,
                productName: (string) ($order->product_name ?: 'Unknown Product'),
                unit: (string) ($order->unit ?: 'pcs'),
                soldQty: $this->soldQuantityForOrder($order),
                revenue: $this->salesOrderRevenue($order),
                clientName: $order->client_name,
                source: 'sales_order',
            );
        }

        $finishedQuery = FinishedGood::query()->with('client')->where('dispatched', '>', 0);
        if ($from !== null) {
            $finishedQuery->where(function ($query) use ($from): void {
                $query->whereDate('updated_at', '>=', $from)
                    ->orWhereDate('production_date', '>=', $from);
            });
        }
        if ($to !== null) {
            $finishedQuery->where(function ($query) use ($to): void {
                $query->whereDate('updated_at', '<=', $to)
                    ->orWhereDate('production_date', '<=', $to);
            });
        }

        foreach ($finishedQuery->get() as $product) {
            $this->accumulateMostSellingRow(
                $rows,
                productName: (string) ($product->product_name ?: 'Unknown Product'),
                unit: 'pcs',
                soldQty: (int) $product->dispatched,
                revenue: 0.0,
                clientName: $product->client?->name,
                source: 'dispatch',
            );
        }

        $sorted = collect($rows)
            ->sortByDesc('sold_qty')
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;
                $row['revenue_display'] = '₹'.number_format((float) $row['revenue'], 0);
                $row['avg_order_qty'] = $row['order_count'] > 0
                    ? round($row['sold_qty'] / $row['order_count'], 1)
                    : 0;
                $row['top_client'] = $row['top_client'] ?? '—';
                $row['share_percent'] = 0.0;

                return $row;
            });

        $totalSold = (int) $sorted->sum('sold_qty');
        $totalRevenue = (float) $sorted->sum('revenue');

        $sorted = $sorted->map(function (array $row) use ($totalSold): array {
            $row['share_percent'] = $totalSold > 0
                ? round(((int) $row['sold_qty'] / $totalSold) * 100, 1)
                : 0.0;

            return $row;
        });

        return response()->json([
            'summary' => [
                'total_products' => $sorted->count(),
                'total_sold_qty' => $totalSold,
                'total_revenue' => round($totalRevenue, 2),
                'total_revenue_display' => '₹'.number_format($totalRevenue, 0),
                'top_product' => $sorted->first()['product'] ?? '—',
            ],
            'rows' => $sorted->all(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function accumulateMostSellingRow(
        array &$rows,
        string $productName,
        string $unit,
        int $soldQty,
        float $revenue,
        ?string $clientName,
        string $source,
    ): void {
        if ($soldQty <= 0 && $revenue <= 0) {
            return;
        }

        $key = strtolower(trim($productName));
        if ($key === '') {
            $key = 'unknown product';
        }

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'product' => trim($productName),
                'unit' => $unit,
                'sold_qty' => 0,
                'order_count' => 0,
                'revenue' => 0.0,
                'client_counts' => [],
                'sources' => [],
            ];
        }

        $rows[$key]['sold_qty'] += max(0, $soldQty);
        $rows[$key]['order_count']++;
        $rows[$key]['revenue'] = round($rows[$key]['revenue'] + max(0, $revenue), 2);
        $rows[$key]['sources'][$source] = true;

        if (filled($clientName)) {
            $client = trim((string) $clientName);
            $rows[$key]['client_counts'][$client] = ($rows[$key]['client_counts'][$client] ?? 0) + 1;
            arsort($rows[$key]['client_counts']);
            $rows[$key]['top_client'] = array_key_first($rows[$key]['client_counts']) ?? '—';
        }
    }

    private function soldQuantityForOrder(SalesOrder $order): int
    {
        $qty = (int) ($order->fulfilled_qty ?? 0);
        if ($qty < 1 && in_array($order->status, ['fulfilled', 'partial'], true)) {
            $qty = (int) $order->quantity;
        }

        return max(0, $qty);
    }

    private function salesOrderRevenue(SalesOrder $order): float
    {
        $qty = $this->soldQuantityForOrder($order);
        if ($qty < 1) {
            return 0.0;
        }

        return round($qty * (float) ($order->unit_price ?? 0), 2);
    }

    private function inventoryCategoryLabel(?string $type): string
    {
        $normalized = strtolower(str_replace(' ', '_', $type ?? 'other'));

        return match (true) {
            str_contains($normalized, 'raw') => 'Raw Material',
            str_contains($normalized, 'wip') => 'Work In Progress',
            str_contains($normalized, 'finished') => 'Finished Goods',
            default => ucwords(str_replace('_', ' ', $normalized)),
        };
    }

    private function stockStatus(InventoryItem $item): string
    {
        if ((int) $item->quantity <= 0) {
            return 'Out of Stock';
        }

        if ($item->min_stock !== null && (int) $item->quantity <= (int) $item->min_stock) {
            return 'Low Stock';
        }

        return 'In Stock';
    }
}
