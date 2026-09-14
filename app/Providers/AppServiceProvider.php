<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\DashboardTask;
use App\Models\Document;
use App\Models\FinishedGood;
use App\Models\Gauge;
use App\Models\GaugeCalibration;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\JobWork;
use App\Models\Machinery;
use App\Models\MachineryMaintenanceLog;
use App\Models\Process;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rejection;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Workflow;
use App\Models\WorkOrder;
use App\Observers\AuditableObserver;
use Illuminate\Support\ServiceProvider;

use App\Services\PermissionService;
use App\Support\WebNavigation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        $observer = AuditableObserver::class;

        Client::observe($observer);
        Vendor::observe($observer);
        PurchaseRequisition::observe($observer);
        PurchaseOrder::observe($observer);
        InventoryTransaction::observe($observer);
        InventoryItem::observe($observer);
        Machinery::observe($observer);
        MachineryMaintenanceLog::observe($observer);
        Document::observe($observer);
        Gauge::observe($observer);
        GaugeCalibration::observe($observer);
        Item::observe($observer);
        Process::observe($observer);
        Workflow::observe($observer);
        WorkOrder::observe($observer);
        JobWork::observe($observer);
        FinishedGood::observe($observer);
        Rejection::observe($observer);
        ProductionBatch::observe($observer);
        DashboardTask::observe($observer);
        User::observe($observer);

        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $permissions = app(PermissionService::class);
            $view->with('navSections', $user ? WebNavigation::sections($user) : []);
            $view->with('userRoleLabel', $user ? $permissions->roleLabel($user->role) : '');
        });
    }
}
