    <?php

    use App\Http\Controllers\Api\AppConfigController;
    use App\Http\Controllers\Api\AuditLogController;
    use App\Http\Controllers\Api\AuthController;
    use App\Http\Controllers\Api\DailyProductionController;
    use App\Http\Controllers\Api\DashboardController;
    use App\Http\Controllers\Api\DocumentController;
    use App\Http\Controllers\Api\ExpenseController;
    use App\Http\Controllers\Api\MaintenanceLogController;
    use App\Http\Controllers\Api\PackingController;
    use App\Http\Controllers\Api\UserDeviceTokenController;
    use App\Http\Controllers\Api\ReportController;
    use App\Http\Controllers\Api\CheckinController;
    use App\Http\Controllers\Api\ClientController;
    use App\Http\Controllers\Api\GaugeController;
    use App\Http\Controllers\Api\FinishedGoodController;
    use App\Http\Controllers\Api\ItemController;
    use App\Http\Controllers\Api\MaterialRequisitionController;
    use App\Http\Controllers\Api\MachineryController;
    use App\Http\Controllers\Api\MachineTimeController;
    use App\Http\Controllers\Api\NotificationController;
    use App\Http\Controllers\Api\InventoryController;
    use App\Http\Controllers\Api\PurchaseOrderController;
    use App\Http\Controllers\Api\PurchaseRequisitionController;
    use App\Http\Controllers\Api\ProcessController;
    use App\Http\Controllers\Api\QualityInspectionController;
    use App\Http\Controllers\Api\CompanyProfileController;
    use App\Http\Controllers\Api\ProfileController;
    use App\Http\Controllers\Api\ProductionController;
    use App\Http\Controllers\Api\RejectionController;
    use App\Http\Controllers\Api\RoleController;
    use App\Http\Controllers\Api\JobWorkController;
    use App\Http\Controllers\Api\SalesTrackingController;
    use App\Http\Controllers\Api\ImportExportController;
    use App\Http\Controllers\Api\UserController;
    use App\Http\Controllers\Api\VendorController;
    use App\Http\Controllers\Api\WorkflowController;
    use App\Http\Controllers\Api\WorkOrderController;
    use App\Models\User;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Route;

    Route::get('/health', function () {
        try {
            DB::connection()->getPdo();

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'users' => User::count(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'database' => $e->getMessage(),
            ], 503);
        }
    });

    Route::get('/app-config', [AppConfigController::class, 'show']);

    Route::get('/internal/production-reminders', function () {
        $key = (string) config('app.cron_key');
        if ($key === '' || ! hash_equals($key, (string) request()->query('key', ''))) {
            abort(403, 'Invalid cron key');
        }

        $sent = app(\App\Services\ProductionCompletionReminderService::class)->sendDueReminders();

        return response()->json(['sent' => $sent]);
    });

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/change-password', [AuthController::class, 'changePassword']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::get('/profile/activity', [ProfileController::class, 'activity']);

        Route::get('/company-profile', [CompanyProfileController::class, 'show']);
        Route::post('/company-profile', [CompanyProfileController::class, 'store']);
        Route::put('/company-profile', [CompanyProfileController::class, 'update']);

        Route::get('/clients', [ClientController::class, 'index']);
        Route::post('/clients', [ClientController::class, 'store']);
        Route::put('/clients/{client}', [ClientController::class, 'update']);
        Route::put('/clients/{client}/status', [ClientController::class, 'updateStatus']);

        Route::get('/sales-tracking/summary', [SalesTrackingController::class, 'summary']);
        Route::post('/sales-tracking/quick-sale', [SalesTrackingController::class, 'quickSale']);
        Route::get('/sales-orders', [SalesTrackingController::class, 'orders']);
        Route::post('/sales-orders', [SalesTrackingController::class, 'storeOrder']);
        Route::post('/sales-orders/{salesOrder}/fulfill', [SalesTrackingController::class, 'fulfillOrder']);
        Route::post('/sales-orders/{salesOrder}/sync-inventory', [SalesTrackingController::class, 'syncInventory']);
        Route::put('/sales-orders/{salesOrder}/status', [SalesTrackingController::class, 'updateOrderStatus']);

        Route::get('/vendors', [VendorController::class, 'index']);
        Route::post('/vendors', [VendorController::class, 'store']);
        Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
        Route::put('/vendors/{vendor}/status', [VendorController::class, 'updateStatus']);

        Route::get('/purchase-requisitions', [PurchaseRequisitionController::class, 'index']);
        Route::post('/purchase-requisitions', [PurchaseRequisitionController::class, 'store']);
        Route::put('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'update']);
        Route::put('/purchase-requisitions/{purchaseRequisition}/status', [PurchaseRequisitionController::class, 'updateStatus']);
        Route::put('/purchase-requisitions/{purchaseRequisition}/review', [PurchaseRequisitionController::class, 'review']);
        Route::delete('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'destroy']);

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('/purchase-orders/approved-prs', [PurchaseOrderController::class, 'approvedPrs']);
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::put('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus']);
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
        Route::post('/purchase-orders/{purchaseOrder}/delete', [PurchaseOrderController::class, 'destroy']);

        Route::get('/checkin-checkout', [CheckinController::class, 'index']);
        Route::put('/checkin-checkout/{transaction}', [CheckinController::class, 'update']);
        Route::post('/checkin-checkout/{transaction}/receive-items', [CheckinController::class, 'receiveItems']);
        Route::post('/checkin-checkout/{transaction}/dispatch-items', [CheckinController::class, 'dispatchItems']);
        Route::post('/checkin-checkout/{transaction}/mark-items-received', [CheckinController::class, 'markItemsReceived']);
        Route::post('/checkin', [CheckinController::class, 'checkin']);
        Route::put('/checkin/{transaction}', [CheckinController::class, 'update']);
        Route::post('/checkout', [CheckinController::class, 'checkout']);

        Route::get('/material-requisitions', [MaterialRequisitionController::class, 'index']);
        Route::post('/material-requisitions', [MaterialRequisitionController::class, 'store']);
        Route::post('/material-requisitions/assign', [MaterialRequisitionController::class, 'assign']);
        Route::put('/material-requisitions/{materialRequisition}/issue', [MaterialRequisitionController::class, 'issue']);
        Route::put('/material-requisitions/{materialRequisition}/reject', [MaterialRequisitionController::class, 'reject']);

        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::post('/inventory', [InventoryController::class, 'store']);
        Route::put('/inventory/{inventory}', [InventoryController::class, 'update']);
        Route::post('/inventory/{inventory}/adjust', [InventoryController::class, 'adjust']);

        Route::get('/machinery', [MachineryController::class, 'index']);
        Route::post('/machinery', [MachineryController::class, 'store']);
        Route::put('/machinery/{machinery}', [MachineryController::class, 'update']);
        Route::delete('/machinery/{machinery}', [MachineryController::class, 'destroy']);
        Route::get('/maintenance-logs', [MaintenanceLogController::class, 'index']);
        Route::post('/machinery/{machinery}/maintenance-logs', [MaintenanceLogController::class, 'store']);

        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents/from-base64', [DocumentController::class, 'storeFromBase64']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
        Route::post('/documents/{document}/delete', [DocumentController::class, 'destroy']);

        Route::get('/gauges', [GaugeController::class, 'index']);
        Route::post('/gauges', [GaugeController::class, 'store']);
        Route::put('/gauges/{gauge}', [GaugeController::class, 'update']);
        Route::delete('/gauges/{gauge}', [GaugeController::class, 'destroy']);
        Route::put('/gauges/{gauge}/maintenance', [GaugeController::class, 'updateMaintenance']);
        Route::post('/gauges/{gauge}/calibrations', [GaugeController::class, 'storeCalibration']);
        Route::post('/gauges/{gauge}/calibrations/{calibration}/certificate', [GaugeController::class, 'attachCalibrationCertificate']);
        Route::post('/gauges/{gauge}/calibrations/{calibration}/document', [GaugeController::class, 'linkCalibrationDocument']);
        Route::post('/gauges/{gauge}/calibrations/{calibration}/certificate/clear', [GaugeController::class, 'clearCalibrationCertificate']);

        Route::get('/machine-times', [MachineTimeController::class, 'index']);
        Route::put('/machine-times', [MachineTimeController::class, 'upsert']);

        Route::get('/items', [ItemController::class, 'index']);
        Route::post('/items', [ItemController::class, 'store']);
        Route::put('/items/{item}', [ItemController::class, 'update']);
        Route::delete('/items/{item}', [ItemController::class, 'destroy']);

        Route::get('/processes', [ProcessController::class, 'index']);
        Route::post('/processes', [ProcessController::class, 'store']);
        Route::put('/processes/{process}', [ProcessController::class, 'update']);
        Route::delete('/processes/{process}', [ProcessController::class, 'destroy']);

        Route::get('/workflows', [WorkflowController::class, 'index']);
        Route::post('/workflows', [WorkflowController::class, 'store']);
        Route::put('/workflows/{workflow}', [WorkflowController::class, 'update']);
        Route::delete('/workflows/{workflow}', [WorkflowController::class, 'destroy']);

        Route::get('/work-orders', [WorkOrderController::class, 'index']);
        Route::get('/work-orders/manufactured-products', [WorkOrderController::class, 'manufacturedProducts']);
        Route::post('/work-orders', [WorkOrderController::class, 'store']);
        Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update']);
        Route::put('/work-orders/{workOrder}/route', [WorkOrderController::class, 'updateRoute']);
        Route::put('/work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus']);
        Route::put('/work-orders/{workOrder}/sync-stages', [WorkOrderController::class, 'syncStages']);
        Route::put('/work-orders/{workOrder}/prepare-wip', [WorkOrderController::class, 'prepareWip']);
        Route::put('/work-orders/{workOrder}/priority', [WorkOrderController::class, 'updatePriority']);
        Route::delete('/work-orders/{workOrder}', [WorkOrderController::class, 'destroy']);
        Route::post('/work-orders/{workOrder}/delete', [WorkOrderController::class, 'destroy']);

        Route::get('/job-work', [JobWorkController::class, 'index']);
        Route::get('/job-work/movements', [JobWorkController::class, 'movements']);
        Route::post('/job-work', [JobWorkController::class, 'store']);
        Route::put('/job-work/{jobWork}', [JobWorkController::class, 'update']);
        Route::put('/job-work/{jobWork}/status', [JobWorkController::class, 'updateStatus']);
        Route::delete('/job-work/{jobWork}', [JobWorkController::class, 'destroy']);

        Route::get('/finished-goods', [FinishedGoodController::class, 'index']);
        Route::post('/finished-goods', [FinishedGoodController::class, 'store']);
        Route::put('/finished-goods/{finishedGood}', [FinishedGoodController::class, 'update']);

        Route::get('/rejections', [RejectionController::class, 'index']);
        Route::post('/rejections', [RejectionController::class, 'store']);
        Route::put('/rejections/{rejection}', [RejectionController::class, 'update']);
        Route::put('/rejections/{rejection}/rework', [RejectionController::class, 'updateRework']);

        Route::get('/quality-inspections', [QualityInspectionController::class, 'index']);
        Route::get('/quality-inspections/{qualityInspection}', [QualityInspectionController::class, 'show']);
        Route::post('/quality-inspections/{qualityInspection}/reject-item', [QualityInspectionController::class, 'rejectItem']);
        Route::post('/quality-inspections/{qualityInspection}/pass-item', [QualityInspectionController::class, 'passItem']);
        Route::post('/quality-inspections/{qualityInspection}/pass', [QualityInspectionController::class, 'pass']);

        Route::get('/packing', [PackingController::class, 'index']);
        Route::put('/packing/{packing}', [PackingController::class, 'update']);

        Route::get('/production/batches', [ProductionController::class, 'batches']);
        Route::post('/production/batches', [ProductionController::class, 'store']);
        Route::delete('/production/batches/{batch}', [ProductionController::class, 'destroyBatch']);
        Route::post('/production/batches/{batch}/delete', [ProductionController::class, 'destroyBatch']);
        Route::put('/production/batches/{batch}/status', [ProductionController::class, 'updateStatus']);
        Route::put('/production/batches/{batch}/priority', [ProductionController::class, 'updatePriority']);
        Route::post('/production/advance-stage', [ProductionController::class, 'advanceStage']);
        Route::post('/production/send-job-work', [ProductionController::class, 'sendForJobWork']);
        Route::post('/production/receive-job-work', [ProductionController::class, 'receiveJobWorkReturn']);
        Route::post('/production/finish-last-stage', [ProductionController::class, 'finishLastStage']);
        Route::post('/production/complete-batch', [ProductionController::class, 'completeBatch']);

        Route::get('/daily-production', [DailyProductionController::class, 'index']);
        Route::post('/daily-production', [DailyProductionController::class, 'store']);
        Route::put('/daily-production/{dailyProduction}', [DailyProductionController::class, 'update']);
        Route::delete('/daily-production/{dailyProduction}', [DailyProductionController::class, 'destroy']);

        Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

        Route::get('/production/material-requests', [ProductionController::class, 'indexMaterialRequests']);
        Route::put('/production/material-requests/{materialRequest}/review', [ProductionController::class, 'reviewMaterialRequest']);
        Route::get('/production/batches/{batch}/material-requests', [ProductionController::class, 'materialRequests']);
        Route::post('/production/batches/{batch}/material-requests', [ProductionController::class, 'storeMaterialRequest']);
        Route::post('/production/batches/{batch}/rejections', [ProductionController::class, 'storeBatchRejection']);

        Route::get('/dashboard/bootstrap', [DashboardController::class, 'bootstrap']);
        Route::get('/dashboard/item-search', [DashboardController::class, 'itemSearch']);
        Route::get('/dashboard/client-orders', [DashboardController::class, 'clientOrders']);
        Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);
        Route::get('/dashboard/low-stock-alerts', [DashboardController::class, 'lowStockAlerts']);
        Route::get('/dashboard/production-trends', [DashboardController::class, 'productionTrends']);
        Route::get('/dashboard/daily-production-trends', [DashboardController::class, 'dailyProductionTrends']);
        Route::get('/dashboard/profit-loss-trends', [DashboardController::class, 'profitLossTrends']);
        Route::get('/dashboard/recent-activities', [DashboardController::class, 'recentActivities']);
        Route::get('/dashboard/stage-flow', [DashboardController::class, 'stageFlow']);
        Route::get('/dashboard/inventory-trends', [DashboardController::class, 'inventoryTrends']);
        Route::get('/dashboard/tasks', [DashboardController::class, 'tasks']);
        Route::get('/dashboard/assignees', [DashboardController::class, 'assignees']);
        Route::post('/dashboard/tasks', [DashboardController::class, 'storeTask']);
        Route::put('/dashboard/tasks/{task}', [DashboardController::class, 'updateTask']);

        Route::get('/reports/inventory', [ReportController::class, 'inventory']);
        Route::get('/reports/production', [ReportController::class, 'production']);
        Route::get('/reports/vendor', [ReportController::class, 'vendor']);
        Route::get('/reports/customer', [ReportController::class, 'customer']);
        Route::get('/reports/finished-goods', [ReportController::class, 'finishedGoods']);
        Route::get('/reports/rejections', [ReportController::class, 'rejections']);
        Route::get('/reports/most-selling-products', [ReportController::class, 'mostSellingProducts']);
        Route::get('/reports/machine-active-work', [ReportController::class, 'machineActiveWork']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications', [NotificationController::class, 'store']);
        Route::post('/notifications/device-token', [UserDeviceTokenController::class, 'store']);
        Route::delete('/notifications/device-token', [UserDeviceTokenController::class, 'destroy']);
        Route::put('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
        Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::post('/audit-logs', [AuditLogController::class, 'store']);

        Route::get('/roles', [RoleController::class, 'index']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}/activity', [UserController::class, 'activity']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::get('/import-export/{entity}/template', [ImportExportController::class, 'template']);
        Route::get('/import-export/{entity}/export', [ImportExportController::class, 'export']);
        Route::post('/import-export/{entity}/import', [ImportExportController::class, 'import']);
    });
