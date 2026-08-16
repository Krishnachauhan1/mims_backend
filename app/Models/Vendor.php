<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = [
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'city',
        'status',
        'total_orders',
        'last_order_date',
        'items',
    ];

    protected function casts(): array
    {
        return [
            'last_order_date' => 'date',
            'items' => 'array',
        ];
    }
}
