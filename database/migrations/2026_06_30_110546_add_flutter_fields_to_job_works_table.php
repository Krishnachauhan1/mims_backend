<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_works', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('vendor_name');
            $table->integer('quantity')->nullable()->after('product_name');
            $table->decimal('rate', 12, 2)->nullable()->after('unit');
            $table->decimal('total_amount', 12, 2)->nullable()->after('rate');
            $table->string('client_name')->nullable()->after('total_amount');
            $table->date('due_date')->nullable()->after('client_name');
        });
    }

    public function down(): void
    {
        Schema::table('job_works', function (Blueprint $table) {
            $table->dropColumn([
                'product_name',
                'quantity',
                'rate',
                'total_amount',
                'client_name',
                'due_date',
            ]);
        });
    }
};
