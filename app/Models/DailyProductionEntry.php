<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyProductionEntry extends Model
{
    protected $fillable = [
        'production_date',
        'machine_id',
        'operator_id',
        'shift',
        'start_time',
        'stop_time',
        'cycle_time',
        'actual_quantity',
        'reject_quantity',
        'work_order_id',
        'route_card_no',
        'item_id',
        'item_name',
        'process_id',
        'process_name',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'cycle_time' => 'float',
            'actual_quantity' => 'integer',
            'reject_quantity' => 'integer',
            'machine_id' => 'integer',
            'operator_id' => 'integer',
            'work_order_id' => 'integer',
            'item_id' => 'integer',
            'process_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machinery::class, 'machine_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
