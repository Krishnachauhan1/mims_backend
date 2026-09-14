@extends('layouts.app')

@section('title', 'Settings')
@section('page-title', 'Settings')

@section('content')
<div class="card">
    <h2 style="margin-top:0;">Company Profile</h2>
    <form method="POST" action="{{ route('web.settings.update') }}">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <div>
                <label for="company_name">Company Name</label>
                <input id="company_name" name="company_name" value="{{ old('company_name', $profile?->company_name) }}">
            </div>
            <div>
                <label for="gst_number">GST Number</label>
                <input id="gst_number" name="gst_number" value="{{ old('gst_number', $profile?->gst_number) }}">
            </div>
        </div>
        <div style="margin-top:20px;">
            <button class="btn btn-primary" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
