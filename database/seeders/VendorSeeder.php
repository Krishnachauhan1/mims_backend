<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            [
                'name' => 'SUNRISE STEEL AGENCY',
                'contact_person' => 'Vikram Patel',
                'email' => 'vikram@steelworks.in',
                'phone' => '+91 98123 45670',
                'address' => 'Plot 8, MIDC Industrial Estate',
                'city' => 'Pune',
                'status' => 'active',
                'total_orders' => 36,
                'last_order_date' => '2024-01-14',
                'items' => [
                    $this->vendorItem('EN18-42', 500, 45.0),
                ],
            ],
            [
                'name' => 'OM SAI TRADERS',
                'contact_person' => 'Anita Desai',
                'email' => 'anita@precisionbearings.com',
                'phone' => '+91 98234 56781',
                'address' => 'Sector 12, Industrial Area',
                'city' => 'Ahmedabad',
                'status' => 'active',
                'total_orders' => 28,
                'last_order_date' => '2024-01-11',
                'items' => [
                    $this->vendorItem('CNC-2', 200, 320.0),
                ],
            ],
            [
                'name' => 'HANUMAN STEELS',
                'contact_person' => 'Suresh Nair',
                'email' => 'suresh@globalfasteners.in',
                'phone' => '+91 98345 67892',
                'address' => 'Unit 5, Peenya Industrial Hub',
                'city' => 'Bangalore',
                'status' => 'active',
                'total_orders' => 19,
                'last_order_date' => '2024-01-09',
                'items' => [
                    $this->vendorItem('EN8D-25', 1000, 8.5),
                ],
            ],
            [
                'name' => 'EKTA TRADERS',
                'contact_person' => 'Meera Iyer',
                'email' => 'meera@chemcosupplies.com',
                'phone' => '+91 98456 78903',
                'address' => '22 Industrial Lane',
                'city' => 'Chennai',
                'status' => 'inactive',
                'total_orders' => 7,
                'last_order_date' => '2023-10-25',
                'items' => [
                    $this->vendorItem('CNC-2', 100, 220.0),
                ],
            ],
            [
                'name' => 'A.V. Unipack Private Limited',
                'contact_person' => 'Rajesh Gupta',
                'email' => 'rajesh@electrohub.in',
                'phone' => '+91 98567 89014',
                'address' => 'Warehouse 2, GT Road',
                'city' => 'Delhi',
                'status' => 'active',
                'total_orders' => 22,
                'last_order_date' => '2024-01-13',
                'items' => [
                    $this->vendorItem('GMW-25', 60, 350.0),
                ],
            ],
        ];

        foreach ($vendors as $vendor) {
            $payload = $vendor;
            $payload['items'] = array_values(array_filter(
                $payload['items'],
                fn (?array $link) => $link !== null,
            ));

            Vendor::updateOrCreate(
                ['name' => $vendor['name']],
                $payload,
            );
        }
    }

    /** @return array{item_id: int, default_qty: float|int, rate: float}|null */
    private function vendorItem(string $code, float|int $defaultQty, float $rate): ?array
    {
        $itemId = Item::query()->where('code', $code)->value('id');
        if ($itemId === null) {
            return null;
        }

        return [
            'item_id' => (int) $itemId,
            'default_qty' => $defaultQty,
            'rate' => $rate,
        ];
    }
}
