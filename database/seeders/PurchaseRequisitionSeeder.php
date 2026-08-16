<?php

namespace Database\Seeders;

use App\Models\PurchaseRequisition;
use Illuminate\Database\Seeder;

class PurchaseRequisitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'pr_number' => 'PR-DEMO-001',
                'vendor_name' => 'SUNRISE STEEL AGENCY',
                'contact_person' => 'Rajesh Kumar',
                'contact_mobile' => '9876543210',
                'status' => 'submitted',
                'created_at' => '2026-03-01',
                'items' => [
                    [
                        'item_name' => 'Steel Rod 12mm',
                        'quantity' => 500,
                        'unit' => 'pcs',
                        'purchase_price' => 45.0,
                    ],
                ],
            ],
            [
                'pr_number' => 'PR-DEMO-002',
                'vendor_name' => 'OM SAI TRADERS',
                'contact_person' => 'Priya Sharma',
                'contact_mobile' => '9123456780',
                'status' => 'submitted',
                'created_at' => '2026-02-28',
                'items' => [
                    [
                        'item_name' => 'Brass Sheet 2mm',
                        'quantity' => 200,
                        'unit' => 'kg',
                        'purchase_price' => 320.0,
                    ],
                ],
            ],
            [
                'pr_number' => 'PR-DEMO-003',
                'vendor_name' => 'HANUMAN STEELS',
                'contact_person' => 'Amit Verma',
                'contact_mobile' => '9988776655',
                'status' => 'submitted',
                'created_at' => '2026-02-25',
                'items' => [
                    [
                        'item_name' => 'Copper Wire 2.5mm',
                        'quantity' => 1000,
                        'unit' => 'meters',
                        'purchase_price' => 12.5,
                    ],
                ],
            ],
        ];

        foreach ($rows as $row) {
            $firstItem = $row['items'][0];

            PurchaseRequisition::updateOrCreate(
                ['pr_number' => $row['pr_number']],
                [
                    'vendor_name' => $row['vendor_name'],
                    'contact_person' => $row['contact_person'],
                    'contact_mobile' => $row['contact_mobile'],
                    'status' => $row['status'],
                    'items' => $row['items'],
                    'item_name' => $firstItem['item_name'],
                    'quantity' => $firstItem['quantity'],
                    'unit' => $firstItem['unit'],
                    'created_at' => $row['created_at'],
                    'updated_at' => now(),
                ],
            );
        }
    }
}
