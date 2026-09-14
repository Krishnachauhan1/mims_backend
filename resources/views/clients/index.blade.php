@extends('layouts.app')

@section('title', 'Clients')
@section('page-title', 'Client Management')

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ route('web.clients.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search clients…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'clients.manage'))
        <a href="{{ route('web.clients.create') }}" class="btn btn-primary">Add Client</a>
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
            @forelse ($clients as $client)
                <tr>
                    <td>{{ $client->name }}</td>
                    <td>{{ $client->contact_person ?: '—' }}</td>
                    <td>{{ $client->email ?: '—' }}</td>
                    <td>{{ $client->phone ?: '—' }}</td>
                    <td>{{ $client->city ?: '—' }}</td>
                    <td>{{ $client->total_orders ?? 0 }}</td>
                    <td>
                        <span class="badge {{ $client->status === 'active' ? 'badge-active' : 'badge-inactive' }}">
                            {{ $client->status }}
                        </span>
                    </td>
                    <td>
                        @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'clients.manage'))
                            <a class="btn btn-secondary btn-sm" href="{{ route('web.clients.edit', $client) }}">Edit</a>
                            <form method="POST" action="{{ route('web.clients.toggle', $client) }}" style="display:inline;">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-danger btn-sm" type="submit">
                                    {{ $client->status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">No clients found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">{{ $clients->links() }}</div>
</div>
@endsection
