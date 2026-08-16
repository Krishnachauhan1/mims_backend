<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DashboardTask;
use App\Services\AppNotificationService;
use App\Models\FinishedGood;
use App\Models\Gauge;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JobWork;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Rejection;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function kpis(): JsonResponse
    {
        $inventory = InventoryItem::query()->get();
        $batches = ProductionBatch::query()->get();
        $rejections = Rejection::query()->get();
        $checkins = InventoryTransaction::query()
            ->where('type', 'checkin')
            ->get();
        $finishedGoods = FinishedGood::query()->get();
        $gauges = Gauge::query()->get();

        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();

        $wipBatches = $batches
            ->whereIn('status', ['in_progress', 'on_hold'])
            ->count();

        $gaugeStats = $this->gaugeKpiStats($gauges);

        return response()->json([
            'total_inventory' => (int) $inventory->sum('quantity'),
            'raw_material' => (int) $inventory->where('type', 'raw_material')->sum('quantity'),
            'wip' => (int) $inventory->where('type', 'wip')->sum('quantity') + $wipBatches,
            'finished_goods' => (int) $finishedGoods->sum('stock') + (int) $inventory->where('type', 'finished_goods')->sum('quantity'),
            'rejected' => (int) $rejections->sum('quantity'),
            'daily_production' => (int) $batches
                ->filter(fn (ProductionBatch $batch) => $batch->status === 'completed'
                    && $batch->completed_at?->isSameDay($today))
                ->sum('quantity'),
            'monthly_production' => (int) $batches
                ->filter(fn (ProductionBatch $batch) => $batch->status === 'completed'
                    && $batch->completed_at?->betweenIncluded($monthStart, $now))
                ->sum('quantity'),
            'inventory_movement' => $checkins->count(),
            ...$gaugeStats,
        ]);
    }

    public function productionTrends(): JsonResponse
    {
        $buckets = $this->emptyMonthlyBuckets();
        $batches = ProductionBatch::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->get();
        $rejections = Rejection::query()->get();

        foreach ($batches as $batch) {
            $key = $batch->completed_at?->format('Y-m');
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['produced'] += (int) $batch->quantity;
            }
        }

        foreach ($rejections as $rejection) {
            $date = $rejection->rejection_date ?? $rejection->created_at;
            $key = $date?->format('Y-m');
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['rejected'] += (int) $rejection->quantity;
            }
        }

        return response()->json(array_values($buckets));
    }

    public function profitLossTrends(): JsonResponse
    {
        try {
            return response()->json($this->buildProfitLossPayload());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'trends' => array_values($this->emptyFinancialBuckets()),
                'summary' => [
                    'total_revenue' => 0,
                    'total_costs' => 0,
                    'net_profit' => 0,
                    'margin_percent' => 0,
                ],
            ]);
        }
    }

    /**
     * @return array{trends: list<array<string, mixed>>, summary: array<string, float|int>}
     */
    private function buildProfitLossPayload(): array
    {
        $buckets = $this->emptyFinancialBuckets();

        $salesOrders = SalesOrder::query()
            ->where(function ($query): void {
                $query->where('fulfilled_qty', '>', 0)
                    ->orWhereIn('status', ['fulfilled', 'partial']);
            })
            ->get();

        foreach ($salesOrders as $order) {
            $revenue = $this->salesOrderRevenue($order);
            if ($revenue <= 0) {
                continue;
            }

            $date = $order->sold_at
                ?? $order->order_date
                ?? $order->updated_at
                ?? $order->created_at;
            $key = $this->financialBucketKey($date);
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['revenue'] += $revenue;
            }
        }

        foreach (PurchaseOrder::query()->get() as $purchaseOrder) {
            $total = $this->purchaseOrderTotal($purchaseOrder);
            if ($total <= 0) {
                continue;
            }

            $key = $this->financialBucketKey($purchaseOrder->created_at);
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['costs'] += $total;
            }
        }

        foreach (JobWork::query()->get() as $jobWork) {
            $total = $this->jobWorkTotal($jobWork);
            if ($total <= 0) {
                continue;
            }

            $date = $jobWork->sent_date ?? $jobWork->created_at;
            $key = $this->financialBucketKey($date);
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['costs'] += $total;
            }
        }

        foreach (Rejection::query()->get() as $rejection) {
            $scrapCost = (float) ($rejection->scrap_cost ?? 0);
            if ($scrapCost <= 0) {
                $scrapCost = (float) ($rejection->unit_cost ?? 0)
                    * (int) ($rejection->quantity ?? 0);
            }
            if ($scrapCost <= 0) {
                continue;
            }

            $date = $rejection->rejection_date ?? $rejection->created_at;
            $key = $this->financialBucketKey($date);
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['costs'] += round($scrapCost, 2);
            }
        }

        $trends = [];
        $totalRevenue = 0.0;
        $totalCosts = 0.0;

        foreach ($buckets as $bucket) {
            $revenue = round($bucket['revenue'], 2);
            $costs = round($bucket['costs'], 2);
            $profit = round($revenue - $costs, 2);
            $trends[] = [
                'month' => $bucket['month'],
                'revenue' => $revenue,
                'costs' => $costs,
                'profit' => $profit,
            ];
            $totalRevenue += $revenue;
            $totalCosts += $costs;
        }

        $netProfit = round($totalRevenue - $totalCosts, 2);

        return [
            'trends' => $trends,
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_costs' => round($totalCosts, 2),
                'net_profit' => $netProfit,
                'margin_percent' => $totalRevenue > 0
                    ? round(($netProfit / $totalRevenue) * 100, 1)
                    : 0,
            ],
        ];
    }

    private function financialBucketKey(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->format('Y-m');
        }

        if ($date === null) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m');
        } catch (\Throwable) {
            return null;
        }
    }

    private function salesOrderRevenue(SalesOrder $order): float
    {
        $qty = (int) ($order->fulfilled_qty ?? 0);
        if ($qty < 1 && in_array($order->status, ['fulfilled', 'partial'], true)) {
            $qty = (int) $order->quantity;
        }
        if ($qty < 1) {
            return 0.0;
        }

        $unitPrice = (float) ($order->unit_price ?? 0);

        return round($qty * $unitPrice, 2);
    }

    private function purchaseOrderTotal(PurchaseOrder $purchaseOrder): float
    {
        $total = (float) ($purchaseOrder->total_amount ?? 0);
        if ($total > 0) {
            return round($total, 2);
        }

        $items = $purchaseOrder->items ?? [];
        if (! is_array($items)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['purchase_price'] ?? 0);
            $sum += $qty * $price;
        }

        return round($sum, 2);
    }

    private function jobWorkTotal(JobWork $jobWork): float
    {
        $total = (float) ($jobWork->total_amount ?? 0);
        if ($total > 0) {
            return round($total, 2);
        }

        return round(
            (float) ($jobWork->quantity ?? 0) * (float) ($jobWork->rate ?? 0),
            2,
        );
    }

    public function recentActivities(): JsonResponse
    {
        $activities = collect();

        $checkins = InventoryTransaction::query()
            ->latest()
            ->limit(5)
            ->get();

        foreach ($checkins as $transaction) {
            $type = $transaction->type === 'checkout' ? 'checkout' : 'checkin';
            $activities->push([
                'type' => $type,
                'title' => $type === 'checkout' ? 'Material checked out' : 'Material checked in',
                'desc' => $transaction->material_name.' · '.$transaction->quantity.' '.$transaction->unit,
                'user' => $transaction->supplier ?? 'System',
                'time' => $transaction->created_at?->format('Y-m-d H:i') ?? '—',
            ]);
        }

        $batches = ProductionBatch::query()
            ->latest()
            ->limit(5)
            ->get();

        foreach ($batches as $batch) {
            $activities->push([
                'type' => 'workflow',
                'title' => 'Production batch '.$batch->status,
                'desc' => $batch->product_name.' · '.$batch->batch_number,
                'user' => $batch->assigned_to ?? $batch->user_name ?? 'System',
                'time' => ($batch->updated_at ?? $batch->created_at)?->format('Y-m-d H:i') ?? '—',
            ]);
        }

        $rejections = Rejection::query()
            ->latest()
            ->limit(5)
            ->get();

        foreach ($rejections as $rejection) {
            $activities->push([
                'type' => 'reject',
                'title' => 'Rejection recorded',
                'desc' => $rejection->product_name.' · '.$rejection->quantity.' units at '.$rejection->stage,
                'user' => $rejection->reported_by ?? 'System',
                'time' => ($rejection->rejection_date ?? $rejection->created_at)?->format('Y-m-d H:i') ?? '—',
            ]);
        }

        return response()->json(
            $activities
                ->sortByDesc('time')
                ->take(10)
                ->values()
                ->all(),
        );
    }

    public function stageFlow(): JsonResponse
    {
        $active = ProductionBatch::query()
            ->where('status', '!=', 'completed')
            ->get();

        if ($active->isEmpty()) {
            return response()->json([]);
        }

        $counts = [];
        foreach ($active as $batch) {
            $stage = $batch->current_stage ?: 'Unknown';
            $counts[$stage] = ($counts[$stage] ?? 0) + (int) ($batch->quantity ?: 1);
        }

        $total = array_sum($counts);
        $colors = [0xFF4B8FE8, 0xFF43A047, 0xFF6A1B9A, 0xFFEF6C00, 0xFF546E7A, 0xFF00897B, 0xFFE53935];
        $i = 0;

        $flow = [];
        foreach ($counts as $stage => $count) {
            $flow[] = [
                'stage' => $stage,
                'count' => $count,
                'percent' => $total === 0 ? 0 : (int) round(($count / $total) * 100),
                'color' => $colors[$i % count($colors)],
            ];
            $i++;
        }

        return response()->json($flow);
    }

    public function inventoryTrends(): JsonResponse
    {
        $inventory = InventoryItem::query()->get();

        if ($inventory->isEmpty()) {
            return response()->json([]);
        }

        $totals = [];
        foreach ($inventory as $item) {
            $label = $this->inventoryTypeLabel($item->type);
            $totals[$label] = ($totals[$label] ?? 0) + (int) $item->quantity;
        }

        $colors = [0xFF4B8FE8, 0xFF43A047, 0xFF6A1B9A, 0xFFEF6C00, 0xFF546E7A];
        $i = 0;
        $trends = [];

        foreach ($totals as $category => $value) {
            $trends[] = [
                'category' => $category,
                'value' => $value,
                'color' => $colors[$i % count($colors)],
            ];
            $i++;
        }

        return response()->json($trends);
    }

    public function clientOrders(): JsonResponse
    {
        $salesOrders = Schema::hasTable('sales_orders')
            ? SalesOrder::query()->latest()->get()
            : collect();

        $workOrders = WorkOrder::query()->latest()->get();
        $clients = Schema::hasTable('clients')
            ? Client::query()->orderBy('name')->get()
            : collect();

        $summary = [
            'total_orders' => 0,
            'open' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'pipeline_value' => 0.0,
            'total_clients' => $clients->count(),
            'clients_ordering' => 0,
            'clients_not_ordering' => 0,
        ];

        $statsByClientId = [];
        $statsByClientName = [];
        $recent = [];

        foreach ($salesOrders as $order) {
            $bucket = $this->salesOrderStatusBucket($order->status);
            $summary[$bucket]++;
            $summary['total_orders']++;

            $remainingQty = max(0, (int) $order->quantity - (int) ($order->fulfilled_qty ?? 0));
            $unitPrice = (float) ($order->unit_price ?? 0);
            if ($bucket !== 'completed' && $bucket !== 'cancelled' && $unitPrice > 0) {
                $summary['pipeline_value'] += round($unitPrice * $remainingQty, 2);
            }

            $orderDate = $order->order_date ?? $order->created_at;
            $this->accumulateClientOrderStats(
                $statsByClientId,
                $statsByClientName,
                $order->client_id,
                $order->client_name,
                $bucket,
                (int) $order->quantity,
                $orderDate,
            );

            $recent[] = $this->formatClientOrderRecentRow(
                id: $order->id,
                orderNumber: $order->order_number,
                clientId: $order->client_id,
                clientName: $order->client_name,
                product: $order->product_name,
                quantity: (int) $order->quantity,
                unit: $order->unit ?? 'pcs',
                status: $order->status,
                statusLabel: $this->salesOrderStatusLabel($order->status),
                orderDate: $orderDate,
            );
        }

        foreach ($workOrders as $order) {
            $bucket = $this->workOrderStatusBucket($order->supervisor_status, $order->flow_step);
            $summary[$bucket]++;
            $summary['total_orders']++;

            $orderDate = $order->created_at;
            $this->accumulateClientOrderStats(
                $statsByClientId,
                $statsByClientName,
                $order->client_id,
                $order->client_name,
                $bucket,
                (int) $order->quantity,
                $orderDate,
            );

            $recent[] = $this->formatClientOrderRecentRow(
                id: $order->id,
                orderNumber: $order->route_card_no ?: ('WO-'.$order->id),
                clientId: $order->client_id,
                clientName: $order->client_name,
                product: $order->product,
                quantity: (int) $order->quantity,
                unit: $order->unit ?? 'pcs',
                status: $order->supervisor_status ?? 'pending',
                statusLabel: $this->workOrderStatusLabel($order->supervisor_status, $order->flow_step),
                orderDate: $orderDate,
            );
        }

        $summary['pipeline_value'] = round($summary['pipeline_value'], 2);

        $clientFrequency = [];
        foreach ($clients as $client) {
            $stats = $this->resolveClientOrderStats(
                $statsByClientId,
                $statsByClientName,
                $client->id,
                $client->name,
            );

            $orderCount = (int) ($stats['order_count'] ?? 0);
            $hasOrders = $orderCount > 0;

            if ($hasOrders) {
                $summary['clients_ordering']++;
            } else {
                $summary['clients_not_ordering']++;
            }

            $clientFrequency[] = [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'client_status' => $client->status ?? 'active',
                'order_count' => $orderCount,
                'has_orders' => $hasOrders,
                'last_order_date' => isset($stats['last_order_at'])
                    ? Carbon::createFromTimestamp($stats['last_order_at'])->format('Y-m-d')
                    : ($client->last_order_date?->format('Y-m-d')),
                'open_count' => (int) ($stats['open_count'] ?? 0),
                'in_progress_count' => (int) ($stats['in_progress_count'] ?? 0),
            ];
        }

        usort(
            $clientFrequency,
            function (array $left, array $right): int {
                $leftHas = ($left['has_orders'] ?? false) ? 1 : 0;
                $rightHas = ($right['has_orders'] ?? false) ? 1 : 0;
                if ($leftHas !== $rightHas) {
                    return $rightHas <=> $leftHas;
                }
                if ($leftHas === 1) {
                    return ((int) ($right['order_count'] ?? 0)) <=> ((int) ($left['order_count'] ?? 0));
                }

                return strcasecmp((string) ($left['client_name'] ?? ''), (string) ($right['client_name'] ?? ''));
            },
        );

        $byStatus = [
            ['label' => 'Open', 'key' => 'open', 'count' => $summary['open']],
            ['label' => 'In Progress', 'key' => 'in_progress', 'count' => $summary['in_progress']],
            ['label' => 'Completed', 'key' => 'completed', 'count' => $summary['completed']],
            ['label' => 'Cancelled', 'key' => 'cancelled', 'count' => $summary['cancelled']],
        ];

        usort($recent, fn (array $left, array $right) => $right['sort_at'] <=> $left['sort_at']);
        $recent = array_map(function (array $row) {
            unset($row['sort_at']);

            return $row;
        }, array_slice($recent, 0, 10));

        return response()->json([
            'summary' => $summary,
            'by_status' => $byStatus,
            'client_frequency' => $clientFrequency,
            'recent' => $recent,
        ]);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $statsByClientId
     * @param  array<string, array<string, mixed>>  $statsByClientName
     */
    private function accumulateClientOrderStats(
        array &$statsByClientId,
        array &$statsByClientName,
        mixed $clientId,
        ?string $clientName,
        string $bucket,
        int $quantity,
        mixed $orderDate,
    ): void {
        $timestamp = $this->orderTimestamp($orderDate);
        $target = &$this->clientStatsRef($statsByClientId, $statsByClientName, $clientId, $clientName);

        $target['order_count'] = ((int) ($target['order_count'] ?? 0)) + 1;
        $target['total_qty'] = ((int) ($target['total_qty'] ?? 0)) + $quantity;

        if ($bucket === 'open') {
            $target['open_count'] = ((int) ($target['open_count'] ?? 0)) + 1;
        } elseif ($bucket === 'in_progress') {
            $target['in_progress_count'] = ((int) ($target['in_progress_count'] ?? 0)) + 1;
        }

        if ($timestamp >= (int) ($target['last_order_at'] ?? 0)) {
            $target['last_order_at'] = $timestamp;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $statsByClientId
     * @param  array<string, array<string, mixed>>  $statsByClientName
     * @return array<string, mixed>
     */
    private function resolveClientOrderStats(
        array $statsByClientId,
        array $statsByClientName,
        mixed $clientId,
        ?string $clientName,
    ): array {
        if ($clientId !== null && $clientId !== '' && isset($statsByClientId[(string) $clientId])) {
            return $statsByClientId[(string) $clientId];
        }

        $normalizedName = $this->normalizedClientName($clientName);
        if ($normalizedName !== '' && isset($statsByClientName[$normalizedName])) {
            return $statsByClientName[$normalizedName];
        }

        return [
            'order_count' => 0,
            'open_count' => 0,
            'in_progress_count' => 0,
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $statsByClientId
     * @param  array<string, array<string, mixed>>  $statsByClientName
     * @return array<string, mixed>
     */
    private function &clientStatsRef(
        array &$statsByClientId,
        array &$statsByClientName,
        mixed $clientId,
        ?string $clientName,
    ): array {
        if ($clientId !== null && $clientId !== '') {
            $key = (string) $clientId;
            if (! isset($statsByClientId[$key])) {
                $statsByClientId[$key] = [
                    'client_id' => $clientId,
                    'client_name' => $clientName,
                    'order_count' => 0,
                    'total_qty' => 0,
                    'open_count' => 0,
                    'in_progress_count' => 0,
                    'last_order_at' => 0,
                ];
            }

            return $statsByClientId[$key];
        }

        $normalizedName = $this->normalizedClientName($clientName);
        if ($normalizedName === '') {
            if (! isset($statsByClientName['__unknown__'])) {
                $statsByClientName['__unknown__'] = [
                    'client_name' => 'Unknown Client',
                    'order_count' => 0,
                    'total_qty' => 0,
                    'open_count' => 0,
                    'in_progress_count' => 0,
                    'last_order_at' => 0,
                ];
            }

            return $statsByClientName['__unknown__'];
        }

        if (! isset($statsByClientName[$normalizedName])) {
            $statsByClientName[$normalizedName] = [
                'client_name' => trim((string) $clientName),
                'order_count' => 0,
                'total_qty' => 0,
                'open_count' => 0,
                'in_progress_count' => 0,
                'last_order_at' => 0,
            ];
        }

        return $statsByClientName[$normalizedName];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatClientOrderRecentRow(
        int $id,
        ?string $orderNumber,
        mixed $clientId,
        ?string $clientName,
        ?string $product,
        int $quantity,
        string $unit,
        ?string $status,
        string $statusLabel,
        mixed $orderDate,
    ): array {
        $timestamp = $this->orderTimestamp($orderDate);

        return [
            'id' => $id,
            'order_number' => $orderNumber ?: '—',
            'client_id' => $clientId,
            'client_name' => $clientName ?: '—',
            'product' => $product ?: '—',
            'quantity' => $quantity,
            'unit' => $unit,
            'status' => $status,
            'status_label' => $statusLabel,
            'order_date' => $timestamp > 0
                ? Carbon::createFromTimestamp($timestamp)->format('Y-m-d')
                : '—',
            'sort_at' => $timestamp,
        ];
    }

    private function orderTimestamp(mixed $orderDate): int
    {
        if ($orderDate instanceof Carbon) {
            return $orderDate->timestamp;
        }

        if ($orderDate === null) {
            return 0;
        }

        try {
            return Carbon::parse($orderDate)->timestamp;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function normalizedClientName(?string $clientName): string
    {
        $name = strtolower(trim((string) $clientName));

        if ($name === '' || $name === '—' || $name === '-') {
            return '';
        }

        return $name;
    }

    private function salesOrderStatusBucket(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'open' => 'open',
            'in_production', 'partial' => 'in_progress',
            'fulfilled' => 'completed',
            'cancelled' => 'cancelled',
            default => 'open',
        };
    }

    private function salesOrderStatusLabel(?string $status): string
    {
        $normalized = strtolower(str_replace('_', ' ', (string) $status));

        return $normalized === '' ? 'Open' : ucwords($normalized);
    }

    private function workOrderStatusBucket(?string $supervisorStatus, ?string $flowStep): string
    {
        $status = strtolower((string) $supervisorStatus);
        $step = strtolower((string) $flowStep);

        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return 'cancelled';
        }

        if ($status === 'approved' || str_contains($step, 'production') || str_contains($step, 'batch')) {
            return 'in_progress';
        }

        if ($status === 'completed' || str_contains($step, 'complete')) {
            return 'completed';
        }

        return 'open';
    }

    private function workOrderStatusLabel(?string $supervisorStatus, ?string $flowStep): string
    {
        $status = strtolower((string) $supervisorStatus);
        if ($status === 'rejected') {
            return 'Rejected';
        }
        if ($status === 'approved') {
            return 'Approved';
        }
        if (filled($flowStep)) {
            return ucwords(str_replace('_', ' ', (string) $flowStep));
        }

        return ucwords(str_replace('_', ' ', $status ?: 'pending'));
    }

    public function tasks(Request $request): JsonResponse
    {
        $query = DashboardTask::query()->latest();
        $user = $request->user();

        if ($user !== null && ! $this->canViewAllTasks($user)) {
            $query->where(function ($builder) use ($user): void {
                $builder
                    ->where('assigned_to_user_id', $user->id)
                    ->orWhere(function ($fallback) use ($user): void {
                        $fallback
                            ->whereNull('assigned_to_user_id')
                            ->where('assigned_to', $user->name);
                    });
            });
        }

        if ($this->canLoadTaskMaterials()) {
            $query->with(['materials.inventoryItem']);
        }

        return response()->json(
            $query->get()->map(fn (DashboardTask $task) => $this->formatTask($task)),
        );
    }

    public function assignees(): JsonResponse
    {
        $assignees = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'department' => $user->department ?? '—',
            ]);

        return response()->json($assignees);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user !== null && ! $this->canViewAllTasks($user)) {
            return response()->json([
                'message' => 'Only administrators can assign tasks to others',
            ], 403);
        }

        $validated = $request->validate([
            'task_name' => 'required|string|max:255',
            'assigned_to' => 'required|string|max:255',
            'assigned_to_user_id' => 'nullable|integer|exists:users,id',
            'module' => 'required|string|max:255',
            'status' => 'nullable|string|in:pending,in_progress,on_hold,completed',
            'activity' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $task = DashboardTask::query()->create([
            'task_name' => $validated['task_name'],
            'assigned_to' => $validated['assigned_to'],
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'module' => $validated['module'],
            'status' => $validated['status'] ?? 'pending',
            'activity' => $validated['activity'] ?? 'Task assigned — awaiting start',
            'due_date' => $validated['due_date'] ?? null,
        ]);

        if ($this->canLoadTaskMaterials()) {
            $task->load(['materials.inventoryItem']);
        }

        try {
            AppNotificationService::taskAssigned(
                taskName: $task->task_name,
                assignedToName: $task->assigned_to,
                module: $task->module,
                activity: $task->activity,
                taskId: $task->id,
                dueDate: $task->due_date?->format('Y-m-d'),
                assigneeUserId: $validated['assigned_to_user_id'] ?? null,
            );
        } catch (\Throwable) {
            // Task is saved; notification failure must not break API.
        }

        return response()->json($this->formatTask($task), 201);
    }

    public function updateTask(Request $request, DashboardTask $task): JsonResponse
    {
        $user = $request->user();
        if ($user !== null
            && ! $this->canViewAllTasks($user)
            && ! $this->taskAssignedToUser($task, $user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'task_name' => 'sometimes|string|max:255',
            'assigned_to' => 'sometimes|string|max:255',
            'assigned_to_user_id' => 'nullable|integer|exists:users,id',
            'module' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:pending,in_progress,on_hold,completed',
            'activity' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        if ($user !== null && ! $this->canViewAllTasks($user)) {
            unset($validated['assigned_to'], $validated['assigned_to_user_id'], $validated['task_name'], $validated['module']);
        }

        $task->update($validated);

        return response()->json($this->formatTask($task->fresh()));
    }

    private function canViewAllTasks(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return ($user->designation ?? '') === 'admin_staff';
    }

    private function taskAssignedToUser(DashboardTask $task, User $user): bool
    {
        if ($task->assigned_to_user_id !== null) {
            return (int) $task->assigned_to_user_id === (int) $user->id;
        }

        return strcasecmp(trim($task->assigned_to), trim($user->name)) === 0;
    }

    private function formatTask(DashboardTask $task): array
    {
        if ($this->canLoadTaskMaterials()) {
            $task->loadMissing(['materials.inventoryItem']);
        }

        return [
            'id' => $task->id,
            'task_name' => $task->task_name,
            'assigned_to' => $task->assigned_to,
            'assigned_to_user_id' => $task->assigned_to_user_id,
            'module' => $task->module,
            'status' => $task->status,
            'activity' => $task->activity,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'updated_at' => $task->updated_at?->format('Y-m-d H:i') ?? '—',
            'materials' => $this->formatTaskMaterials($task),
        ];
    }

    private function taskMaterialsTableExists(): bool
    {
        return Schema::hasTable('dashboard_task_materials');
    }

    private function canLoadTaskMaterials(): bool
    {
        return $this->taskMaterialsTableExists()
            && is_callable([DashboardTask::class, 'materials']);
    }

    private function formatTaskMaterials(DashboardTask $task): array
    {
        if (! $this->canLoadTaskMaterials()) {
            return [];
        }

        return $task->materials->map(fn ($line) => [
            'inventory_item_id' => $line->inventory_item_id,
            'name' => $line->inventoryItem?->name ?? '—',
            'quantity' => $line->quantity,
            'unit' => $line->unit ?? $line->inventoryItem?->unit ?? 'pcs',
            'sku' => $line->inventoryItem?->sku,
        ])->values()->all();
    }

    private function gaugeKpiStats($gauges): array
    {
        if ($gauges->isEmpty()) {
            return [
                'total_instruments' => 0,
                'due_soon_instruments' => 0,
                'overdue_instruments' => 0,
                'calibration_compliance' => 0,
            ];
        }

        $dueSoon = 0;
        $overdue = 0;
        $active = 0;
        $today = Carbon::today();

        foreach ($gauges as $gauge) {
            $status = $this->resolveGaugeStatus($gauge, $today);
            if ($status === 'overdue') {
                $overdue++;
            } elseif ($status === 'due_soon') {
                $dueSoon++;
            } elseif ($status === 'active') {
                $active++;
            }
        }

        return [
            'total_instruments' => $gauges->count(),
            'due_soon_instruments' => $dueSoon,
            'overdue_instruments' => $overdue,
            'calibration_compliance' => (int) round(($active / $gauges->count()) * 100),
        ];
    }

    private function resolveGaugeStatus(Gauge $gauge, Carbon $today): string
    {
        if ($gauge->status === 'under_maintenance') {
            return 'under_maintenance';
        }

        $nextDue = $gauge->next_calibration_date;
        if (! $nextDue) {
            return 'active';
        }

        $days = $today->diffInDays($nextDue, false);
        if ($days < 0) {
            return 'overdue';
        }
        if ($days <= 30) {
            return 'due_soon';
        }

        return 'active';
    }

    private function emptyMonthlyBuckets(int $months = 6): array
    {
        $buckets = [];
        $now = Carbon::now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i)->startOfMonth();
            $key = $date->format('Y-m');
            $buckets[$key] = [
                'month' => $date->format('M'),
                'produced' => 0,
                'rejected' => 0,
            ];
        }

        return $buckets;
    }

    private function emptyFinancialBuckets(int $months = 6): array
    {
        $buckets = [];
        $now = Carbon::now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i)->startOfMonth();
            $key = $date->format('Y-m');
            $buckets[$key] = [
                'month' => $date->format('M'),
                'revenue' => 0.0,
                'costs' => 0.0,
            ];
        }

        return $buckets;
    }

    private function inventoryTypeLabel(?string $type): string
    {
        $normalized = strtolower(str_replace(' ', '_', $type ?? 'other'));

        return match (true) {
            str_contains($normalized, 'raw') => 'Raw Material',
            str_contains($normalized, 'wip') => 'Work In Progress',
            str_contains($normalized, 'finished') => 'Finished Goods',
            default => strtoupper(str_replace('_', ' ', $normalized)),
        };
    }
}
