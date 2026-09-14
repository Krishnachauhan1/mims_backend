@extends('layouts.app')

@section('title', $machine->exists ? 'Edit Machine' : 'Add Machine')
@section('page-title', $machine->exists ? 'Edit Machine' : 'Add Machine')

@section('content')
<div class="card">
    <form method="POST" action="{{ $machine->exists ? route('web.machinery.update', $machine) : route('web.machinery.store') }}">
        @csrf
        @if ($machine->exists)
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="sequence">Sequence</label>
                <input id="sequence" type="number" min="0" name="sequence"
                       value="{{ old('sequence', $machine->sequence) }}">
            </div>
            <div>
                <label for="machine_code">Machine Code *</label>
                <input id="machine_code" name="machine_code" value="{{ old('machine_code', $machine->machine_code) }}" required>
            </div>
            <div>
                <label for="name">Name *</label>
                <input id="name" name="name" value="{{ old('name', $machine->name) }}" required>
            </div>
            <div>
                <label for="type">Type</label>
                <input id="type" name="type" value="{{ old('type', $machine->type) }}">
            </div>
            <div>
                <label for="location">Location</label>
                <input id="location" name="location" value="{{ old('location', $machine->location) }}">
            </div>
            <div>
                <label for="capacity">Capacity</label>
                <input id="capacity" name="capacity" value="{{ old('capacity', $machine->capacity) }}">
            </div>
            <div>
                <label for="manufacturer">Manufacturer</label>
                <input id="manufacturer" name="manufacturer" value="{{ old('manufacturer', $machine->manufacturer) }}">
            </div>
            <div>
                <label for="default_stage">Default Stage</label>
                <input id="default_stage" name="default_stage" value="{{ old('default_stage', $machine->default_stage) }}">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" @selected(old('status', $machine->status) === 'active')>Active</option>
                    <option value="maintenance" @selected(old('status', $machine->status) === 'maintenance')>Maintenance</option>
                    <option value="inactive" @selected(old('status', $machine->status) === 'inactive')>Inactive</option>
                </select>
            </div>
            <div style="grid-column:1/-1;">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3">{{ old('notes', $machine->notes) }}</textarea>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-secondary" href="{{ route('web.machinery.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
