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
        'per_piece_time',
        'average_time',
        'cutting_weight',
        'final_weight',
        'cutting_size',
        'remark',
        'inactive_date',
        'purchase_price',
        'category',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'float',
            'diameter_mm' => 'float',
            'cutting_weight' => 'float',
            'final_weight' => 'float',
            'min_stock_alert' => 'integer',
            'per_piece_time' => 'float',
            'average_time' => 'float',
            'inactive_date' => 'date',
        ];
    }
}
