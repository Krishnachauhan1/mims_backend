<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'grade',
        'diameter_mm',
        'unit',
        'min_stock_alert',
        'purchase_price',
        'category',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'float',
            'diameter_mm' => 'float',
            'min_stock_alert' => 'integer',
        ];
    }
}
