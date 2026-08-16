<?php

namespace Database\Seeders;

use App\Models\Machinery;
use Illuminate\Database\Seeder;

class MachinerySeeder extends Seeder
{
    public function run(): void
    {
        $machines = [
            [
                'sequence' => 1,
                'machine_code' => 'CNC-001',
                'name' => 'Bansaw Machine',
                'type' => 'Raw',
                'location' => 'Machining Bay 1',
                'capacity' => '500 units/day',
                'manufacturer' => 'Haas Automation',
                'status' => 'active',
                'default_stage' => 'Raw Material Cutting',
            ],
            [
                'sequence' => 2,
                'machine_code' => 'LAT-002',
                'name' => 'Lathe Machine',
                'type' => 'Lathe',
                'location' => 'Machining Bay 2',
                'capacity' => '300 units/day',
                'manufacturer' => 'DMG Mori',
                'status' => 'active',
                'default_stage' => 'Part Center & Faching',
            ],
            [
                'sequence' => 3,
                'machine_code' => 'ASM-004',
                'name' => 'SPM Machine',
                'type' => 'Assembly',
                'location' => 'Assembly Floor',
                'capacity' => '800 units/day',
                'manufacturer' => 'FlexLink',
                'status' => 'active',
                'default_stage' => 'Part Center & Faching',
            ],
            [
                'sequence' => 4,
                'machine_code' => 'PRS-003',
                'name' => 'CNC Machine',
                'type' => 'Press',
                'location' => 'Assembly Floor',
                'capacity' => '200 units/day',
                'manufacturer' => 'Schuler',
                'status' => 'maintenance',
                'default_stage' => 'Part Turning & Size',
            ],
            [
                'sequence' => 5,
                'machine_code' => 'CMM-002',
                'name' => 'VMC Machine',
                'type' => 'CMM',
                'location' => 'QC Lab - Bay 2',
                'capacity' => '120 inspections/day',
                'manufacturer' => 'Zeiss',
                'status' => 'active',
                'default_stage' => 'Part Drilling , Silot',
            ],
            [
                'sequence' => 6,
                'machine_code' => 'MNT-006',
                'name' => 'MNTR',
                'type' => 'CMM',
                'location' => 'QC Lab - Bay 2',
                'capacity' => '120 inspections/day',
                'manufacturer' => 'Zeiss',
                'status' => 'active',
                'default_stage' => 'Part Drilling , Silot,etc',
            ],
            [
                'sequence' => 7,
                'machine_code' => 'IND-007',
                'name' => 'Induction Machine',
                'type' => 'CMM',
                'location' => 'QC Lab - Bay 2',
                'capacity' => '120 inspections/day',
                'manufacturer' => 'Zeiss',
                'status' => 'active',
                'default_stage' => 'Part Hard',
            ],
            [
                'sequence' => 8,
                'machine_code' => 'GRD-008',
                'name' => 'Grinding Machine',
                'type' => 'CMM',
                'location' => 'QC Lab - Bay 2',
                'capacity' => '120 inspections/day',
                'manufacturer' => 'Zeiss',
                'status' => 'active',
                'default_stage' => 'Sharpening & Smoothing.',
            ],
            [
                'sequence' => 9,
                'machine_code' => 'WLD-009',
                'name' => 'Welding Machine',
                'type' => 'CMM',
                'location' => 'QC Lab - Bay 2',
                'capacity' => '120 inspections/day',
                'manufacturer' => 'Zeiss',
                'status' => 'active',
                'default_stage' => 'Fusing',
            ],
        ];

        foreach ($machines as $machine) {
            Machinery::updateOrCreate(
                ['machine_code' => $machine['machine_code']],
                $machine,
            );
        }
    }
}
