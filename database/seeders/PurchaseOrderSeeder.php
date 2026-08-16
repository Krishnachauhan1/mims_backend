<?php

namespace Database\Seeders;

use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;

class PurchaseOrderSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'po_number' => 'PO-DEMO-001',
                'pr_number' => 'PR-DEMO-002',
                'pr_id' => 2,
                'vendor_name' => 'OM SAI TRADERS',
                'contact_person' => 'Priya Sharma',
                'contact_mobile' => '9123456780',
                'status' => 'pending',
                'created_at' => '2026-03-02',
                'items' => [
                    [
                        'item_name' => 'Brass Sheet 2mm',
                        'quantity' => 200,
                        'unit' => 'kg',
                        'purchase_price' => 320.0,
                    ],
                ],
                'total_amount' => 64000.0,
            ],
            [
                'po_number' => 'PO-DEMO-002',
                'pr_number' => 'PR-DEMO-003',
                'pr_id' => 3,
                'vendor_name' => 'HANUMAN STEELS',
                'contact_person' => 'Amit Verma',
                'contact_mobile' => '9988776655',
                'status' => 'approved',
                'created_at' => '2026-02-26',
                'items' => [
                    [
                        'item_name' => 'Copper Wire 2.5mm',
                        'quantity' => 1000,
                        'unit' => 'meters',
                        'purchase_price' => 12.5,
                    ],
                ],
                'total_amount' => 12500.0,
            ],
        ];

        foreach ($rows as $row) {
            PurchaseOrder::updateOrCreate(
                ['po_number' => $row['po_number']],
                $row,
            );
        }
    }
}
