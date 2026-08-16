<?php

namespace Database\Seeders;

use App\Models\Process;
use Illuminate\Database\Seeder;

class ProcessSeeder extends Seeder
{
    public function run(): void
    {
        $processes = [
            [
                'name' => 'Welding',
                'description' => 'Join materials, usually metals or thermoplastics, by using high heat to melt the parts together and allowing them to cool, causing fusion.',
                'process_type' => 'in_house',
                'rate' => 10.0,
                'unit' => 'kg',
                'status' => 'active',
            ],
            [
                'name' => 'Grinding',
                'description' => 'Creating a smooth surface finish on a workpiece.',
                'process_type' => 'job_work',
                'rate' => 15.0,
                'unit' => 'pcs',
                'status' => 'active',
            ],
        ];

        foreach ($processes as $process) {
            Process::updateOrCreate(
                ['name' => $process['name']],
                $process,
            );
        }
    }
}
