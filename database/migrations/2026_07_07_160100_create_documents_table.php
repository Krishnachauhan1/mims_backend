<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->string('entity_id');
            $table->string('label')->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['module', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
