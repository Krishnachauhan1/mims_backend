<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardTaskMaterial extends Model
{
    protected $fillable = [
        'dashboard_task_id',
        'inventory_item_id',
        'quantity',
        'unit',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(DashboardTask::class, 'dashboard_task_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
