<?php

namespace App\Services;

use App\Models\BatchMaterialRequest;
use App\Models\Document;
use App\Models\FinishedGood;
use App\Models\GaugeCalibration;
use App\Models\InventoryItem;
use App\Models\JobWork;
use App\Models\MachineryMaintenanceLog;
use App\Models\Notification;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QualityInspection;
use App\Models\Rejection;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AppNotificationService
{
    public static function create(
        string $title,
        string $message,
        string $type = 'info',
        string $severity = 'info',
        ?string $module = null,
        ?string $reference = null,
        ?string $route = null,
        ?int $userId = null,
        array $extraData = [],
        bool $broadcastPush = false,
        ?array $broadcastExcludeDesignations = null,
    ): void {
        if ((! request()->is('api/*') && ! app()->runningInConsole()) || app()->runningUnitTests()) {
            return;
        }

        try {
            $notification = Notification::query()->create([
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'severity' => $severity,
                'module' => $module,
                'reference' => $reference,
                'route' => $route,
                'user_id' => $broadcastPush ? null : ($userId ?? request()->user()?->id),
            ]);

            self::dispatchPush($notification->title, $notification->message, array_merge([
                'type' => $notification->type,
                'severity' => $notification->severity,
                'module' => $notification->module,
                'reference' => $notification->reference,
                'route' => $notification->route,
                'notification_id' => $notification->id,
            ], $extraData), $broadcastPush ? null : $notification->user_id, $broadcastExcludeDesignations);
        } catch (\Throwable $e) {
            Log::warning('In-app notification skipped', [
                'title' => $title,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function dispatchPush(
        string $title,
        string $message,
        array $data,
        ?int $userId,
        ?array $excludeDesignations = null,
    ): void {
        $service = app(FcmService::class);

        if ($userId !== null) {
            $user = User::query()->find($userId);
            if ($user !== null) {
                $service->sendToUser($user, $title, $message, $data);
            }

            return;
        }

        if ($excludeDesignations !== null && $excludeDesignations !== []) {
            $service->sendToAllExceptDesignations($title, $message, $data, $excludeDesignations);

            return;
        }

        $service->sendToAll($title, $message, $data);
    }

    public static function fromModelEvent(string $event, Model $model): void
    {
        match ($model::class) {
            PurchaseRequisition::class => self::purchaseRequisitionEvent($event, $model),
            PurchaseOrder::class => self::purchaseOrderEvent($event, $model),
            InventoryItem::class => self::inventoryItemEvent($event, $model),
            MachineryMaintenanceLog::class => self::maintenanceEvent($event, $model),
            Document::class => self::documentEvent($event, $model),
            GaugeCalibration::class => self::calibrationEvent($event, $model),
            ProductionBatch::class => self::productionBatchEvent($event, $model),
            Rejection::class => self::rejectionEvent($event, $model),
            JobWork::class => self::jobWorkEvent($event, $model),
            FinishedGood::class => self::finishedGoodEvent($event, $model),
            default => null,
        };
    }

    private static function purchaseRequisitionEvent(string $event, PurchaseRequisition $pr): void
    {
        if ($event === 'created') {
            self::create(
                title: 'PR created',
                message: "Purchase requisition {$pr->pr_number} created for {$pr->vendor_name}",
                type: 'pr_created',
                severity: 'info',
                module: 'Purchase Requisition',
                reference: $pr->pr_number,
                route: '/purchase-requisition',
            );
        }
    }

    public static function prSubmitted(PurchaseRequisition $pr): void
    {
        self::create(
            title: 'PR submitted for approval',
            message: "{$pr->pr_number} from {$pr->vendor_name} is awaiting admin review",
            type: 'pr_submitted',
            severity: 'info',
            module: 'Purchase Requisition',
            reference: $pr->pr_number,
            route: '/purchase-requisition',
        );
    }

    public static function prReviewed(PurchaseRequisition $pr, string $action, ?string $notes): void
    {
        $labels = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'revision' => 'sent back for revision',
        ];
        $label = $labels[$action] ?? $action;
        $severity = $action === 'approve' ? 'success' : ($action === 'reject' ? 'high' : 'warning');
        $message = "{$pr->pr_number} was {$label}";
        if ($notes) {
            $message .= ": {$notes}";
        }

        self::create(
            title: 'PR review update',
            message: $message,
            type: 'pr_review',
            severity: $severity,
            module: 'Purchase Requisition',
            reference: $pr->pr_number,
            route: '/purchase-requisition',
            userId: $pr->submitted_by_user_id,
        );
    }

    private static function purchaseOrderEvent(string $event, PurchaseOrder $po): void
    {
        if ($event === 'created') {
            self::create(
                title: 'PO created',
                message: "Purchase order {$po->po_number} created for {$po->vendor_name}",
                type: 'po_created',
                severity: 'info',
                module: 'Purchase Order',
                reference: $po->po_number,
                route: '/purchase-order',
            );

            return;
        }

        if ($event === 'updated' && $po->wasChanged('status')) {
            $severity = $po->status === 'approved' ? 'success' : ($po->status === 'rejected' ? 'high' : 'info');
            self::create(
                title: 'PO status updated',
                message: "Purchase order {$po->po_number} marked {$po->status}",
                type: 'po_status',
                severity: $severity,
                module: 'Purchase Order',
                reference: $po->po_number,
                route: '/purchase-order',
            );
        }
    }

    private static function inventoryItemEvent(string $event, InventoryItem $item): void
    {
        if ($event !== 'updated' || ! $item->wasChanged(['quantity', 'min_stock'])) {
            return;
        }

        $minStock = (int) ($item->min_stock ?? 0);
        $quantity = (int) ($item->quantity ?? 0);

        if ($minStock > 0 && $quantity <= $minStock) {
            self::create(
                title: 'Low stock alert',
                message: "{$item->name} is at or below minimum stock level",
                type: 'low_stock',
                severity: 'warning',
                module: 'Inventory',
                reference: $item->sku ?? (string) $item->id,
                route: '/inventory',
            );
        }
    }

    private static function maintenanceEvent(string $event, MachineryMaintenanceLog $log): void
    {
        if ($event !== 'created') {
            return;
        }

        $machine = $log->machinery;
        $label = $machine?->name ?? 'Machine #'.$log->machinery_id;

        self::create(
            title: 'Maintenance logged',
            message: "{$label}: {$log->type} maintenance recorded",
            type: 'maintenance',
            severity: 'info',
            module: 'Machinery',
            reference: $machine?->machine_code,
            route: '/machinery',
        );
    }

    private static function documentEvent(string $event, Document $document): void
    {
        if ($event !== 'created') {
            return;
        }

        self::create(
            title: 'Document uploaded',
            message: "{$document->file_name} uploaded for {$document->module}",
            type: 'document_upload',
            severity: 'info',
            module: ucfirst(str_replace('_', ' ', $document->module)),
            reference: $document->entity_id,
            route: '/'.$document->module,
        );
    }

    private static function calibrationEvent(string $event, GaugeCalibration $calibration): void
    {
        if ($event !== 'created') {
            return;
        }

        $gauge = $calibration->gauge;
        $severity = $calibration->result === 'fail' ? 'high' : 'success';

        self::create(
            title: 'Calibration recorded',
            message: ($gauge?->name ?? 'Gauge').' calibration marked '.$calibration->result,
            type: 'calibration_recorded',
            severity: $severity,
            module: 'Gauges',
            reference: $gauge?->instrument_id,
            route: '/gauges',
        );
    }

    private static function productionBatchEvent(string $event, ProductionBatch $batch): void
    {
        if ($event === 'created') {
            self::create(
                title: 'Production batch started',
                message: "Batch {$batch->batch_number} started at {$batch->current_stage}",
                type: 'production_started',
                severity: 'info',
                module: 'Production',
                reference: $batch->batch_number,
                route: '/production',
            );

            return;
        }

        if ($event === 'updated' && $batch->wasChanged('status') && $batch->status === 'completed') {
            self::create(
                title: 'Production batch completed',
                message: "Batch {$batch->batch_number} completed",
                type: 'production_completion',
                severity: 'success',
                module: 'Production',
                reference: $batch->batch_number,
                route: '/finished-goods',
            );
        }
    }

    private static function rejectionEvent(string $event, Rejection $rejection): void
    {
        if ($event !== 'created') {
            return;
        }

        self::create(
            title: 'Rejection recorded',
            message: "{$rejection->quantity} unit(s) rejected at {$rejection->stage}",
            type: 'rejection',
            severity: 'high',
            module: 'Rejection',
            reference: $rejection->batch_number ?? (string) $rejection->id,
            route: '/rejection',
        );
    }

    private static function jobWorkEvent(string $event, JobWork $job): void
    {
        if ($event !== 'updated' || ! $job->wasChanged('status')) {
            return;
        }

        self::create(
            title: 'Job work updated',
            message: "{$job->job_number} status changed to {$job->status}",
            type: 'job_work_status',
            severity: 'info',
            module: 'Job Work',
            reference: $job->job_number,
            route: '/job-work',
        );
    }

    private static function finishedGoodEvent(string $event, FinishedGood $good): void
    {
        if ($event !== 'created') {
            return;
        }

        self::create(
            title: 'Finished goods recorded',
            message: "{$good->product_name} added to finished goods",
            type: 'finished_goods',
            severity: 'success',
            module: 'Finished Goods',
            reference: $good->batch_number ?? (string) $good->id,
            route: '/finished-goods',
        );
    }

    public static function taskAssigned(
        string $taskName,
        string $assignedToName,
        string $module,
        ?string $activity,
        int $taskId,
        ?string $dueDate,
        ?int $assigneeUserId = null,
    ): void {
        $assignee = null;

        if ($assigneeUserId !== null) {
            $assignee = User::query()
                ->where('is_active', true)
                ->find($assigneeUserId);
        }

        if ($assignee === null) {
            $assignee = User::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($assignedToName))])
                ->first();
        }

        if ($assignee === null) {
            return;
        }

        $creator = request()->user()?->name ?? 'Admin';
        $dueLabel = $dueDate ?? '—';

        self::create(
            title: 'New task assigned',
            message: "{$creator} assigned you \"{$taskName}\" in {$module}",
            type: 'task_assigned',
            severity: 'info',
            module: $module,
            reference: (string) $taskId,
            route: '/dashboard',
            userId: $assignee->id,
            extraData: [
                'task_id' => (string) $taskId,
                'task_name' => $taskName,
                'activity' => $activity ?? 'Task assigned — awaiting start',
                'due_date' => $dueLabel,
            ],
        );
    }

    public static function jobWorkStageReady(ProductionBatch $batch): void
    {
        $product = $batch->product_name;

        self::notifyUsersWithPermissions(
            permissionKeys: ['job_work.view', 'job_work.manage'],
            title: 'Job Work stage ready',
            message: "\"{$product}\" ({$batch->batch_number}) is at Job Work — send to vendor from Product Movement",
            type: 'job_work_stage_ready',
            severity: 'info',
            module: 'Job Work',
            reference: $batch->batch_number,
            route: '/workflow',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $product,
            ],
        );
    }

    public static function jobWorkSentOut(
        ProductionBatch $batch,
        string $vendor,
        ?string $senderName = null,
    ): void {
        $sender = $senderName ?? request()->user()?->name ?? 'User';
        $product = $batch->product_name;

        self::notifyNonOperators(
            title: 'Sent for job work',
            message: "{$sender} sent \"{$product}\" to {$vendor} for job work",
            type: 'job_work_sent',
            severity: 'info',
            module: 'Job Work',
            reference: $batch->batch_number,
            route: '/checkin',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'vendor' => $vendor,
            ],
        );
    }

    public static function jobWorkReturned(
        ProductionBatch $batch,
        ?string $receiverName = null,
        bool $addedToInventory = false,
    ): void {
        $receiver = $receiverName ?? request()->user()?->name ?? 'User';
        $product = $batch->product_name;
        $after = $addedToInventory
            ? 'added to inventory'
            : 'moved to next production stage';

        self::notifyNonOperators(
            title: 'Job work returned',
            message: "{$receiver} checked in \"{$product}\" from job work — {$after}",
            type: 'job_work_returned',
            severity: 'success',
            module: 'Job Work',
            reference: $batch->batch_number,
            route: '/workflow',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'added_to_inventory' => $addedToInventory ? '1' : '0',
            ],
        );
    }

    public static function batchMaterialRequested(
        ProductionBatch $batch,
        BatchMaterialRequest $materialRequest,
        ?string $operatorName = null,
    ): void {
        $operator = $operatorName ?? $materialRequest->requested_by_name ?? 'Operator';
        $itemName = $materialRequest->item_name;
        $qty = rtrim(rtrim(number_format((float) $materialRequest->quantity, 2, '.', ''), '0'), '.');
        $unit = $materialRequest->unit;
        $product = $batch->product_name;

        self::notifyNonOperators(
            title: 'Extra material requested',
            message: "{$operator} needs {$qty} {$unit} of {$itemName} "
                ."for batch {$batch->batch_number} ({$product})",
            type: 'batch_material_request',
            severity: 'warning',
            module: 'Production',
            reference: $batch->batch_number,
            route: '/workflow',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'request_id' => (string) $materialRequest->id,
                'item_name' => $itemName,
                'quantity' => (string) $qty,
                'unit' => $unit,
                'request_type' => $materialRequest->request_type,
                'requested_by' => $operator,
            ],
        );
    }

    public static function batchMaterialRequestReviewed(
        ProductionBatch $batch,
        BatchMaterialRequest $materialRequest,
        string $action,
        ?string $reviewerName = null,
    ): void {
        $reviewer = $reviewerName ?? $materialRequest->reviewed_by_name ?? 'Supervisor';
        $operatorId = $materialRequest->requested_by_user_id;
        if ($operatorId === null) {
            return;
        }

        $itemName = $materialRequest->item_name;
        $qty = rtrim(rtrim(number_format((float) $materialRequest->quantity, 2, '.', ''), '0'), '.');
        $unit = $materialRequest->unit;
        $approved = $action === 'approve';

        self::create(
            title: $approved ? 'Material request approved' : 'Material request rejected',
            message: $approved
                ? "{$reviewer} approved {$qty} {$unit} of {$itemName} for batch {$batch->batch_number}"
                : "{$reviewer} rejected your request for {$itemName} (batch {$batch->batch_number})",
            type: 'batch_material_request_reviewed',
            severity: $approved ? 'success' : 'high',
            module: 'Production',
            reference: $batch->batch_number,
            route: '/workflow',
            userId: $operatorId,
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'request_id' => (string) $materialRequest->id,
                'action' => $action,
            ],
        );
    }

    public static function batchItemRejected(
        ProductionBatch $batch,
        Rejection $rejection,
        ?string $operatorName = null,
    ): void {
        $operator = $operatorName ?? $rejection->reported_by ?? 'Operator';
        $itemCode = $rejection->item_code ?? 'Item';
        $product = $batch->product_name;

        self::notifyNonOperators(
            title: 'Batch item rejected',
            message: "{$operator} rejected {$rejection->quantity} unit(s) of {$itemCode} "
                ."at {$rejection->stage} — {$rejection->reason} (batch {$batch->batch_number})",
            type: 'rejection',
            severity: 'high',
            module: 'Rejection',
            reference: $batch->batch_number,
            route: '/rejection',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'rejection_id' => (string) $rejection->id,
                'item_code' => $itemCode,
                'product' => $product,
                'reason' => $rejection->reason,
                'reported_by' => $operator,
            ],
        );
    }

    public static function qualityInspectionPending(QualityInspection $inspection): void
    {
        self::notifyUsersWithPermissions(
            permissionKeys: ['quality.view', 'quality.inspect', 'quality.approve'],
            title: 'Quality inspection pending',
            message: "Batch {$inspection->batch_number} ({$inspection->product_name}) "
                ."is ready for quality check — {$inspection->quantity} {$inspection->unit}",
            type: 'quality_inspection',
            severity: 'warning',
            module: 'Quality',
            reference: $inspection->batch_number,
            route: '/quality',
            extraData: [
                'inspection_id' => (string) $inspection->id,
                'batch_id' => (string) $inspection->production_batch_id,
                'batch_number' => $inspection->batch_number,
                'product_name' => $inspection->product_name,
            ],
        );
    }

    public static function qualityInspectionPassed(QualityInspection $inspection): void
    {
        $rejected = (int) $inspection->rejected_qty;
        $passed = (int) $inspection->passed_qty;
        $detail = $rejected > 0
            ? " ({$passed} passed, {$rejected} rejected)"
            : '';

        self::notifyNonOperators(
            title: 'Quality check passed',
            message: "Batch {$inspection->batch_number} ({$inspection->product_name}) "
                ."cleared QC and released to inventory{$detail}",
            type: 'quality_passed',
            severity: 'success',
            module: 'Quality',
            reference: $inspection->batch_number,
            route: '/inventory',
            extraData: [
                'inspection_id' => (string) $inspection->id,
                'batch_number' => $inspection->batch_number,
                'product_name' => $inspection->product_name,
                'passed_qty' => (string) $passed,
                'rejected_qty' => (string) $rejected,
            ],
        );
    }

    public static function qualityItemRejected(
        QualityInspection $inspection,
        Rejection $rejection,
        ?string $inspectorName = null,
    ): void {
        $inspector = $inspectorName ?? $rejection->reported_by ?? 'Quality Inspector';
        $itemCode = $rejection->item_code ?? 'Item';

        self::notifyNonOperators(
            title: 'Quality item rejected',
            message: "{$inspector} rejected {$rejection->quantity} unit(s) of {$itemCode} "
                ."at Quality Check — {$rejection->reason} (batch {$inspection->batch_number})",
            type: 'quality_item_rejected',
            severity: 'high',
            module: 'Quality',
            reference: $inspection->batch_number,
            route: '/rejection',
            extraData: [
                'inspection_id' => (string) $inspection->id,
                'batch_number' => $inspection->batch_number,
                'rejection_id' => (string) $rejection->id,
                'item_code' => $itemCode,
                'product_name' => $inspection->product_name,
                'reason' => $rejection->reason,
            ],
        );
    }

    public static function qualityInspectionFailed(
        QualityInspection $inspection,
        string $reason,
    ): void {
        self::notifyNonOperators(
            title: 'Quality check failed',
            message: "Batch {$inspection->batch_number} ({$inspection->product_name}) "
                ."failed QC — {$reason}. Sent to rejection for rework.",
            type: 'quality_failed',
            severity: 'high',
            module: 'Quality',
            reference: $inspection->batch_number,
            route: '/rejection',
            extraData: [
                'inspection_id' => (string) $inspection->id,
                'batch_number' => $inspection->batch_number,
                'product_name' => $inspection->product_name,
                'reason' => $reason,
            ],
        );
    }

    public static function productionAwaitingFinalCompletion(
        ProductionBatch $batch,
        ?string $operatorName = null,
    ): void {
        $operator = $operatorName ?? request()->user()?->name ?? 'Operator';
        $product = $batch->product_name;

        self::broadcastToAllUsers(
            title: 'All stages completed — mark batch complete',
            message: "{$operator} finished the last stage for \"{$product}\" ({$batch->batch_number}). "
                .'Mark as complete in Workflow → Product Movement.',
            type: 'production_awaiting_completion',
            severity: 'warning',
            module: 'Production',
            reference: $batch->batch_number,
            route: '/workflow',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $product,
            ],
        );
    }

    public static function productionCompletionReminder(ProductionBatch $batch): void
    {
        $product = $batch->product_name;

        self::broadcastToAllUsers(
            title: 'Reminder: mark batch complete',
            message: "\"{$product}\" ({$batch->batch_number}) finished all stages — "
                .'mark as complete in Workflow → Product Movement.',
            type: 'production_completion_reminder',
            severity: 'warning',
            module: 'Production',
            reference: $batch->batch_number,
            route: '/workflow',
            extraData: [
                'batch_id' => (string) $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $product,
            ],
        );
    }

    /**
     * @param  array<string, string>  $extraData
     */
    private static function broadcastToAllUsers(
        string $title,
        string $message,
        string $type,
        string $severity,
        string $module,
        ?string $reference,
        string $route,
        array $extraData = [],
    ): void {
        if ((! request()->is('api/*') && ! app()->runningInConsole()) || app()->runningUnitTests()) {
            return;
        }

        $payload = array_merge([
            'type' => $type,
            'severity' => $severity,
            'module' => $module,
            'reference' => $reference ?? '',
            'route' => $route,
        ], $extraData);

        $fcm = app(FcmService::class);

        User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($users) use (
                $title,
                $message,
                $type,
                $severity,
                $module,
                $reference,
                $route,
                $payload,
                $fcm,
            ): void {
                foreach ($users as $user) {
                    try {
                        $notification = Notification::query()->create([
                            'title' => $title,
                            'message' => $message,
                            'type' => $type,
                            'severity' => $severity,
                            'module' => $module,
                            'reference' => $reference,
                            'route' => $route,
                            'user_id' => $user->id,
                        ]);

                        $fcm->sendToUser($user, $title, $message, array_merge($payload, [
                            'notification_id' => (string) $notification->id,
                        ]));
                    } catch (\Throwable) {
                        // Continue notifying other users if one fails.
                    }
                }
            });
    }

    /**
     * @param  array<int, string>  $permissionKeys
     * @param  array<string, string>  $extraData
     */
    private static function notifyUsersWithPermissions(
        array $permissionKeys,
        string $title,
        string $message,
        string $type,
        string $severity,
        string $module,
        ?string $reference,
        string $route,
        array $extraData = [],
    ): void {
        if (! request()->is('api/*') || app()->runningUnitTests()) {
            return;
        }

        $permissionService = app(PermissionService::class);
        $users = User::query()->where('is_active', true)->get();
        $fcm = app(FcmService::class);
        $payload = array_merge([
            'type' => $type,
            'severity' => $severity,
            'module' => $module,
            'reference' => $reference ?? '',
            'route' => $route,
        ], $extraData);

        foreach ($users as $user) {
            $permissions = $permissionService->permissionsForUser($user);
            if (array_intersect($permissionKeys, $permissions) === []) {
                continue;
            }

            $notification = Notification::query()->create([
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'severity' => $severity,
                'module' => $module,
                'reference' => $reference,
                'route' => $route,
                'user_id' => $user->id,
            ]);

            $fcm->sendToUser($user, $title, $message, array_merge($payload, [
                'notification_id' => (string) $notification->id,
            ]));
        }
    }

    /**
     * @param  array<string, string>  $extraData
     */
    private static function notifyNonOperators(
        string $title,
        string $message,
        string $type,
        string $severity,
        string $module,
        ?string $reference,
        string $route,
        array $extraData = [],
    ): void {
        self::create(
            title: $title,
            message: $message,
            type: $type,
            severity: $severity,
            module: $module,
            reference: $reference,
            route: $route,
            broadcastPush: true,
            broadcastExcludeDesignations: ['machine_operator', 'production_operator'],
            extraData: $extraData,
        );

        app(FcmService::class)->sendToAllExceptOperators($title, $message, array_merge([
            'type' => $type,
            'severity' => $severity,
            'module' => $module,
            'reference' => $reference ?? '',
            'route' => $route,
        ], $extraData));
    }

    public static function workflowAssigned(WorkOrder $order): void
    {
        $creator = $order->created_by ?? request()->user()?->name ?? 'Admin';
        $item = $order->product ?: ($order->workflow_name ?: 'Work order');
        $client = ($order->client_name && $order->client_name !== '—')
            ? " for {$order->client_name}"
            : '';
        $routeCard = filled($order->route_card_no) ? " · {$order->route_card_no}" : '';

        self::create(
            title: 'Workflow assigned',
            message: "{$creator} assigned \"{$item}\"{$client}{$routeCard}",
            type: 'workflow_assigned',
            severity: 'info',
            module: 'Workflow',
            reference: (string) $order->id,
            route: '/workflow',
            broadcastPush: true,
            extraData: [
                'work_order_id' => (string) $order->id,
                'workflow_name' => $order->workflow_name ?? '',
                'product' => $order->product ?? '',
                'client_name' => $order->client_name ?? '',
                'route_card_no' => $order->route_card_no ?? '',
                'created_by' => $creator,
            ],
        );
    }

    public static function workflowStatusChanged(
        WorkOrder $order,
        string $previousStatus,
    ): void {
        $updater = $order->updated_by ?? request()->user()?->name ?? 'Supervisor';
        $item = $order->product ?: ($order->workflow_name ?: 'Work order');
        $fromLabel = self::supervisorStatusLabel($previousStatus);
        $toLabel = self::supervisorStatusLabel($order->supervisor_status ?? 'pending');
        $severity = match ($order->supervisor_status) {
            'rejection' => 'high',
            'finished_goods' => 'success',
            'wip' => 'info',
            default => 'info',
        };

        self::create(
            title: 'Workflow status updated',
            message: "{$updater} changed \"{$item}\" from {$fromLabel} to {$toLabel}",
            type: 'workflow_status_changed',
            severity: $severity,
            module: 'Workflow',
            reference: (string) $order->id,
            route: '/workflow',
            broadcastPush: true,
            broadcastExcludeDesignations: ['machine_operator'],
            extraData: [
                'work_order_id' => (string) $order->id,
                'workflow_name' => $order->workflow_name ?? '',
                'product' => $order->product ?? '',
                'previous_status' => $previousStatus,
                'supervisor_status' => $order->supervisor_status ?? 'pending',
                'updated_by' => $updater,
            ],
        );
    }

    private static function supervisorStatusLabel(string $status): string
    {
        return match ($status) {
            'wip' => 'Work In Progress',
            'rejection' => 'Rejection',
            'finished_goods' => 'Finished Goods',
            default => 'Pending',
        };
    }
}
