<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses')) {
            return;
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->string('type', 20)->default('expense');
            $table->string('category_id', 50);
            $table->string('category_name')->nullable();
            $table->date('expense_date');
            $table->string('payment_method', 50)->default('cash');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['expense_date', 'type']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
