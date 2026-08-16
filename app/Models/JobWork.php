<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobWork extends Model
{
    protected $fillable = [
        'work_order_id',
        'batch_id',
        'job_number',
        'vendor_name',
        'vendor_id',
        'product_name',
        'process_name',
        'quantity',
        'material_sent',
        'quantity_sent',
        'unit',
        'rate',
        'total_amount',
        'client_name',
        'client_id',
        'due_date',
        'sent_date',
        'expected_return_date',
        'actual_return_date',
        'status',
        'from_workflow',
        'workflow_stage',
        'workflow_name',
        'pr_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'total_amount' => 'float',
            'from_workflow' => 'boolean',
            'due_date' => 'date',
            'sent_date' => 'date',
            'expected_return_date' => 'date',
            'actual_return_date' => 'date',
        ];
    }
}
