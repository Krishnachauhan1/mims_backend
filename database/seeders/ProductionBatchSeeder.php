<?php

namespace Database\Seeders;

use App\Models\ProductionBatch;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class ProductionBatchSeeder extends Seeder
{
    public function run(): void
    {
        $workflowId = Workflow::query()->where('name', 'Standard Manufacturing')->value('id');

        $rows = [
            [
                'batch_number' => 'BATCH-DEMO-001',
                'product_name' => 'Copper Wire Spool',
                'quantity' => 1000,
                'workflow_id' => $workflowId,
                'current_stage' => 'Cutting',
                'current_stage_index' => 1,
                'stage_order' => 2,
                'total_stages' => 8,
                'status' => 'in_progress',
                'started_at' => '2026-02-26 15:00:00',
                'stage_started_at' => '2026-02-27 09:00:00',
                'pr_number' => 'PR-DEMO-003',
            ],
            [
                'batch_number' => 'BATCH-DEMO-002',
                'product_name' => 'Aluminium Plate Set',
                'quantity' => 150,
                'workflow_id' => $workflowId,
                'current_stage' => 'Machining',
                'current_stage_index' => 2,
                'stage_order' => 3,
                'total_stages' => 8,
                'status' => 'in_progress',
                'started_at' => '2026-02-21 10:00:00',
                'stage_started_at' => '2026-02-22 11:00:00',
                'pr_number' => 'PR-DEMO-004',
            ],
        ];

        foreach ($rows as $row) {
            ProductionBatch::updateOrCreate(
                ['batch_number' => $row['batch_number']],
                $row,
            );
        }
    }
}
