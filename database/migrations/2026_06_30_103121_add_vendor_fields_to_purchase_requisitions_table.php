<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->string('vendor_name')->nullable()->after('pr_number');
            $table->string('contact_person')->nullable()->after('vendor_name');
            $table->string('contact_mobile')->nullable()->after('contact_person');
            $table->json('items')->nullable()->after('contact_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropColumn(['vendor_name', 'contact_person', 'contact_mobile', 'items']);
        });
    }
};
