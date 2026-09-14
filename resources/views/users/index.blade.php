@extends('layouts.app')

@section('title', 'Users')
@section('page-title', 'User Management')

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ route('web.users.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search users…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'users.create'))
        <a href="{{ route('web.users.create') }}" class="btn btn-primary">Add User</a>
    @endif
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Department</th>
                <th>Phone</th>
                <th>Machine</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->role }}</td>
                    <td>{{ $user->department ?: '—' }}</td>
                    <td>{{ $user->phone ?: '—' }}</td>
                    <td>{{ $user->machinery?->name ?: '—' }}</td>
                    <td>
                        <span class="badge {{ $user->is_active ? 'badge-active' : 'badge-inactive' }}">
                            {{ $user->is_active ? 'active' : 'inactive' }}
                        </span>
                    </td>
                    <td>
                        @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'users.update'))
                            <a class="btn btn-secondary btn-sm" href="{{ route('web.users.edit', $user) }}">Edit</a>
                        @endif
                        @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'users.deactivate'))
                            <form method="POST" action="{{ route('web.users.toggle', $user) }}" style="display:inline;">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-danger btn-sm" type="submit">
                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">No users found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">{{ $users->links() }}</div>
</div>
@endsection
