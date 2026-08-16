<?php

namespace Database\Seeders;

use App\Models\Workflow;
use Illuminate\Database\Seeder;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Workflow::updateOrCreate(
            ['name' => 'Standard Manufacturing'],
            [
                'stages' => [
                    ['name' => 'Raw Material', 'order' => 1],
                    ['name' => 'Cutting', 'order' => 2],
                    ['name' => 'Machining', 'order' => 3],
                    ['name' => 'Assembly', 'order' => 4],
                    ['name' => 'Quality Check', 'order' => 5],
                    ['name' => 'Packaging', 'order' => 6],
                    ['name' => 'Job Work', 'order' => 7],
                    ['name' => 'Finished Goods', 'order' => 8],
                ],
                'is_active' => true,
            ],
        );
    }
}
