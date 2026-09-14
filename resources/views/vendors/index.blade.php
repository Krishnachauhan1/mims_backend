@extends('layouts.app')

@section('title', 'Vendors')
@section('page-title', 'Vendor Management')

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ route('web.vendors.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search vendors…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'vendors.manage'))
        <a href="{{ route('web.vendors.create') }}" class="btn btn-primary">Add Vendor</a>
    @endif
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Company</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Phone</th>
                <th>City</th>
                <th>Orders</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($vendors as $vendor)
                <tr>
                    <td>{{ $vendor->name }}</td>
                    <td>{{ $vendor->contact_person ?: '—' }}</td>
                    <td>{{ $vendor->email ?: '—' }}</td>
                    <td>{{ $vendor->phone ?: '—' }}</td>
                    <td>{{ $vendor->city ?: '—' }}</td>
                    <td>{{ $vendor->total_orders ?? 0 }}</td>
                    <td>
                        <span class="badge {{ $vendor->status === 'active' ? 'badge-active' : 'badge-inactive' }}">
                            {{ $vendor->status }}
                        </span>
                    </td>
                    <td>
                        @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'vendors.manage'))
                            <a class="btn btn-secondary btn-sm" href="{{ route('web.vendors.edit', $vendor) }}">Edit</a>
                            <form method="POST" action="{{ route('web.vendors.toggle', $vendor) }}" style="display:inline;">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-danger btn-sm" type="submit">
                                    {{ $vendor->status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">No vendors found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">{{ $vendors->links() }}</div>
</div>
@endsection
