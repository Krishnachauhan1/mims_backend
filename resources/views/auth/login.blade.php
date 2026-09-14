@extends('layouts.guest')

@section('content')
<div class="login-shell">
    <div class="login-brand">
        <div class="login-brand-inner">
            <div class="brand-mark brand-mark-lg">
                <span class="material-symbols-outlined">factory</span>
            </div>
            <h1>Power Force Industries</h1>
            <p>Manufacturing & Inventory Management System</p>
            <ul class="login-features">
                <li><span class="material-symbols-outlined">precision_manufacturing</span> Production tracking</li>
                <li><span class="material-symbols-outlined">inventory_2</span> Inventory control</li>
                <li><span class="material-symbols-outlined">verified</span> Quality & workflow</li>
            </ul>
        </div>
    </div>
    <div class="login-form-panel">
        <div class="auth-card">
            <h2>Sign in</h2>
            <p class="text-muted">Enter your credentials to continue</p>

            @if ($errors->any())
                <div class="flash flash-error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('web.login.submit') }}">
                @csrf
                <div class="form-field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="you@company.com">
                </div>
                <div class="form-field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required placeholder="••••••••">
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="remember" value="1"> Remember me
                </label>
                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>
        </div>
    </div>
</div>
@endsection
