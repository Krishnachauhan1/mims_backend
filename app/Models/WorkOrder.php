<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $fillable = [
        'workflow_id',
        'workflow_name',
        'client_id',
        'client_name',
        'vendor_id',
        'vendor_name',
        'item_id',
        'product',
        'item_code',
        'unit',
        'quantity',
        'pr_number',
        'route_card_no',
        'remarks',
        'stages',
        'is_job_work',
        'supervisor_status',
        'priority',
        'flow_step',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'is_job_work' => 'boolean',
            'quantity' => 'integer',
            'client_id' => 'integer',
            'vendor_id' => 'integer',
            'item_id' => 'integer',
            'workflow_id' => 'integer',
        ];
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }
}
