<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityInspection extends Model
{
    protected $fillable = [
        'production_batch_id',
        'work_order_id',
        'batch_number',
        'product_name',
        'quantity',
        'passed_qty',
        'rejected_qty',
        'unit',
        'status',
        'workflow_name',
        'pr_number',
        'inspected_by',
        'inspected_at',
        'failure_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'passed_qty' => 'integer',
            'rejected_qty' => 'integer',
            'inspected_at' => 'datetime',
        ];
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function rejections(): HasMany
    {
        return $this->hasMany(Rejection::class);
    }

    public function remainingQty(): int
    {
        return max(0, (int) $this->quantity - (int) $this->rejected_qty);
    }
}
