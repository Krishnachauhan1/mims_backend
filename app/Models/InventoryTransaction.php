<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'type',
        'material_name',
        'supplier',
        'destination',
        'quantity',
        'unit',
        'batch_number',
        'po_number',
        'pr_number',
        'items',
        'notes',
        'status',
        'receipt_phase',
        'user_id',
        'production_batch_id',
        'job_work_id',
        'work_order_id',
        'flow_source',
    ];

    protected $casts = [
        'items' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
