<?php

namespace App\Services;

use App\Models\AuditLog;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public static function log(
        string $action,
        string $module,
        ?string $description = null,
        ?string $type = null,
        array $metadata = [],
    ): void {
        if (! self::shouldLog()) {
            return;
        }

        try {
            $request = request();

            AuditLog::query()->create([
                'user_id' => $request?->user()?->id,
                'user_name' => $request?->user()?->name ?? 'System',
                'action' => $action,
                'module' => $module,
                'type' => $type ?? self::inferType($action, $module),
                'description' => $description,
                'ip_address' => $request?->ip(),
                'metadata' => $metadata ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log skipped', [
                'action' => $action,
                'module' => $module,
                'error' => $e->getMessage(),
            ]);

            return;
        }
    }

    public static function logModelEvent(string $event, Model $model): void
    {
        if (! self::shouldLog() || self::shouldSkipModel($model, $event)) {
            return;
        }

        $module = self::moduleFor($model);
        $label = self::labelFor($model);
        $action = match ($event) {
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            default => ucfirst($event),
        };

        self::log(
            action: "{$action} {$module}",
            module: $module,
            description: self::descriptionFor($event, $model, $label),
            metadata: [
                'model' => $model::class,
                'id' => $model->getKey(),
            ],
        );

        try {
            AppNotificationService::fromModelEvent($event, $model);
        } catch (\Throwable $e) {
            Log::warning('Model notification skipped', [
                'model' => $model::class,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function shouldLog(): bool
    {
        return request()->is('api/*') && ! app()->runningUnitTests();
    }

    private static function shouldSkipModel(Model $model, string $event): bool
    {
        if ($model instanceof AuditLog || $model instanceof \App\Models\Notification) {
            return true;
        }

        if ($model instanceof User && $event === 'updated') {
            $changes = array_keys($model->getChanges());
            $ignored = ['last_login_at', 'updated_at', 'remember_token', 'password'];

            return empty(array_diff($changes, $ignored));
        }

        return false;
    }

    private static function moduleFor(Model $model): string
    {
        return match ($model::class) {
            Client::class => 'Clients',
            Vendor::class => 'Vendors',
            PurchaseRequisition::class => 'Purchase Requisition',
            PurchaseOrder::class => 'Purchase Order',
            InventoryTransaction::class => 'Check-in / Check-out',
            InventoryItem::class => 'Inventory',
            Machinery::class => 'Machinery',
            MachineryMaintenanceLog::class => 'Machinery Maintenance',
            Document::class => 'Documents',
            Gauge::class => 'Gauges',
            GaugeCalibration::class => 'Gauge Calibration',
            Item::class => 'Items',
            Process::class => 'Processes',
            Workflow::class => 'Workflow',
            WorkOrder::class => 'Work Orders',
            JobWork::class => 'Job Work',
            FinishedGood::class => 'Finished Goods',
            Rejection::class => 'Rejection',
            ProductionBatch::class => 'Production',
            DashboardTask::class => 'Dashboard',
            User::class => 'Users',
            default => class_basename($model),
        };
    }

    private static function labelFor(Model $model): string
    {
        return match (true) {
            isset($model->product_name) && filled($model->product_name) => (string) $model->product_name,
            isset($model->name) && filled($model->name) => (string) $model->name,
            isset($model->title) && filled($model->title) => (string) $model->title,
            isset($model->batch_number) && filled($model->batch_number) => (string) $model->batch_number,
            isset($model->po_number) && filled($model->po_number) => (string) $model->po_number,
            isset($model->pr_number) && filled($model->pr_number) => (string) $model->pr_number,
            isset($model->job_number) && filled($model->job_number) => (string) $model->job_number,
            isset($model->machine_code) && filled($model->machine_code) => (string) $model->machine_code,
            isset($model->file_name) && filled($model->file_name) => (string) $model->file_name,
            default => '#'.$model->getKey(),
        };
    }

    private static function descriptionFor(string $event, Model $model, string $label): string
    {
        return match ($event) {
            'created' => "{$label} created",
            'updated' => "{$label} updated",
            'deleted' => "{$label} deleted",
            default => "{$label} {$event}",
        };
    }

    public static function inferType(string $action, string $module): string
    {
        $haystack = strtolower($action.' '.$module);

        return match (true) {
            str_contains($haystack, 'login') => 'login',
            str_contains($haystack, 'check in') || str_contains($haystack, 'checkin') => 'check_in',
            str_contains($haystack, 'check out') || str_contains($haystack, 'checkout') => 'check_out',
            str_contains($haystack, 'inventory') => 'inventory_update',
            str_contains($haystack, 'workflow') => 'workflow_change',
            str_contains($haystack, 'movement') || str_contains($haystack, 'dispatch') => 'product_movement',
            str_contains($haystack, 'export') || str_contains($haystack, 'report') => 'report_export',
            str_contains($haystack, 'calibration') => 'calibration_recorded',
            str_contains($haystack, 'certificate') || str_contains($haystack, 'document') => 'certificate_uploaded',
            str_contains($haystack, 'maintenance') => 'maintenance_recorded',
            str_contains($haystack, 'production') => 'production_update',
            str_contains($haystack, 'rejection') => 'rejection_recorded',
            str_contains($haystack, 'purchase') => 'purchase_update',
            default => 'inventory_update',
        };
    }
}
