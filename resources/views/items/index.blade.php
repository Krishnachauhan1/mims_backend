@extends('layouts.app')

@section('title', 'Items')
@section('page-title', 'Item Master')

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ route('web.items.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search items…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'items.manage'))
        <a href="{{ route('web.items.create') }}" class="btn btn-primary">Add Item</a>
    @endif
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Category</th>
                <th>Grade</th>
                <th>Unit</th>
                <th>Purchase Price</th>
                <th>Min Stock</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item->code }}</td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->category ?: '—' }}</td>
                    <td>{{ $item->grade ?: '—' }}</td>
                    <td>{{ $item->unit ?: '—' }}</td>
                    <td>{{ $item->purchase_price !== null ? number_format($item->purchase_price, 2) : '—' }}</td>
                    <td>{{ $item->min_stock_alert ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $item->status === 'active' ? 'badge-active' : 'badge-inactive' }}">
                            {{ $item->status }}
                        </span>
                    </td>
                    <td>
                        @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'items.manage'))
                            <a class="btn btn-secondary btn-sm" href="{{ route('web.items.edit', $item) }}">Edit</a>
                            <form method="POST" action="{{ route('web.items.toggle', $item) }}" style="display:inline;">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-danger btn-sm" type="submit">
                                    {{ $item->status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">No items found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">{{ $items->links() }}</div>
</div>
@endsection
