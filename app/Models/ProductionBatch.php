<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionBatch extends Model
{
    protected $fillable = [
        'batch_number',
        'product_name',
        'workflow_id',
        'current_stage',
        'current_stage_index',
        'stage_order',
        'total_stages',
        'quantity',
        'status',
        'priority',
        'assigned_to',
        'start_date',
        'expected_completion',
        'started_at',
        'stage_started_at',
        'completed_at',
        'awaiting_final_completion_at',
        'last_completion_reminder_at',
        'hold_reason',
        'stage_history',
        'activity_log',
        'work_order_id',
        'parent_batch_id',
        'checkin_id',
        'pr_number',
        'supplier',
        'vendor_name',
        'client_name',
        'unit',
        'source',
        'performed_by',
        'user_name',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'expected_completion' => 'date',
            'started_at' => 'datetime',
            'stage_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'awaiting_final_completion_at' => 'datetime',
            'last_completion_reminder_at' => 'datetime',
            'stage_history' => 'array',
            'activity_log' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function parentBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_batch_id');
    }

    public function splitBatches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_batch_id');
    }

    public function materialRequests(): HasMany
    {
        return $this->hasMany(BatchMaterialRequest::class);
    }
}
