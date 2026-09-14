@extends('layouts.app')

@section('title', 'Machinery')
@section('page-title', 'Machinery Management')

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ route('web.machinery.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search machinery…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
    @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'machinery.manage'))
        <a href="{{ route('web.machinery.create') }}" class="btn btn-primary">Add Machine</a>
    @endif
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Location</th>
                <th>Capacity</th>
                <th>Stage</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($machines as $machine)
                <tr>
                    <td>{{ $machine->sequence ?? '—' }}</td>
                    <td>{{ $machine->machine_code }}</td>
                    <td>{{ $machine->name }}</td>
                    <td>{{ $machine->type ?: '—' }}</td>
                    <td>{{ $machine->location ?: '—' }}</td>
                    <td>{{ $machine->capacity ?: '—' }}</td>
                    <td>{{ $machine->default_stage ?: '—' }}</td>
                    <td>
                        <span class="badge {{ $machine->status === 'active' ? 'badge-active' : 'badge-inactive' }}">
                            {{ $machine->status }}
                        </span>
                    </td>
                    <td>
                        @if (app(\App\Services\PermissionService::class)->userCan(auth()->user(), 'machinery.manage'))
                            <a class="btn btn-secondary btn-sm" href="{{ route('web.machinery.edit', $machine) }}">Edit</a>
                            <form method="POST" action="{{ route('web.machinery.destroy', $machine) }}" style="display:inline;"
                                  onsubmit="return confirm('Delete this machine?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">No machinery found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">{{ $machines->links() }}</div>
</div>
@endsection
