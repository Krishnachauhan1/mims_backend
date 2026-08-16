<?php

namespace App\Services;

use App\Models\ProductionBatch;
use Illuminate\Support\Facades\Log;

class ProductionCompletionReminderService
{
    public function sendDueReminders(): int
    {
        $sent = 0;

        ProductionBatch::query()
            ->whereNotNull('awaiting_final_completion_at')
            ->where('status', '!=', 'completed')
            ->orderBy('id')
            ->chunkById(50, function ($batches) use (&$sent): void {
                foreach ($batches as $batch) {
                    if (! $this->isReminderDue($batch)) {
                        continue;
                    }

                    try {
                        AppNotificationService::productionCompletionReminder($batch);
                        $batch->update(['last_completion_reminder_at' => now()]);
                        $sent++;
                    } catch (\Throwable $exception) {
                        Log::warning('Production completion reminder failed', [
                            'batch_id' => $batch->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $sent;
    }

    private function isReminderDue(ProductionBatch $batch): bool
    {
        $awaitingSince = $batch->awaiting_final_completion_at;
        if ($awaitingSince === null) {
            return false;
        }

        if ($awaitingSince->gt(now()->subMinutes(5))) {
            return false;
        }

        $lastReminder = $batch->last_completion_reminder_at;
        if ($lastReminder === null) {
            return true;
        }

        return $lastReminder->lte(now()->subMinutes(5));
    }
}
