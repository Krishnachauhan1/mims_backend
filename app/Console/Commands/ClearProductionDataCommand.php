<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearProductionDataCommand extends Command
{
    protected $signature = 'db:clear-production {--force : Skip confirmation prompt}';

    protected $description = 'Delete production, inventory, check-in/check-out, PR/PO, sales orders, notifications, and logs';

    /**
     * Child tables first, then parents. Keeps users, vendors, clients, workflows, machinery, items.
     *
     * @var list<string>
     */
    private array $tables = [
        'dashboard_task_materials',
        'batch_material_requests',
        'work_order_materials',
        'quality_inspections',
        'inventory_transactions',
        'rejections',
        'finished_goods',
        'job_works',
        'production_batches',
        'work_orders',
        'inventory_items',
        'purchase_orders',
        'purchase_requisitions',
        'sales_orders',
        'machinery_maintenance_logs',
        'audit_logs',
        'notifications',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Delete all production, inventory, check-in/check-out, PR, PO, sales order, notification, and audit log data?',
            false,
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->line("  skipped {$table} (not found)");

                continue;
            }

            DB::table($table)->truncate();
            $this->line("  cleared {$table}");
        }

        $this->resetOrderCounters();

        Schema::enableForeignKeyConstraints();

        $this->newLine();
        $this->info('Done. Kept users, clients, vendors, workflows, machinery, and item master catalog.');

        return self::SUCCESS;
    }

    private function resetOrderCounters(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'total_orders')) {
            DB::table('clients')->update(['total_orders' => 0]);
            $this->line('  reset clients.total_orders');
        }

        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'total_orders')) {
            DB::table('vendors')->update(['total_orders' => 0]);
            $this->line('  reset vendors.total_orders');
        }
    }
}
