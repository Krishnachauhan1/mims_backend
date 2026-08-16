<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClearDatabaseExceptAdminCommand extends Command
{
    protected $signature = 'db:clear-except-admin {--force : Skip confirmation prompt}';

    protected $description = 'Delete all application data and keep only admin user accounts';

    /** @var list<string> */
    private array $tables = [
        'sales_orders',
        'audit_logs',
        'documents',
        'machinery_maintenance_logs',
        'notifications',
        'dashboard_tasks',
        'rejections',
        'finished_goods',
        'work_orders',
        'job_works',
        'production_batches',
        'workflows',
        'processes',
        'items',
        'gauge_calibrations',
        'gauges',
        'machinery',
        'inventory_transactions',
        'inventory_items',
        'purchase_orders',
        'purchase_requisitions',
        'vendors',
        'clients',
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
        if (! $this->option('force') && ! $this->confirm('Delete ALL data except admin users?', false)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $this->info('Clearing uploaded document files...');
        Document::query()->each(function (Document $document): void {
            if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
                Storage::disk('local')->delete($document->file_path);
            }
        });

        Schema::disableForeignKeyConstraints();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->truncate();
            $this->line("  cleared {$table}");
        }

        $removedUsers = User::query()->where('role', '!=', 'admin')->delete();

        Schema::enableForeignKeyConstraints();

        $adminCount = User::query()->where('role', 'admin')->count();

        $this->newLine();
        $this->info("Done. Kept {$adminCount} admin user(s), removed {$removedUsers} non-admin user(s).");

        return self::SUCCESS;
    }
}
