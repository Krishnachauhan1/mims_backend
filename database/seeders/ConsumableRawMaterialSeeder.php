<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

class ConsumableRawMaterialSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            Item::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'purchase_price' => $row['purchase_price'] ?? 0,
                    'category' => 'raw_material',
                    'status' => 'active',
                ],
            );
        }
    }

    /** @return list<array{code: string, name: string, unit: string, purchase_price?: float}> */
    private function catalog(): array
    {
        return [
            [
                'code' => 'CNC-2',
                'name' => 'CNC Insert Etc',
                'unit' => 'pcs',
                'purchase_price' => 320.0,
            ],
            [
                'code' => 'GMW-25',
                'name' => 'Grinding Machine Wheel',
                'unit' => 'mtr',
                'purchase_price' => 12.5,
            ],
        ];
    }
}
