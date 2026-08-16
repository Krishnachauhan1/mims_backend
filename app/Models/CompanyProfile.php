<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    protected $fillable = [
        'company_name',
        'gst_number',
        'addresses',
        'contacts',
    ];

    protected function casts(): array
    {
        return [
            'addresses' => 'array',
            'contacts' => 'array',
        ];
    }
}
