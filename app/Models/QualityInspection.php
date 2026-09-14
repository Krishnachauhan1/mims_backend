<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QualityInspection extends Model
{
    protected $fillable = [
        'production_batch_id',
        'work_order_id',
        'inventory_transaction_id',
        'batch_number',
        'product_name',
        'item_name',
        'quantity',
        'passed_qty',
        'rejected_qty',
        'unit',
        'status',
        'source_type',
        'workflow_name',
        'pr_number',
        'po_number',
        'mrn_number',
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

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    public function rejections(): HasMany
    {
        return $this->hasMany(Rejection::class);
    }

    public function packingEntry(): HasOne
    {
        return $this->hasOne(PackingEntry::class);
    }

    public function remainingQty(): int
    {
        return max(0, (int) $this->quantity - (int) $this->rejected_qty);
    }
}
