<?php

namespace Database\Seeders;

use App\Models\Notification;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $alerts = [
            [
                'title' => 'Low stock alert',
                'message' => 'Steel Rod 12mm is below minimum stock level',
                'type' => 'low_stock',
                'severity' => 'warning',
                'module' => 'Inventory',
                'reference' => 'INV-001',
                'route' => '/inventory',
            ],
            [
                'title' => 'Production batch completed',
                'message' => 'Batch B-2026-014 completed and moved to Finished Goods',
                'type' => 'production_completion',
                'severity' => 'success',
                'module' => 'Production',
                'reference' => 'B-2026-014',
                'route' => '/finished-goods',
            ],
            [
                'title' => 'Calibration due soon',
                'message' => 'Digital Vernier Caliper calibration due within 7 days',
                'type' => 'calibration_due',
                'severity' => 'info',
                'module' => 'Gauges',
                'reference' => 'GAU-003',
                'route' => '/gauges',
            ],
            [
                'title' => 'Rejection recorded',
                'message' => '3 units rejected at Assembly stage for Batch B-2026-011',
                'type' => 'rejection',
                'severity' => 'high',
                'module' => 'Rejection',
                'reference' => 'RJ-2026-011',
                'route' => '/rejection',
            ],
        ];

        foreach ($alerts as $alert) {
            Notification::query()->updateOrCreate(
                ['title' => $alert['title']],
                $alert,
            );
        }
    }
}
