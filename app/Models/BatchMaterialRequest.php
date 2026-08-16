<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchMaterialRequest extends Model
{
    protected $fillable = [
        'production_batch_id',
        'inventory_item_id',
        'item_name',
        'quantity',
        'unit',
        'request_type',
        'status',
        'notes',
        'requested_by_user_id',
        'requested_by_name',
        'review_notes',
        'reviewed_by_user_id',
        'reviewed_by_name',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
