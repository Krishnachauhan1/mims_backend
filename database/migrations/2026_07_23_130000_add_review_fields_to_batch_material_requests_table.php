<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_material_requests', function (Blueprint $table) {
            $table->text('review_notes')->nullable()->after('status');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_name')->nullable()->after('reviewed_by_user_id');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('batch_material_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['review_notes', 'reviewed_by_name', 'reviewed_at']);
        });
    }
};
