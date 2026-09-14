<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteQualityInspectionsTable();

            return;
        }

        Schema::table('quality_inspections', function (Blueprint $table) {
            $table->dropForeign(['production_batch_id']);
        });

        DB::statement('ALTER TABLE quality_inspections MODIFY production_batch_id BIGINT UNSIGNED NULL');

        Schema::table('quality_inspections', function (Blueprint $table) {
            $table->foreign('production_batch_id')
                ->references('id')
                ->on('production_batches')
                ->nullOnDelete();

            $table->foreignId('inventory_transaction_id')
                ->nullable()
                ->after('work_order_id')
                ->constrained('inventory_transactions')
                ->nullOnDelete();

            $table->string('source_type')->default('production')->after('status');
            $table->string('item_name')->nullable()->after('product_name');
            $table->string('po_number')->nullable()->after('pr_number');
            $table->string('mrn_number')->nullable()->after('po_number');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('quality_inspections', function (Blueprint $table) {
            $table->dropForeign(['production_batch_id']);
            $table->dropConstrainedForeignId('inventory_transaction_id');
            $table->dropColumn(['source_type', 'item_name', 'po_number', 'mrn_number']);
        });

        DB::statement('ALTER TABLE quality_inspections MODIFY production_batch_id BIGINT UNSIGNED NOT NULL');

        Schema::table('quality_inspections', function (Blueprint $table) {
            $table->foreign('production_batch_id')
                ->references('id')
                ->on('production_batches')
                ->cascadeOnDelete();
        });
    }

    private function rebuildSqliteQualityInspectionsTable(): void
    {
        if (Schema::hasColumn('quality_inspections', 'source_type')) {
            if (Schema::hasTable('quality_inspections_old')) {
                Schema::drop('quality_inspections_old');
            }

            return;
        }

        if (Schema::hasTable('quality_inspections_old')) {
            Schema::drop('quality_inspections');
        }

        Schema::rename('quality_inspections', 'quality_inspections_old');

        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->string('batch_number');
            $table->string('product_name');
            $table->string('item_name')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('passed_qty')->default(0);
            $table->unsignedInteger('rejected_qty')->default(0);
            $table->string('unit')->default('pcs');
            $table->string('status')->default('pending');
            $table->string('source_type')->default('production');
            $table->string('workflow_name')->nullable();
            $table->string('pr_number')->nullable();
            $table->string('po_number')->nullable();
            $table->string('mrn_number')->nullable();
            $table->string('inspected_by')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('batch_number');
        });

        DB::statement('
            INSERT INTO quality_inspections (
                id, production_batch_id, work_order_id, batch_number, product_name,
                quantity, passed_qty, rejected_qty, unit, status, workflow_name,
                pr_number, inspected_by, inspected_at, failure_reason, notes,
                created_at, updated_at, source_type
            )
            SELECT
                id, production_batch_id, work_order_id, batch_number, product_name,
                quantity, passed_qty, rejected_qty, unit, status, workflow_name,
                pr_number, inspected_by, inspected_at, failure_reason, notes,
                created_at, updated_at, \'production\'
            FROM quality_inspections_old
        ');

        Schema::drop('quality_inspections_old');
    }
};
