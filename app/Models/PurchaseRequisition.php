<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    protected $fillable = [
        'pr_number',
        'vendor_name',
        'contact_person',
        'contact_mobile',
        'items',
        'item_name',
        'quantity',
        'unit',
        'department',
        'requested_by',
        'required_date',
        'priority',
        'status',
        'review_notes',
        'submitted_by_user_id',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'required_date' => 'date',
            'items' => 'array',
        ];
    }

    public function scopeApprovedWithoutPo($query)
    {
        return $query
            ->where('status', 'approved')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('purchase_orders')
                    ->whereColumn('purchase_orders.pr_number', 'purchase_requisitions.pr_number')
                    ->whereNotNull('purchase_orders.pr_number');
            });
    }
}
