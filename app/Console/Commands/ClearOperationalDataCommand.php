<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearOperationalDataCommand extends Command
{
    protected $signature = 'db:clear-operational {--force : Skip confirmation prompt}';

    protected $description = 'Clear sales, inventory, PR, PO, production, daily production, check-in/out, and route card data (keeps users and master data)';

    /**
     * Child tables first, then parents. Users/vendors/clients/machinery/items/workflows are kept.
     *
     * @var list<string>
     */
    private array $tables = [
        'dashboard_task_materials',
        'daily_production_entries',
        'company_profiles',
        'sales_orders',
        'quality_inspections',
        'packing_entries',
        'rejections',
        'inventory_transactions',
        'batch_material_requests',
        'work_order_materials',
        'finished_goods',
        'job_works',
        'production_batches',
        'work_orders',
        'purchase_orders',
        'purchase_requisitions',
        'dashboard_tasks',
        'documents',
        'inventory_items',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Delete all sales, inventory, PR, PO, production, daily production, check-in/out, and route card data?',
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

        Schema::enableForeignKeyConstraints();

        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'total_orders')) {
            DB::table('clients')->update(['total_orders' => 0]);
            $this->line('  reset clients.total_orders');
        }

        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'total_orders')) {
            DB::table('vendors')->update(['total_orders' => 0]);
            $this->line('  reset vendors.total_orders');
        }

        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'last_order_date')) {
            DB::table('clients')->update(['last_order_date' => null]);
            $this->line('  reset clients.last_order_date');
        }

        $this->newLine();
        $this->info('Done. Kept users, vendors, clients, machinery, items, workflows, and processes.');

        return self::SUCCESS;
    }
}
