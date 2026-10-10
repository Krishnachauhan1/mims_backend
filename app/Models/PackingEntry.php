<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingEntry extends Model
{
    protected $fillable = [
        'quality_inspection_id',
        'production_batch_id',
        'work_order_id',
        'batch_number',
        'product_name',
        'item_name',
        'item_code',
        'client_name',
        'quantity',
        'packed_qty',
        'rejected_qty',
        'hold_qty',
        'unit',
        'status',
        'inspected_by',
        'inspected_at',
        'notes',
        'hold_reason',
        'reject_reason',
        'packed_by',
        'packed_at',
        'action_by',
        'action_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'packed_qty' => 'integer',
            'rejected_qty' => 'integer',
            'hold_qty' => 'integer',
            'inspected_at' => 'datetime',
            'packed_at' => 'datetime',
            'action_at' => 'datetime',
        ];
    }

    public function qualityInspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class);
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
