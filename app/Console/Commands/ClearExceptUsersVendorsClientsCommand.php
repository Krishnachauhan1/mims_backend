<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClearExceptUsersVendorsClientsCommand extends Command
{
    protected $signature = 'db:clear-except-users-vendors-clients {--force : Skip confirmation prompt}';

    protected $description = 'Clear all data except users, vendors, and clients';

    /**
     * Child tables first, then parents. Keeps only users, vendors, clients.
     *
     * @var list<string>
     */
    private array $tables = [
        'dashboard_task_materials',
        'dashboard_tasks',
        'batch_material_requests',
        'work_order_materials',
        'quality_inspections',
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
        'documents',
        'machinery_maintenance_logs',
        'audit_logs',
        'notifications',
        'user_device_tokens',
        'gauge_calibrations',
        'gauges',
        'machinery',
        'workflows',
        'processes',
        'items',
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
            'Delete ALL data except users, vendors, and clients?',
            false,
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        if (Schema::hasTable('documents')) {
            $this->info('Clearing uploaded document files...');
            Document::query()->each(function (Document $document): void {
                if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
                    Storage::disk('local')->delete($document->file_path);
                }
            });
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

        $this->newLine();
        $this->info("Done. Kept {$users} user(s), {$vendors} vendor(s), {$clients} client(s).");

        return self::SUCCESS;
    }
}
