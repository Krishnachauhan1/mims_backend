<?php

namespace App\Console\Commands;

use App\Services\ProductionCompletionReminderService;
use Illuminate\Console\Command;

class RemindPendingProductionCompletionCommand extends Command
{
    protected $signature = 'production:remind-pending-completion';

    protected $description = 'Notify all users to mark production batches complete when last stage is finished';

    public function handle(): int
    {
        $sent = app(ProductionCompletionReminderService::class)->sendDueReminders();

        $this->info("Sent {$sent} completion reminder(s).");

        return self::SUCCESS;
    }
}
