<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $fillable = [
        'order_number',
        'client_id',
        'client_name',
        'item_id',
        'product_name',
        'items',
        'unit',
        'quantity',
        'available_at_order',
        'shortfall',
        'status',
        'fulfilled_qty',
        'unit_price',
        'sold_at',
        'sold_by',
        'order_date',
        'delivery_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'order_date' => 'date',
            'delivery_date' => 'date',
            'quantity' => 'integer',
            'fulfilled_qty' => 'integer',
            'available_at_order' => 'integer',
            'shortfall' => 'integer',
            'unit_price' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }
}
