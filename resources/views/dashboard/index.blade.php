@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
@php
    $user = auth()->user();
    $summary = $profitLossSummary ?? [];
    $maxDaily = max(1, collect($dailyProductionTrends ?? [])->max('produced') ?: 1);
    $maxMonthly = max(1, collect($productionTrends ?? [])->max('produced') ?: 1);
    $maxInv = max(1, collect($inventoryTrends ?? [])->max('value') ?: 1);
    $maxPl = max(1, collect($profitLossTrends ?? [])->max(fn ($r) => max($r['revenue'] ?? 0, $r['costs'] ?? 0)) ?: 1);
@endphp

@push('head')
<style>.app-header { display: none; }</style>
@endpush

<section class="dash-hero">
    <div class="dash-hero-inner">
        <div>
            <p class="dash-hero-kicker">Welcome back,</p>
            <h2 class="dash-hero-name">{{ $user->name ?? 'User' }}</h2>
            <span class="dash-hero-badge">
                <span class="dash-live-dot"></span>
                {{ ucfirst($user->role ?? 'staff') }} · Live dashboard
            </span>
        </div>
    </div>
</section>

@if (!empty($lowStockAlerts))
<section class="dash-alert card section-card">
    <div class="dash-alert-head">
        <span class="material-symbols-outlined dash-alert-icon">warning</span>
        <div>
            <h3>Low Stock Alert</h3>
            <p class="text-muted">{{ count($lowStockAlerts) }} material(s) at or below safety stock</p>
        </div>
        <a href="{{ route('web.resource.inventory') }}" class="btn btn-sm btn-outline">View Inventory</a>
    </div>
    <ul class="dash-alert-list">
        @foreach (array_slice($lowStockAlerts, 0, 6) as $item)
            <li>
                <strong>{{ $item['name'] ?? '—' }}</strong>
                <span>{{ ($item['quantity'] ?? 0) }} / {{ ($item['min_stock'] ?? 0) }} {{ $item['unit'] ?? 'pcs' }}</span>
            </li>
        @endforeach
    </ul>
</section>
@endif

<div class="section-heading">
    <h2>Operations Overview</h2>
    <p class="text-muted">Key metrics across your manufacturing floor</p>
</div>

<div class="kpi-grid">
    @foreach ($operationsKpis as $kpi)
        <div class="kpi-card palette-{{ $kpi['palette'] % 6 }}">
            <div class="kpi-card-top">
                <div class="kpi-icon-wrap">
                    <span class="material-symbols-outlined">{{ $kpi['icon'] }}</span>
                </div>
            </div>
            <div class="kpi-value">{{ number_format((int) $kpi['value']) }}</div>
            <div class="kpi-label">{{ $kpi['label'] }}</div>
        </div>
    @endforeach
</div>

<section class="dash-reports-panel section-card">
    <div class="dash-panel-head">
        <div class="dash-panel-title">
            <span class="dash-panel-icon reports"><span class="material-symbols-outlined">analytics</span></span>
            <div>
                <h2>Reports & Analytics</h2>
                <p class="text-muted">Tap a report card to open detailed analytics</p>
            </div>
        </div>
        <span class="dash-pill">{{ number_format($totalReportRecords) }} records</span>
    </div>
    <div class="dash-reports-grid">
        @foreach ($reportCatalog as $report)
            @php
                $count = $reportCounts[$report['id']] ?? 0;
                $ratio = $maxReportCount > 0 ? max(5, (int) round(($count / $maxReportCount) * 100)) : 5;
            @endphp
            <a href="{{ Route::has($report['route']) ? route($report['route']) : url('/'.$report['id']) }}"
               class="dash-report-tile" style="--accent: {{ $report['color'] }}">
                <div class="dash-report-ring" style="--pct: {{ $ratio }}">
                    <span class="material-symbols-outlined">{{ $report['icon'] }}</span>
                </div>
                <div class="dash-report-copy">
                    <strong>{{ $report['title'] }}</strong>
                    <span>{{ $report['subtitle'] }}</span>
                    <em>{{ number_format($count) }} records</em>
                </div>
            </a>
        @endforeach
    </div>
</section>

