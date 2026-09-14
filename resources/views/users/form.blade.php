@extends('layouts.app')

@section('title', $user->exists ? 'Edit User' : 'Add User')
@section('page-title', $user->exists ? 'Edit User' : 'Add User')

@section('content')
<div class="card">
    <form method="POST" action="{{ $user->exists ? route('web.users.update', $user) : route('web.users.store') }}">
        @csrf
        @if ($user->exists)
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="name">Full Name *</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required>
            </div>
            <div>
                <label for="email">Email *</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>
            </div>
            <div>
                <label for="password">Password {{ $user->exists ? '(leave blank to keep)' : '*' }}</label>
                <input id="password" type="password" name="password" {{ $user->exists ? '' : 'required' }}>
            </div>
            <div>
                <label for="role">Role *</label>
                <select id="role" name="role" required>
                    @foreach ($roles as $role)
                        <option value="{{ $role['key'] }}" @selected(old('role', $user->role) === $role['key'])>
                            {{ $role['label'] ?? $role['key'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="department">Department</label>
                <input id="department" name="department" value="{{ old('department', $user->department) }}">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input id="phone" name="phone" value="{{ old('phone', $user->phone) }}">
            </div>
            <div>
                <label for="machinery_id">Assigned Machine</label>
                <select id="machinery_id" name="machinery_id">
                    <option value="">— None —</option>
                    @foreach ($machines as $machine)
                        <option value="{{ $machine->id }}" @selected((string) old('machinery_id', $user->machinery_id) === (string) $machine->id)>
                            {{ $machine->name }} ({{ $machine->machine_code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))>
                    Active
                </label>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-secondary" href="{{ route('web.users.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
