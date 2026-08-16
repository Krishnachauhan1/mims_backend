<?php

namespace Database\Seeders;

use App\Models\Gauge;
use Illuminate\Database\Seeder;

class GaugeSeeder extends Seeder
{
    public function run(): void
    {
        $gauges = [
            [
                'instrument_id' => 'MIC-001',
                'name' => 'Digital Micrometer 0-25mm',
                'type' => 'Measuring',
                'manufacturer' => 'Mitutoyo',
                'location' => 'QC Lab - Bay 2',
                'status' => 'active',
                'last_calibration_date' => '2025-12-15',
                'next_calibration_date' => '2026-12-15',
                'calibration_status' => 'valid',
            ],
            [
                'instrument_id' => 'VEN-014',
                'name' => 'Vernier Caliper 300mm',
                'type' => 'Measuring',
                'manufacturer' => 'Fowler',
                'location' => 'Machining - Line 1',
                'status' => 'active',
                'last_calibration_date' => '2026-01-10',
                'next_calibration_date' => '2026-07-10',
                'calibration_status' => 'valid',
            ],
            [
                'instrument_id' => 'PRG-008',
                'name' => 'Dial Pressure Gauge 0-10 bar',
                'type' => 'Pressure',
                'manufacturer' => 'Wika',
                'location' => 'Hydraulic Test Bench',
                'status' => 'active',
                'last_calibration_date' => '2025-06-01',
                'next_calibration_date' => '2026-06-01',
                'calibration_status' => 'valid',
            ],
            [
                'instrument_id' => 'CMM-002',
                'name' => 'Coordinate Measuring Machine',
                'type' => 'CMM',
                'manufacturer' => 'Zeiss',
                'location' => 'Quality Lab',
                'status' => 'active',
                'last_calibration_date' => '2025-11-20',
                'next_calibration_date' => '2026-02-20',
                'calibration_status' => 'valid',
            ],
        ];

        foreach ($gauges as $gauge) {
            Gauge::updateOrCreate(
                ['instrument_id' => $gauge['instrument_id']],
                $gauge,
            );
        }
    }
}
