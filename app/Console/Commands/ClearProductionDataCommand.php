<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClearProductionDataCommand extends Command
{
    protected $signature = 'db:clear-production {--force : Skip confirmation prompt}';

    protected $description = 'Clear PR, PO, route cards, check-in/out, production, finished goods, daily production, quality, packing queue, rejection, job work, inventory stock, gauges, notifications, and audit logs (keeps users, vendors, clients, item catalog, workflows, machinery, sales)';

    /**
     * Child tables first, then parents.
     *
     * @var list<string>
     */
    private array $tables = [
        'packing_entries',
        'dashboard_task_materials',
        'batch_material_requests',
        'daily_production_entries',
        'work_order_materials',
        'rejections',
        'quality_inspections',
        'inventory_transactions',
        'finished_goods',
        'job_works',
        'production_batches',
        'work_orders',
        'purchase_orders',
        'purchase_requisitions',
        'inventory_items',
        'gauge_calibrations',
        'gauges',
        'notifications',
        'audit_logs',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Delete PR, PO, route cards, check-in/out, production, finished goods, daily production, quality, rejection, job work, inventory stock, gauges, notifications, and audit logs?',
            false,
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $this->clearGaugeDocuments();

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
        $this->info('Done. Kept users, vendors, clients, item catalog (items table), workflows, machinery, and sales orders.');

        return self::SUCCESS;
    }

    private function clearGaugeDocuments(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        Document::query()
            ->where('module', 'gauge')
            ->each(function (Document $document): void {
                if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
                    Storage::disk('local')->delete($document->file_path);
                }
            });

        $deleted = Document::query()->where('module', 'gauge')->delete();
        if ($deleted > 0) {
            $this->line("  cleared {$deleted} gauge document(s)");
        }
    }

    private function resetOrderCounters(): void
    {
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
    }
}
