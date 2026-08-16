<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'pr_number',
        'pr_id',
        'vendor_name',
        'contact_person',
        'contact_mobile',
        'status',
        'items',
        'total_amount',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_amount' => 'float',
        ];
    }
}
