<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\Machinery;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Rejection;
use App\Models\SalesOrder;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\BatchStageMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function machineActiveWork(Request $request): JsonResponse
    {
        try {
            return response()->json($this->buildMachineActiveWorkPayload($request));
        } catch (\Throwable $e) {
            report($e);
            Log::error('[API][reports/machine-active-work] failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to load machine active work report',
                'summary' => [
                    'total_machines' => 0,
                    'busy_machines' => 0,
                    'idle_machines' => 0,
                    'total_active_jobs' => 0,
                    'total_active_quantity' => 0,
                    'generated_at' => now()->format('Y-m-d H:i'),
                ],
                'machines' => [],
            ], 500);
        }
    }

    /**
     * @return array{summary: array<string, mixed>, machines: array<int, array<string, mixed>>}
     */
    private function buildMachineActiveWorkPayload(Request $request): array
    {
        $batchStageMachine = app(BatchStageMachineService::class);
        $includeOnHold = filter_var($request->query('include_on_hold', false), FILTER_VALIDATE_BOOL);
        $machineFilter = $request->filled('machine_id')
            ? (int) $request->query('machine_id')
            : null;

        $machinesQuery = Machinery::query();
        if (Schema::hasColumn('machinery', 'sequence')) {
            $machinesQuery->orderBy('sequence');
        }
        $machines = $machinesQuery->orderBy('id')->get();

        $batchQuery = ProductionBatch::query()
            ->with(['workflow'])
            ->where('status', '!=', 'completed');

        if (! $includeOnHold) {
            $batchQuery->where('status', '!=', 'on_hold');
        }

        $batches = $batchQuery->get();

        $workOrdersById = $this->workOrdersForBatches($batches);

        $machineRows = [];
        foreach ($machines as $machine) {
            $machineRows[(int) $machine->id] = [
                'machine_id' => (int) $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->name,
                'type' => $machine->type,
                'location' => $machine->location,
                'capacity' => $machine->capacity,
                'status' => $machine->status,
                'default_stage' => $machine->default_stage,
                'active_job_count' => 0,
                'active_quantity' => 0,
                'is_busy' => false,
                'jobs' => [],
            ];
        }

        $unassigned = [
            'machine_id' => null,
            'machine_code' => null,
            'machine_name' => 'Unassigned / No machine',
            'type' => null,
            'location' => null,
            'capacity' => null,
            'status' => 'unknown',
            'default_stage' => null,
            'active_job_count' => 0,
            'active_quantity' => 0,
            'is_busy' => false,
            'jobs' => [],
        ];

        foreach ($batches as $batch) {
            try {
                $currentStage = $batchStageMachine->currentStageForBatch(
                    $batch,
                    $workOrdersById,
                );
            } catch (\Throwable $e) {
                Log::warning('[API][reports/machine-active-work] stage resolve failed', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($currentStage === null) {
                continue;
            }

            $machineId = $batchStageMachine->machineIdForStage($currentStage);
            $job = $this->formatMachineActiveJob(
                $batch,
                $currentStage,
                $workOrdersById[(int) $batch->work_order_id] ?? null,
            );

            if ($machineId !== null && isset($machineRows[$machineId])) {
                $machineRows[$machineId]['jobs'][] = $job;
                $machineRows[$machineId]['active_job_count']++;
                $machineRows[$machineId]['active_quantity'] += (int) $batch->quantity;
                $machineRows[$machineId]['is_busy'] = true;
            } else {
                $unassigned['jobs'][] = $job;
                $unassigned['active_job_count']++;
                $unassigned['active_quantity'] += (int) $batch->quantity;
                $unassigned['is_busy'] = true;
            }
        }

        $rows = array_values($machineRows);
        if ($unassigned['active_job_count'] > 0) {
            $rows[] = $unassigned;
        }

        if ($machineFilter !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => (int) ($row['machine_id'] ?? 0) === $machineFilter,
            ));
        }

        $busyCount = collect($machineRows)->where('is_busy', true)->count();
        $totalJobs = (int) collect($rows)->sum('active_job_count');
        $totalQty = (int) collect($rows)->sum('active_quantity');

        return [
            'summary' => [
                'total_machines' => $machines->count(),
                'busy_machines' => $busyCount,
                'idle_machines' => max(0, $machines->count() - $busyCount),
                'total_active_jobs' => $totalJobs,
                'total_active_quantity' => $totalQty,
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'machines' => $rows,
        ];
    }

    /**
     * @param  iterable<int, ProductionBatch>  $batches
     * @return array<int, WorkOrder>
     */
    private function workOrdersForBatches(iterable $batches): array
    {
        if (! Schema::hasColumn('production_batches', 'work_order_id')) {
            return [];
        }

        $ids = [];
        foreach ($batches as $batch) {
            if (! empty($batch->work_order_id)) {
                $ids[] = (int) $batch->work_order_id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return WorkOrder::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $currentStage
     * @return array<string, mixed>
     */
    private function formatMachineActiveJob(
        ProductionBatch $batch,
        array $currentStage,
        ?WorkOrder $workOrder = null,
    ): array {
        if ($workOrder === null && ! empty($batch->work_order_id)) {
            $workOrder = WorkOrder::query()->find($batch->work_order_id);
        }

        $routeCard = null;
        if ($workOrder !== null) {
            $routeCard = $workOrder->route_card_no ?? $workOrder->workflow_name ?? null;
        }
        $routeCard ??= $batch->workflow?->name;

        return [
            'batch_id' => (int) $batch->id,
            'batch_number' => (string) $batch->batch_number,
            'product_name' => (string) ($batch->product_name ?: 'Product'),
            'quantity' => (int) $batch->quantity,
            'unit' => $batch->unit,
            'current_stage' => (string) ($batch->current_stage ?? ($currentStage['name'] ?? '—')),
            'stage_order' => (int) ($batch->stage_order ?? 1),
            'total_stages' => (int) ($batch->total_stages ?? 1),
            'status' => (string) ($batch->status ?? 'in_progress'),
            'priority' => Schema::hasColumn('production_batches', 'priority')
                ? (string) ($batch->priority ?? 'normal')
                : 'normal',
            'work_order_id' => $batch->work_order_id,
            'route_card_no' => $routeCard,
            'client_name' => $batch->client_name ?? $workOrder?->client_name,
            'stage_started_at' => $this->safeReportDateTime($batch->stage_started_at ?? $batch->started_at),
            'started_at' => $this->safeReportDateTime($batch->started_at ?? $batch->created_at),
            'operator' => $batch->assigned_to ?? $batch->user_name ?? $batch->performed_by,
            'hold_reason' => $batch->hold_reason,
            'machine_name' => $currentStage['machine_name'] ?? null,
            'machine_code' => $currentStage['machine_code'] ?? null,
            'is_job_work_stage' => (bool) ($currentStage['is_job_work'] ?? false),
        ];
    }

    private function safeReportDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return null;
        }
    }

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