<section class="dash-charts">
    <div class="section-heading">
        <h2>Charts & Trends</h2>
        <p class="text-muted">Production, profit & loss, and inventory trends</p>
    </div>

    <div class="dash-chart-grid">
        <div class="card section-card dash-chart-card">
            <div class="dash-chart-head">
                <h3>Profit & Loss</h3>
                <div class="dash-mini-stats">
                    <span>Revenue <strong>₹{{ number_format($summary['total_revenue'] ?? 0, 0) }}</strong></span>
                    <span>Costs <strong>₹{{ number_format($summary['total_costs'] ?? 0, 0) }}</strong></span>
                    <span>Net <strong class="{{ ($summary['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">₹{{ number_format($summary['net_profit'] ?? 0, 0) }}</strong></span>
                </div>
            </div>
            <div class="trend-chart trend-chart-dual">
                @forelse ($profitLossTrends as $row)
                    @php
                        $revH = (int) round((($row['revenue'] ?? 0) / $maxPl) * 100);
                        $costH = (int) round((($row['costs'] ?? 0) / $maxPl) * 100);
                    @endphp
                    <div class="trend-col" title="{{ $row['month'] ?? '' }}">
                        <div class="trend-bars">
                            <div class="trend-bar revenue" style="height: {{ $revH }}%"></div>
                            <div class="trend-bar costs" style="height: {{ $costH }}%"></div>
                        </div>
                        <label>{{ $row['month'] ?? '—' }}</label>
                    </div>
                @empty
                    <p class="empty-cell">No financial trend data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card section-card dash-chart-card">
            <div class="dash-chart-head">
                <h3>Daily Production (7 days)</h3>
                <span class="dash-pill subtle">Today: {{ number_format($kpis['daily_production'] ?? 0) }}</span>
            </div>
            <div class="trend-chart">
                @forelse ($dailyProductionTrends as $row)
                    @php $h = (int) round((($row['produced'] ?? 0) / $maxDaily) * 100); @endphp
                    <div class="trend-col">
                        <div class="trend-bars single">
                            <div class="trend-bar produced" style="height: {{ max(4, $h) }}%"></div>
                        </div>
                        <label>{{ $row['label'] ?? '—' }}</label>
                    </div>
                @empty
                    <p class="empty-cell">No daily production data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card section-card dash-chart-card">
            <div class="dash-chart-head">
                <h3>Monthly Production</h3>
                <span class="dash-pill subtle">This month: {{ number_format($kpis['monthly_production'] ?? 0) }}</span>
            </div>
            <div class="trend-chart">
                @forelse ($productionTrends as $row)
                    @php $h = (int) round((($row['produced'] ?? 0) / $maxMonthly) * 100); @endphp
                    <div class="trend-col">
                        <div class="trend-bars single">
                            <div class="trend-bar produced" style="height: {{ max(4, $h) }}%"></div>
                        </div>
                        <label>{{ $row['month'] ?? '—' }}</label>
                    </div>
                @empty
                    <p class="empty-cell">No monthly production data yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card section-card dash-chart-card">
            <div class="dash-chart-head">
                <h3>Inventory Mix</h3>
            </div>
            <div class="trend-chart">
                @forelse ($inventoryTrends as $row)
                    @php $h = (int) round((($row['value'] ?? 0) / $maxInv) * 100); @endphp
                    <div class="trend-col">
                        <div class="trend-bars single">
                            <div class="trend-bar inventory" style="height: {{ max(4, $h) }}%"></div>
                        </div>
                        <label title="{{ $row['category'] ?? '' }}">{{ Str::limit($row['category'] ?? '—', 8) }}</label>
                    </div>
                @empty
                    <p class="empty-cell">No inventory breakdown yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</section>

@if (!empty($stageFlow))
<section class="card section-card">
    <div class="card-header">
        <h2>Stage-wise Product Flow</h2>
        <p class="text-muted">Items per production stage</p>
    </div>
    <div class="dash-stage-flow">
        @foreach ($stageFlow as $stage)
            @php
                $colorHex = str_pad(dechex((int) ($stage['color'] ?? 0x5C2A4E) & 0xFFFFFF), 6, '0', STR_PAD_LEFT);
            @endphp
            <div class="dash-stage-item">
                <div class="dash-stage-top">
                    <span class="dash-stage-dot" style="background:#{{ $colorHex }}"></span>
                    <strong>{{ $stage['stage'] ?? '—' }}</strong>
                </div>
                <div class="dash-stage-count">{{ $stage['count'] ?? 0 }}</div>
                <div class="dash-stage-bar">
                    <div style="width: {{ $stage['percent'] ?? 0 }}%; background:#{{ $colorHex }}"></div>
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif

@if (!empty($recentActivities))
<section class="card section-card">
    <div class="card-header"><h2>Recent Activity</h2></div>
    <ul class="dash-activity-list">
        @foreach ($recentActivities as $activity)
            <li>
                <span class="dash-activity-icon {{ $activity['type'] ?? 'default' }}">
                    <span class="material-symbols-outlined">
                        @switch($activity['type'] ?? '')
                            @case('checkin') arrow_downward @break
                            @case('checkout') arrow_upward @break
                            @case('workflow') account_tree @break
                            @case('reject') cancel @break
                            @default history
                        @endswitch
                    </span>
                </span>
                <div>
                    <strong>{{ $activity['title'] ?? '—' }}</strong>
                    <p>{{ $activity['desc'] ?? '' }}</p>
                    <small>{{ $activity['user'] ?? 'System' }} · {{ $activity['time'] ?? '' }}</small>
                </div>
            </li>
        @endforeach
    </ul>
</section>
@endif

<div class="card section-card">
    <div class="card-header">
        <h2>Recent Production Batches</h2>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Product</th>
                    <th>Stage</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentBatches as $batch)
                    <tr>
                        <td><strong>{{ $batch->batch_number }}</strong></td>
                        <td>{{ $batch->product ?: '—' }}</td>
                        <td>{{ $batch->current_stage ?: '—' }}</td>
                        <td>
                            <span class="badge badge-{{ $batch->status === 'completed' ? 'success' : ($batch->status === 'on_hold' ? 'warning' : 'info') }}">
                                {{ str_replace('_', ' ', $batch->status) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-cell">No batches yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
