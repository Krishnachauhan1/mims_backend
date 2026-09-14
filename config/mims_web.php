<?php

use App\Models\AuditLog;
use App\Models\DailyProductionEntry;
use App\Models\FinishedGood;
use App\Models\Gauge;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JobWork;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rejection;

/**
 * List-only modules for Laravel web (full CRUD modules have dedicated controllers).
 */
return [
    'resources' => [
        'tasks' => [
            'title' => 'Tasks',
            'permission' => 'tasks.view',
            'model' => \App\Models\DashboardTask::class,
            'columns' => [
                'title' => 'Title',
                'status' => 'Status',
                'priority' => 'Priority',
                'due_date' => 'Due',
            ],
        ],
        'inventory' => [
            'title' => 'Inventory',
            'permission' => 'inventory.view',
            'model' => InventoryItem::class,
            'columns' => [
                'name' => 'Item',
                'type' => 'Type',
                'quantity' => 'Qty',
                'unit' => 'Unit',
                'location' => 'Location',
            ],
        ],
        'checkin' => [
            'title' => 'Check-in / Check-out',
            'permission' => 'checkin.view',
            'model' => InventoryTransaction::class,
            'columns' => [
                'type' => 'Type',
                'item_name' => 'Item',
                'quantity' => 'Qty',
                'status' => 'Status',
                'created_at' => 'Date',
            ],
        ],
        'production' => [
            'title' => 'Production',
            'permission' => 'production.view',
            'model' => ProductionBatch::class,
            'columns' => [
                'batch_number' => 'Batch',
                'product' => 'Product',
                'current_stage' => 'Stage',
                'status' => 'Status',
            ],
        ],
        'daily-production' => [
            'title' => 'Daily Production',
            'permission' => 'daily_production.view',
            'model' => DailyProductionEntry::class,
            'columns' => [
                'production_date' => 'Date',
                'item_name' => 'Item',
                'quantity' => 'Qty',
                'shift' => 'Shift',
            ],
        ],
        'quality' => [
            'title' => 'Quality',
            'permission' => 'quality.view',
            'model' => \App\Models\QualityInspection::class,
            'columns' => [
                'batch_number' => 'Batch',
                'stage' => 'Stage',
                'result' => 'Result',
                'inspected_at' => 'Inspected',
            ],
        ],
        'packing' => [
            'title' => 'Packing',
            'permission' => 'packing.view',
            'model' => \App\Models\PackingEntry::class,
            'columns' => [
                'batch_number' => 'Batch',
                'packed_qty' => 'Packed',
                'status' => 'Status',
            ],
        ],
        'rejection' => [
            'title' => 'Rejection',
            'permission' => 'rejection.view',
            'model' => Rejection::class,
            'columns' => [
                'batch_number' => 'Batch',
                'quantity' => 'Qty',
                'reason' => 'Reason',
                'status' => 'Status',
            ],
        ],
        'gauge-management' => [
            'title' => 'Gauge Management',
            'permission' => 'gauge.view',
            'model' => Gauge::class,
            'columns' => [
                'instrument_id' => 'ID',
                'name' => 'Name',
                'status' => 'Status',
                'next_calibration_date' => 'Next Cal.',
            ],
        ],
        'finished-goods' => [
            'title' => 'Finished Goods',
            'permission' => 'finished_goods.view',
            'model' => FinishedGood::class,
            'columns' => [
                'product' => 'Product',
                'stock' => 'Stock',
                'unit' => 'Unit',
            ],
        ],
        'sales-tracking' => [
            'title' => 'Sales Tracking',
            'permission' => 'sales_tracking.view',
            'model' => \App\Models\SalesOrder::class,
            'columns' => [
                'order_no' => 'Order',
                'client_name' => 'Client',
                'status' => 'Status',
                'total_amount' => 'Amount',
            ],
        ],
        'purchase-requisition' => [
            'title' => 'Purchase Requisition',
            'permission' => 'purchase_requisition.view',
            'model' => PurchaseRequisition::class,
            'columns' => [
                'pr_number' => 'PR No.',
                'status' => 'Status',
                'requested_by' => 'Requested By',
            ],
        ],
        'purchase-order' => [
            'title' => 'Purchase Order',
            'permission' => 'purchase_order.view',
            'model' => PurchaseOrder::class,
            'columns' => [
                'po_number' => 'PO No.',
                'vendor_name' => 'Vendor',
                'status' => 'Status',
                'total_amount' => 'Amount',
            ],
        ],
        'job-work' => [
            'title' => 'Job Work',
            'permission' => 'job_work.view',
            'model' => JobWork::class,
            'columns' => [
                'job_number' => 'Job No.',
                'vendor_name' => 'Vendor',
                'status' => 'Status',
            ],
        ],
        'reports' => [
            'title' => 'Reports',
            'permission' => 'reports.view',
            'model' => ProductionBatch::class,
            'columns' => [
                'batch_number' => 'Batch',
                'product' => 'Product',
                'status' => 'Status',
                'completed_at' => 'Completed',
            ],
        ],
        'all-reports' => [
            'title' => 'All Reports',
            'permission' => 'reports.view',
            'model' => ProductionBatch::class,
            'columns' => [
                'batch_number' => 'Batch',
                'product' => 'Product',
                'status' => 'Status',
                'completed_at' => 'Completed',
            ],
        ],
        'notifications' => [
            'title' => 'Notifications',
            'permission' => 'notifications.view',
            'model' => \App\Models\Notification::class,
            'columns' => [
                'title' => 'Title',
                'type' => 'Type',
                'is_read' => 'Read',
                'created_at' => 'Date',
            ],
        ],
        'audit-logs' => [
            'title' => 'Audit Logs',
            'permission' => 'audit_logs.view',
            'model' => AuditLog::class,
            'columns' => [
                'action' => 'Action',
                'module' => 'Module',
                'user_name' => 'User',
                'created_at' => 'Date',
            ],
        ],
    ],
];
