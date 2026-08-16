<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = [
        'name',
        'sku',
        'type',
        'status',
        'quantity',
        'unit',
        'batch_number',
        'location',
        'min_stock',
    ];
}
