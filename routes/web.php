<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ClientController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ItemController;
use App\Http\Controllers\Web\MachineryController;
use App\Http\Controllers\Web\ResourceController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\VendorController;
use App\Http\Controllers\Web\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('web.dashboard')
    : redirect()->route('web.login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('web.login');
    Route::post('/login', [AuthController::class, 'login'])->name('web.login.submit');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('web.logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('web.dashboard');

    // Vendors
    Route::get('/vendors', [VendorController::class, 'index'])->name('web.vendors.index');
    Route::get('/vendors/create', [VendorController::class, 'create'])->name('web.vendors.create');
    Route::post('/vendors', [VendorController::class, 'store'])->name('web.vendors.store');
    Route::get('/vendors/{vendor}/edit', [VendorController::class, 'edit'])->name('web.vendors.edit');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])->name('web.vendors.update');
    Route::patch('/vendors/{vendor}/status', [VendorController::class, 'toggleStatus'])->name('web.vendors.toggle');

    // Clients
    Route::get('/clients', [ClientController::class, 'index'])->name('web.clients.index');
    Route::get('/clients/create', [ClientController::class, 'create'])->name('web.clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->name('web.clients.store');
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('web.clients.edit');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('web.clients.update');
    Route::patch('/clients/{client}/status', [ClientController::class, 'toggleStatus'])->name('web.clients.toggle');

    // Items
    Route::get('/items', [ItemController::class, 'index'])->name('web.items.index');
    Route::get('/items/create', [ItemController::class, 'create'])->name('web.items.create');
    Route::post('/items', [ItemController::class, 'store'])->name('web.items.store');
    Route::get('/items/{item}/edit', [ItemController::class, 'edit'])->name('web.items.edit');
    Route::put('/items/{item}', [ItemController::class, 'update'])->name('web.items.update');
    Route::patch('/items/{item}/status', [ItemController::class, 'toggleStatus'])->name('web.items.toggle');

    // Users
    Route::get('/users', [UserController::class, 'index'])->name('web.users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('web.users.create');
    Route::post('/users', [UserController::class, 'store'])->name('web.users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('web.users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('web.users.update');
    Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])->name('web.users.toggle');

    // Machinery
    Route::get('/machinery', [MachineryController::class, 'index'])->name('web.machinery.index');
    Route::get('/machinery/create', [MachineryController::class, 'create'])->name('web.machinery.create');
    Route::post('/machinery', [MachineryController::class, 'store'])->name('web.machinery.store');
    Route::get('/machinery/{machinery}/edit', [MachineryController::class, 'edit'])->name('web.machinery.edit');
    Route::put('/machinery/{machinery}', [MachineryController::class, 'update'])->name('web.machinery.update');
    Route::delete('/machinery/{machinery}', [MachineryController::class, 'destroy'])->name('web.machinery.destroy');

    // Workflow / Route cards (must be before generic resource routes)
    Route::get('/workflow', [WorkflowController::class, 'index'])->name('web.workflow.index');
    Route::get('/workflow/create', [WorkflowController::class, 'create'])->name('web.workflow.create');
    Route::post('/workflow', [WorkflowController::class, 'store'])->name('web.workflow.store');
    Route::get('/workflow/{workOrder}', [WorkflowController::class, 'show'])->name('web.workflow.show');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('web.settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('web.settings.update');

    // All other modules — list + detail
    foreach (array_keys(config('mims_web.resources', [])) as $slug) {
        Route::get('/'.$slug, [ResourceController::class, 'index'])
            ->name('web.resource.'.$slug);
        Route::get('/'.$slug.'/{id}', [ResourceController::class, 'show'])
            ->whereNumber('id')
            ->name('web.resource.'.$slug.'.show');
    }
});
