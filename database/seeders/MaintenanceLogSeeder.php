<?php

namespace Database\Seeders;

use App\Models\Machinery;
use App\Models\MachineryMaintenanceLog;
use Illuminate\Database\Seeder;

class MaintenanceLogSeeder extends Seeder
{
    public function run(): void
    {
        $machine = Machinery::query()->where('machine_code', 'CNC-001')->first();
        if (! $machine) {
            return;
        }

        MachineryMaintenanceLog::query()->firstOrCreate(
            [
                'machinery_id' => $machine->id,
                'type' => 'Preventive',
                'performed_at' => now()->subDays(14)->toDateString(),
            ],
            [
                'notes' => 'Spindle lubrication and axis calibration check.',
                'status' => 'completed',
                'performed_by' => 'Maintenance Team',
            ],
        );
    }
}
