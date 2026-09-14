<?php

namespace App\Services;

use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Models\Client;
use App\Models\FinishedGood;
use App\Models\InventoryItem;
use App\Models\Machinery;
use App\Models\ProductionBatch;
use App\Models\Rejection;
use App\Models\SalesOrder;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class DashboardWebService
{
    /** @var list<array{id: string, title: string, subtitle: string, color: string, icon: string, route: string}> */
    public const REPORT_CATALOG = [
        [
            'id' => 'finished_goods',
            'title' => 'Finished Goods',
            'subtitle' => 'Production, stock & dispatch by product',
            'color' => '#4B8FE8',
            'icon' => 'check_circle',
            'route' => 'web.resource.finished-goods',
        ],
        [
            'id' => 'rejection',
            'title' => 'Rejection Analysis',
            'subtitle' => 'Quality failures, stage & reason trends',
            'color' => '#E53935',
            'icon' => 'cancel',
            'route' => 'web.resource.rejection',
        ],
        [
            'id' => 'inventory',
            'title' => 'Inventory',
            'subtitle' => 'Stock levels, movement & low stock',
            'color' => '#43A047',
            'icon' => 'inventory_2',
            'route' => 'web.resource.inventory',
        ],
        [
            'id' => 'production',
            'title' => 'Production',
            'subtitle' => 'Batch output, stages & completion time',
            'color' => '#6A1B9A',
            'icon' => 'precision_manufacturing',
            'route' => 'web.resource.production',
        ],
        [
            'id' => 'vendor',
            'title' => 'Vendor',
            'subtitle' => 'Historical purchases & supplier trends',
            'color' => '#EF6C00',
            'icon' => 'storefront',
            'route' => 'web.vendors.index',
        ],
        [
            'id' => 'customer',
            'title' => 'Customer',
            'subtitle' => 'Sales history & revenue after dispatch',
            'color' => '#00897B',
            'icon' => 'groups',
            'route' => 'web.clients.index',
        ],
        [
            'id' => 'sales_tracking',
            'title' => 'Sales Tracking',
            'subtitle' => 'Completed products ready for sale & dispatch',
            'color' => '#1565C0',
            'icon' => 'local_shipping',
            'route' => 'web.resource.sales-tracking',
        ],
        [
            'id' => 'most_selling',
            'title' => 'Most Selling Products',
            'subtitle' => 'Top products by sold quantity & revenue',
            'color' => '#2E7D32',
            'icon' => 'trending_up',
            'route' => 'web.resource.sales-tracking',
        ],
        [
            'id' => 'machine_active_work',
            'title' => 'Machine Active Work',
            'subtitle' => 'Which machine is running which batch & product',
            'color' => '#3949AB',
            'icon' => 'settings',
            'route' => 'web.machinery.index',
        ],
    ];

    public function __construct(
        private readonly ApiDashboardController $apiDashboard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $kpis = $this->decode($this->apiDashboard->kpis());
        $productionTrends = $this->decodeList($this->apiDashboard->productionTrends());
        $dailyProductionTrends = $this->decodeList($this->apiDashboard->dailyProductionTrends());
        $profitLossPayload = $this->decode($this->apiDashboard->profitLossTrends());
        $inventoryTrends = $this->decodeList($this->apiDashboard->inventoryTrends());
        $stageFlow = $this->decodeList($this->apiDashboard->stageFlow());
        $recentActivities = $this->decodeList($this->apiDashboard->recentActivities());
        $clientOrders = $this->decode($this->apiDashboard->clientOrders());
        $lowStockAlerts = $this->decodeList($this->apiDashboard->lowStockAlerts());

        $reportCounts = $this->reportCounts();
        $maxReportCount = max(1, ...array_values($reportCounts));

        return [
            'kpis' => $kpis,
            'operationsKpis' => $this->operationsKpis($kpis),
            'productionTrends' => $productionTrends,
            'dailyProductionTrends' => $dailyProductionTrends,
            'profitLossTrends' => $profitLossPayload['trends'] ?? [],
            'profitLossSummary' => $profitLossPayload['summary'] ?? [],
            'inventoryTrends' => $inventoryTrends,
            'stageFlow' => $stageFlow,
            'recentActivities' => $recentActivities,
            'clientOrdersSummary' => $clientOrders['summary'] ?? [],
            'lowStockAlerts' => $lowStockAlerts,
            'reportCatalog' => self::REPORT_CATALOG,
            'reportCounts' => $reportCounts,
            'maxReportCount' => $maxReportCount,
            'totalReportRecords' => array_sum($reportCounts),
        ];
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @return list<array{label: string, value: int|string, icon: string, palette: int}>
     */
    private function operationsKpis(array $kpis): array
    {
        return [
            ['label' => 'Total Inventory', 'value' => (int) ($kpis['total_inventory'] ?? 0), 'icon' => 'warehouse', 'palette' => 0],
            ['label' => 'Raw Material', 'value' => (int) ($kpis['raw_material'] ?? 0), 'icon' => 'inventory', 'palette' => 1],
            ['label' => 'Work In Progress', 'value' => (int) ($kpis['wip'] ?? 0), 'icon' => 'settings', 'palette' => 2],
            ['label' => 'Finished Goods', 'value' => (int) ($kpis['finished_goods'] ?? 0), 'icon' => 'check_circle', 'palette' => 3],
            ['label' => 'Rejected Items', 'value' => (int) ($kpis['rejected'] ?? 0), 'icon' => 'cancel', 'palette' => 4],
            ['label' => 'Daily Production', 'value' => (int) ($kpis['daily_production'] ?? 0), 'icon' => 'today', 'palette' => 5],
            ['label' => 'Monthly Production', 'value' => (int) ($kpis['monthly_production'] ?? 0), 'icon' => 'calendar_month', 'palette' => 0],
            ['label' => 'Inventory Movements', 'value' => (int) ($kpis['inventory_movement'] ?? 0), 'icon' => 'swap_horiz', 'palette' => 1],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function reportCounts(): array
    {
        $salesCount = Schema::hasTable('sales_orders') ? SalesOrder::query()->count() : 0;
        $activeBatches = ProductionBatch::query()
            ->where('status', '!=', 'completed')
            ->count();

        return [
            'finished_goods' => FinishedGood::query()->count(),
            'rejection' => Rejection::query()->count(),
            'inventory' => InventoryItem::query()->count(),
            'production' => ProductionBatch::query()->count(),
            'vendor' => Vendor::query()->count(),
            'customer' => Client::query()->count(),
            'sales_tracking' => $salesCount,
            'most_selling' => $salesCount > 0 ? $salesCount : FinishedGood::query()->count(),
            'machine_active_work' => max(Machinery::query()->count(), $activeBatches),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        $decoded = json_decode($response->getContent(), true);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeList(JsonResponse $response): array
    {
        $decoded = json_decode($response->getContent(), true);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return array_values($decoded['data']);
        }

        return array_is_list($decoded) ? $decoded : [];
    }
}
