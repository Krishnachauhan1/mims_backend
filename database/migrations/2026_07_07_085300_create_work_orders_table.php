<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->nullable()->index();
            $table->string('workflow_name')->nullable();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('vendor_name')->nullable();
            $table->unsignedBigInteger('item_id')->nullable()->index();
            $table->string('product')->nullable();
            $table->string('item_code')->nullable();
            $table->string('unit')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('pr_number')->nullable();
            $table->json('stages')->nullable();
            $table->boolean('is_job_work')->default(false);
            $table->string('supervisor_status')->default('pending');
            $table->string('flow_step')->default('workflow');
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
