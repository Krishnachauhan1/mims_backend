@extends('layouts.app')

@section('title', 'Workflow '.$order->route_card_no)
@section('page-title', $order->route_card_no ?: 'Route Card')

@section('content')
<div class="toolbar">
    <a href="{{ route('web.workflow.index') }}" class="btn btn-secondary">← Back to list</a>
</div>

<div class="card section-card">
    <div class="card-header"><h2>Route Card Details</h2></div>
    <div style="padding:20px;">
        <div class="detail-grid">
            <div><span class="detail-label">Route Card</span><strong>{{ $order->route_card_no ?: 'WO-'.$order->id }}</strong></div>
            <div><span class="detail-label">Template</span><strong>{{ $order->workflow_name ?: '—' }}</strong></div>
            <div><span class="detail-label">Product</span><strong>{{ $order->product ?: '—' }}</strong></div>
            <div><span class="detail-label">Client</span><strong>{{ $order->client_name ?: '—' }}</strong></div>
            <div><span class="detail-label">Quantity</span><strong>{{ $order->quantity }} {{ $order->unit }}</strong></div>
            <div><span class="detail-label">Status</span>
                <span class="badge badge-info">{{ str_replace('_', ' ', $order->supervisor_status) }}</span>
            </div>
            <div><span class="detail-label">Priority</span><strong>{{ ucfirst($order->priority ?? 'normal') }}</strong></div>
            <div><span class="detail-label">Created by</span><strong>{{ $order->created_by ?: '—' }}</strong></div>
        </div>
        @if ($order->remarks)
            <p style="margin-top:16px;"><span class="detail-label">Remarks</span><br>{{ $order->remarks }}</p>
        @endif
    </div>
</div>

@if (! empty($order->stages))
<div class="card section-card" style="margin-top:16px;">
    <div class="card-header"><h2>Stages</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Stage</th>
                    <th>Machine</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->stages as $stage)
                    <tr>
                        <td>{{ $stage['order'] ?? '—' }}</td>
                        <td>{{ $stage['name'] ?? '—' }}</td>
                        <td>{{ $stage['machine_name'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
