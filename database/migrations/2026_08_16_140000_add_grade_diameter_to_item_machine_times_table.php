<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_machine_times')) {
            Schema::create('item_machine_times', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->foreignId('machine_id')->constrained('machinery')->cascadeOnDelete();
                $table->string('grade', 50)->default('');
                $table->decimal('diameter_mm', 8, 2)->nullable();
                $table->decimal('per_piece_time', 10, 2)->nullable();
                $table->decimal('average_time', 10, 2)->nullable();
                $table->timestamps();

                $table->unique(
                    ['machine_id', 'grade', 'diameter_mm'],
                    'item_machine_times_machine_grade_dia_unique',
                );
            });

            return;
        }

        if (Schema::hasColumn('item_machine_times', 'grade')) {
            return;
        }

        Schema::table('item_machine_times', function (Blueprint $table) {
            $table->string('grade', 50)->default('')->after('machine_id');
            $table->decimal('diameter_mm', 8, 2)->nullable()->after('grade');
        });

        Schema::table('item_machine_times', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropUnique(['item_id', 'machine_id']);
        });

        Schema::table('item_machine_times', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->change();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
            $table->unique(
                ['machine_id', 'grade', 'diameter_mm'],
                'item_machine_times_machine_grade_dia_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('item_machine_times')) {
            return;
        }

        if (! Schema::hasColumn('item_machine_times', 'grade')) {
            return;
        }

        Schema::table('item_machine_times', function (Blueprint $table) {
            $table->dropUnique('item_machine_times_machine_grade_dia_unique');
            $table->dropForeign(['item_id']);
        });

        Schema::table('item_machine_times', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable(false)->change();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->unique(['item_id', 'machine_id']);
            $table->dropColumn(['grade', 'diameter_mm']);
        });
    }
};
