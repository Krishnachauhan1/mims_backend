<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            ClientSeeder::class,
            ItemSeeder::class,
            VendorSeeder::class,
            PurchaseRequisitionSeeder::class,
            PurchaseOrderSeeder::class,
            MachinerySeeder::class,
            MaintenanceLogSeeder::class,
            GaugeSeeder::class,
            ProcessSeeder::class,
            WorkflowSeeder::class,
            ProductionBatchSeeder::class,
            DashboardTaskSeeder::class,
            NotificationSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
