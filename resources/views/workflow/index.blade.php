@extends('layouts.app')

@section('title', 'Create Workflow')
@section('page-title', 'Create Workflow')

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ route('web.workflow.index') }}">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search route cards…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'workflow.move'))
        <a href="{{ route('web.workflow.create') }}" class="btn btn-primary">
            <span class="material-symbols-outlined">add</span>
            New Workflow
        </a>
    @endif
</div>

<div class="tabs">
    @foreach (['all' => 'All', 'draft' => 'Draft', 'pending' => 'Tracking', 'wip' => 'WIP'] as $key => $label)
        <a href="{{ route('web.workflow.index', ['tab' => $key, 'q' => $search]) }}"
           class="tab-link {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="card section-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Route Card</th>
                    <th>Product</th>
                    <th>Client</th>
                    <th>Qty</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td><strong>{{ $order->route_card_no ?: 'WO-'.$order->id }}</strong></td>
                        <td>{{ $order->product ?: '—' }}</td>
                        <td>{{ $order->client_name ?: '—' }}</td>
                        <td>{{ $order->quantity }} {{ $order->unit }}</td>
                        <td>
                            <span class="badge badge-{{ $order->supervisor_status === 'wip' ? 'info' : ($order->supervisor_status === 'draft' ? 'warning' : 'success') }}">
                                {{ str_replace('_', ' ', $order->supervisor_status) }}
                            </span>
                        </td>
                        <td>{{ ucfirst($order->priority ?? 'normal') }}</td>
                        <td>
                            <a class="btn btn-secondary btn-sm" href="{{ route('web.workflow.show', $order) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-cell">No workflows found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">{{ $orders->links() }}</div>
</div>
@endsection
