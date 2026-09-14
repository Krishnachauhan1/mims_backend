<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClearExceptUsersVendorsClientsCommand extends Command
{
    protected $signature = 'db:clear-except-users-vendors-clients-items {--force : Skip confirmation prompt}';

    protected $description = 'Clear all data except users, vendors, clients, and items';

    /**
     * Child tables first, then parents. Keeps users, vendors, clients, items (+ item documents).
     *
     * @var list<string>
     */
    private array $tables = [
        'dashboard_task_materials',
        'dashboard_tasks',
        'batch_material_requests',
        'work_order_materials',
        'quality_inspections',
        'daily_production_entries',
        'packing_entries',
        'expenses',
        'sales_orders',
        'inventory_transactions',
        'rejections',
        'finished_goods',
        'job_works',
        'production_batches',
        'work_orders',
        'purchase_orders',
        'purchase_requisitions',
        'inventory_items',
        'machinery_maintenance_logs',
        'audit_logs',
        'notifications',
        'user_device_tokens',
        'gauge_calibrations',
        'gauges',
        'machinery',
        'workflows',
        'processes',
        'item_machine_times',
        'company_profiles',
        'personal_access_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Delete ALL data except users, vendors, clients, and items?',
            false,
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        if (Schema::hasTable('documents')) {
            $this->info('Clearing non-item uploaded files...');
            Document::query()
                ->where('module', '!=', 'item')
                ->each(function (Document $document): void {
                    if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
                        Storage::disk('local')->delete($document->file_path);
                    }
                });
            Document::query()->where('module', '!=', 'item')->delete();
            $this->line('  cleared non-item documents');
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

        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'last_order_date')) {
            DB::table('clients')->update(['last_order_date' => null]);
            $this->line('  reset clients.last_order_date');
        }

        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'total_orders')) {
            DB::table('vendors')->update(['total_orders' => 0]);
            $this->line('  reset vendors.total_orders');
        }

        $users = Schema::hasTable('users') ? DB::table('users')->count() : 0;
        $vendors = Schema::hasTable('vendors') ? DB::table('vendors')->count() : 0;
        $clients = Schema::hasTable('clients') ? DB::table('clients')->count() : 0;
        $items = Schema::hasTable('items') ? DB::table('items')->count() : 0;

        $this->newLine();
        $this->info("Done. Kept {$users} user(s), {$vendors} vendor(s), {$clients} client(s), {$items} item(s).");

        return self::SUCCESS;
    }
}
