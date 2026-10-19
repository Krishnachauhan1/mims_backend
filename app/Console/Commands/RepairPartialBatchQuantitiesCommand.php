<?php

namespace App\Console\Commands;

use App\Models\ProductionBatch;
use Illuminate\Console\Command;

class RepairPartialBatchQuantitiesCommand extends Command
{
    protected $signature = 'production:repair-partial-quantities
                            {--dry-run : Show changes without saving}';

    protected $description = 'Fix split batch quantities using partial forward entries in activity_log';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        $children = ProductionBatch::query()
            ->whereNotNull('parent_batch_id')
            ->orderBy('id')
            ->get();

        foreach ($children as $child) {
            $splitQty = $this->parseReceivedPartialQty($child->activity_log);
            if ($splitQty === null || $splitQty < 1) {
                continue;
            }

            $oldChildQty = (int) $child->quantity;
            if ($oldChildQty === $splitQty) {
                continue;
            }

            $this->line(sprintf(
                'Child %s (#%d): quantity %d → %d',
                $child->batch_number,
                $child->id,
                $oldChildQty,
                $splitQty,
            ));

            if (! $dryRun) {
                $child->update(['quantity' => $splitQty]);
            }
            $fixed++;

            $parent = ProductionBatch::query()->find($child->parent_batch_id);
            if ($parent === null) {
                continue;
            }

            $sentQty = $this->parseSentPartialQty($parent->activity_log);
            if ($sentQty === null || $sentQty !== $splitQty) {
                continue;
            }

            $parentQty = (int) $parent->quantity;
            if ($oldChildQty !== $parentQty || $parentQty <= $sentQty) {
                continue;
            }

            $expectedParent = $parentQty - $sentQty;

            $this->line(sprintf(
                'Parent %s (#%d): quantity %d → %d',
                $parent->batch_number,
                $parent->id,
                $parentQty,
                $expectedParent,
            ));
            if (! $dryRun) {
                $parent->update(['quantity' => $expectedParent]);
            }
        }

        if ($fixed === 0) {
            $this->info('No split batch quantities needed repair.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "Would repair {$fixed} child batch(es). Re-run without --dry-run to apply."
            : "Repaired {$fixed} child batch(es).");

        return self::SUCCESS;
    }

    /**
     * @param  mixed  $activityLog
     */
    private function parseReceivedPartialQty(mixed $activityLog): ?int
    {
        return $this->parsePartialQtyFromLog($activityLog, '/Received\s+(\d+)\s+units\s+from\s+.+\(partial/i');
    }

    /**
     * @param  mixed  $activityLog
     */
    private function parseSentPartialQty(mixed $activityLog): ?int
    {
        return $this->parsePartialQtyFromLog($activityLog, '/Sent\s+(\d+)\s+units\s+to\s+.+\(partial/i');
    }

    /**
     * @param  mixed  $activityLog
     */
    private function parsePartialQtyFromLog(mixed $activityLog, string $pattern): ?int
    {
        if (! is_array($activityLog)) {
            return null;
        }

        foreach ($activityLog as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $action = (string) ($entry['action'] ?? '');
            if (preg_match($pattern, $action, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
