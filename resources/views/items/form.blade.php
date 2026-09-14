@extends('layouts.app')

@section('title', $item->exists ? 'Edit Item' : 'Add Item')
@section('page-title', $item->exists ? 'Edit Item' : 'Add Item')

@section('content')
<div class="card">
    <form method="POST" action="{{ $item->exists ? route('web.items.update', $item) : route('web.items.store') }}">
        @csrf
        @if ($item->exists)
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="code">Item Code *</label>
                <input id="code" name="code" value="{{ old('code', $item->code) }}" required>
            </div>
            <div>
                <label for="name">Item Name *</label>
                <input id="name" name="name" value="{{ old('name', $item->name) }}" required>
            </div>
            <div>
                <label for="category">Category</label>
                <input id="category" name="category" value="{{ old('category', $item->category) }}">
            </div>
            <div>
                <label for="grade">Grade</label>
                <input id="grade" name="grade" value="{{ old('grade', $item->grade) }}">
            </div>
            <div>
                <label for="unit">Unit</label>
                <input id="unit" name="unit" value="{{ old('unit', $item->unit) }}">
            </div>
            <div>
                <label for="purchase_price">Purchase Price</label>
                <input id="purchase_price" type="number" step="0.01" min="0" name="purchase_price"
                       value="{{ old('purchase_price', $item->purchase_price) }}">
            </div>
            <div>
                <label for="min_stock_alert">Min Stock Alert</label>
                <input id="min_stock_alert" type="number" min="0" name="min_stock_alert"
                       value="{{ old('min_stock_alert', $item->min_stock_alert) }}">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" @selected(old('status', $item->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $item->status) === 'inactive')>Inactive</option>
                </select>
            </div>
            <div style="grid-column:1/-1;">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3">{{ old('description', $item->description) }}</textarea>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-secondary" href="{{ route('web.items.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
