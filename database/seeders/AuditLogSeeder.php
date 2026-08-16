<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use Illuminate\Database\Seeder;

class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $logs = [
            [
                'action' => 'User login',
                'module' => 'Auth',
                'type' => 'login',
                'description' => 'Admin user signed in successfully',
                'user_name' => 'Admin',
                'ip_address' => '127.0.0.1',
            ],
            [
                'action' => 'Material checked in',
                'module' => 'Check-In',
                'type' => 'check_in',
                'description' => 'Steel Rod 12mm — 500 pcs received from Apex Metals',
                'user_name' => 'Store Keeper',
                'ip_address' => '127.0.0.1',
            ],
            [
                'action' => 'Inventory updated',
                'module' => 'Inventory',
                'type' => 'inventory_update',
                'description' => 'Stock adjusted for Steel Rod 12mm (+500)',
                'user_name' => 'Inventory Manager',
                'ip_address' => '127.0.0.1',
            ],
            [
                'action' => 'Workflow stage advanced',
                'module' => 'Workflow',
                'type' => 'workflow_change',
                'description' => 'Batch B-2026-014 moved from Cutting to Assembly',
                'user_name' => 'Production Supervisor',
                'ip_address' => '127.0.0.1',
            ],
            [
                'action' => 'Rejection logged',
                'module' => 'Rejection',
                'type' => 'product_movement',
                'description' => '3 units rejected at Assembly — dimensional variance',
                'user_name' => 'Quality Engineer',
                'ip_address' => '127.0.0.1',
            ],
            [
                'action' => 'Report exported',
                'module' => 'Reports',
                'type' => 'report_export',
                'description' => 'Production report exported for March 2026',
                'user_name' => 'Admin',
                'ip_address' => '127.0.0.1',
            ],
        ];

        foreach ($logs as $log) {
            AuditLog::query()->updateOrCreate(
                [
                    'action' => $log['action'],
                    'description' => $log['description'],
                ],
                $log,
            );
        }
    }
}
