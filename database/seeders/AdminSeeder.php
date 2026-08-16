<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'harish@nationalelectric.pk'],
            [
                'name' => 'Harish Kumar',
                'role' => 'admin',
                'department' => 'Administration',
                'password' => Hash::make('password'),
                'phone' => '+91 98765 43210',
                'is_active' => true,
                'permissions' => null,
            ],
        );
    }
}
