<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $clients = [
            [
                'name' => 'ESCORTS KUBOTA LIMITED',
                'contact_person' => 'Harish Kumar',
                'email' => 'harish@nationalelectric.pk',
                'phone' => '+91 98765 43210',
                'address' => 'Plot 12, Industrial Area',
                'city' => 'Agra',
                'status' => 'active',
                'total_orders' => 48,
                'last_order_date' => '2024-01-15',
                'items' => [
                    ['item_id' => 1, 'default_qty' => 500],
                ],
            ],
            [
                'name' => 'ACTION CONSTRUCTION EQUIPMENT LIMITED(DHATIR)',
                'contact_person' => 'Ramesh Singh',
                'email' => 'ramesh@metroeng.com',
                'phone' => '+91 87654 32109',
                'address' => 'Sector F-7, Main Boulevard',
                'city' => 'Jaipur',
                'status' => 'active',
                'total_orders' => 32,
                'last_order_date' => '2024-01-12',
                'items' => [
                    ['item_id' => 2, 'default_qty' => 150],
                ],
            ],
            [
                'name' => 'ACTION CONSTRUCTION EQUIPMENT LIMITED(P8)',
                'contact_person' => 'Harsh Arora',
                'email' => 'harsh@gulfmotors.pk',
                'phone' => '+91 76543 21098',
                'address' => 'Hub Industrial Zone',
                'city' => 'Jaipur',
                'status' => 'active',
                'total_orders' => 21,
                'last_order_date' => '2024-01-08',
                'items' => [
                    ['item_id' => 1, 'default_qty' => 300],
                    ['item_id' => 2, 'default_qty' => 80],
                ],
            ],
            [
                'name' => 'ACTION CONSTRUCTION EQUIPMENT LIMITED(PLANT 2)',
                'contact_person' => 'Rakhi Sharma',
                'email' => 'Rakhi@precisiontools.pk',
                'phone' => '+91 98765 43211',
                'address' => '22-A Faisalabad Road',
                'city' => 'Jaipur',
                'status' => 'inactive',
                'total_orders' => 9,
                'last_order_date' => '2023-11-20',
                'items' => [
                    ['item_id' => 3, 'default_qty' => 40],
                ],
            ],
            [
                'name' => 'ACTION CONSTRUCTION EQUIPMENT LIMITED(PLANT 6)',
                'contact_person' => 'Pankaj Mehta',
                'email' => 'pankaj@alphadist.pk',
                'phone' => '+91 98765 43212',
                'address' => 'Warehouse 4, GT Road',
                'city' => 'Ajmer',
                'status' => 'active',
                'total_orders' => 15,
                'last_order_date' => '2024-01-10',
                'items' => [
                    ['item_id' => 3, 'default_qty' => 60],
                ],
            ],
            [
                'name' => 'ACTION CONSTRUCTION EQUIPMENT LIMITED(UNIT-IV)',
                'contact_person' => 'Pankaj Mehta',
                'email' => 'unit4@alphadist.pk',
                'phone' => '+91 98765 43212',
                'address' => 'Warehouse 4, GT Road',
                'city' => 'Ajmer',
                'status' => 'active',
                'total_orders' => 15,
                'last_order_date' => '2024-01-10',
                'items' => [
                    ['item_id' => 1, 'default_qty' => 200],
                    ['item_id' => 3, 'default_qty' => 25],
                ],
            ],
        ];

        foreach ($clients as $client) {
            Client::updateOrCreate(
                ['name' => $client['name']],
                $client,
            );
        }
    }
}
