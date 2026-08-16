<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rejection extends Model
{
    protected $fillable = [
        'production_batch_id',
        'work_order_id',
        'quality_inspection_id',
        'batch_number',
        'product_name',
        'item_code',
        'stage',
        'quantity',
        'unit_cost',
        'scrap_cost',
        'rejection_date',
        'user_id',
        'reason',
        'severity',
        'rework_status',
        'rework_possible',
        'reported_by',
        'rework_assigned_to',
        'total_produced',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'float',
            'scrap_cost' => 'float',
            'rejection_date' => 'date',
            'quantity' => 'integer',
            'total_produced' => 'integer',
            'rework_possible' => 'boolean',
        ];
    }
}
