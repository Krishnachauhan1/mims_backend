<?php

namespace Database\Seeders;

use App\Models\DashboardTask;
use Illuminate\Database\Seeder;

class DashboardTaskSeeder extends Seeder
{
    public function run(): void
    {
        $tasks = [
            [
                'task_name' => 'Review low stock raw materials',
                'assigned_to' => 'Inventory Manager',
                'module' => 'Inventory',
                'status' => 'pending',
                'activity' => 'Check raw material levels and raise PR if needed',
                'due_date' => now()->addDays(2),
            ],
            [
                'task_name' => 'Approve pending production batch',
                'assigned_to' => 'Production Supervisor',
                'module' => 'Production',
                'status' => 'in_progress',
                'activity' => 'Validate batch QC before stage advance',
                'due_date' => now()->addDay(),
            ],
            [
                'task_name' => 'Schedule gauge calibration',
                'assigned_to' => 'Quality Engineer',
                'module' => 'Gauges',
                'status' => 'on_hold',
                'activity' => 'Coordinate with vendor for overdue instruments',
                'due_date' => now()->addDays(5),
            ],
        ];

        foreach ($tasks as $task) {
            DashboardTask::query()->updateOrCreate(
                ['task_name' => $task['task_name']],
                $task,
            );
        }
    }
}
