@extends('layouts.app')

@section('title', $vendor->exists ? 'Edit Vendor' : 'Add Vendor')
@section('page-title', $vendor->exists ? 'Edit Vendor' : 'Add Vendor')

@section('content')
<div class="card">
    <form method="POST" action="{{ $vendor->exists ? route('web.vendors.update', $vendor) : route('web.vendors.store') }}">
        @csrf
        @if ($vendor->exists)
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="name">Vendor Name *</label>
                <input id="name" name="name" value="{{ old('name', $vendor->name) }}" required>
            </div>
            <div>
                <label for="contact_person">Contact Person</label>
                <input id="contact_person" name="contact_person" value="{{ old('contact_person', $vendor->contact_person) }}">
            </div>
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $vendor->email) }}">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input id="phone" name="phone" value="{{ old('phone', $vendor->phone) }}">
            </div>
            <div>
                <label for="gst_number">GST No.</label>
                <input id="gst_number" name="gst_number" value="{{ old('gst_number', $vendor->gst_number) }}">
            </div>
            <div>
                <label for="city">City</label>
                <input id="city" name="city" value="{{ old('city', $vendor->city) }}">
            </div>
            <div style="grid-column:1/-1;">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3">{{ old('address', $vendor->address) }}</textarea>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" @selected(old('status', $vendor->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $vendor->status) === 'inactive')>Inactive</option>
                </select>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-secondary" href="{{ route('web.vendors.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
