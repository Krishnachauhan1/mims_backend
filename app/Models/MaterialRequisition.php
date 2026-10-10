<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequisition extends Model
{
    protected $fillable = [
        'destination_type',
        'machinery_id',
        'machine_name',
        'machine_code',
        'source',
        'inventory_item_id',
        'item_name',
        'quantity',
        'unit',
        'status',
        'notes',
        'recipient_user_id',
        'recipient_name',
        'requested_by_user_id',
        'requested_by_name',
        'issued_by_user_id',
        'issued_by_name',
        'issued_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'issued_at' => 'datetime',
        ];
    }

    public function machinery(): BelongsTo
    {
        return $this->belongsTo(Machinery::class, 'machinery_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
