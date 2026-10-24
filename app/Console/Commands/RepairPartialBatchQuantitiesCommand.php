<?php

namespace App\Console\Commands;

use App\Models\ProductionBatch;
use Illuminate\Console\Command;

class RepairPartialBatchQuantitiesCommand extends Command
{
    protected $signature = 'production:repair-partial-quantities
                            {--dry-run : Show changes without saving}
                            {--batch= : Only repair splits related to this batch number (e.g. WO-148 or WO-148-P1)}';

    protected $description = 'Fix split batch quantities using partial forward entries in activity_log';

    /** @var list<string> */
    private array $receivedPartialPatterns = [
        '/Received\s+(\d+)\s+units?\s+from\s+.+\(partial\s+forward\)/i',
        '/Received\s+(\d+)\s+units?\s+from\s+.+\(partial/i',
        '/Received\s+(\d+)\s+.*partial/i',
    ];

    /** @var list<string> */
    private array $sentPartialPatterns = [
        '/Sent\s+(\d+)\s+units?\s+to\s+.+\(partial\)/i',
        '/Sent\s+(\d+)\s+units?\s+to\s+.+\(partial/i',
        '/Sent\s+(\d+)\s+.*partial/i',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchFilter = trim((string) $this->option('batch'));
        $fixed = 0;

        $childrenQuery = ProductionBatch::query()
            ->whereNotNull('parent_batch_id')
            ->orderBy('id');

        if ($batchFilter !== '') {
            $root = preg_replace('/-P\d+$/i', '', $batchFilter) ?: $batchFilter;
            $childrenQuery->where(function ($q) use ($batchFilter, $root) {
                $q->where('batch_number', 'like', $root.'-P%')
                    ->orWhere('batch_number', $batchFilter);
            });
        }

        $children = $childrenQuery->get();

        foreach ($children as $child) {
            $parent = ProductionBatch::query()->find($child->parent_batch_id);
            if ($parent === null) {
                continue;
            }

            $splitQty = $this->parseReceivedPartialQty($child->activity_log)
                ?? $this->inferSplitQtyFromParent($parent, $child);

            if ($splitQty === null || $splitQty < 1) {
                continue;
            }

            $oldChildQty = (int) $child->quantity;
            $parentQty = (int) $parent->quantity;
            $sentQty = $this->parseSentPartialQty($parent->activity_log) ?? $splitQty;
            $childNeedsFix = $oldChildQty !== $splitQty;

            // Parent still holds full qty while child was split (209 / 209 instead of 62 / 147).
            $parentNeedsFix = $sentQty === $splitQty
                && $oldChildQty === $parentQty
                && $parentQty > $sentQty;

            if (! $childNeedsFix && ! $parentNeedsFix) {
                continue;
            }

            if ($childNeedsFix) {
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
            }

            if ($parentNeedsFix) {
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

    private function inferSplitQtyFromParent(
        ProductionBatch $parent,
        ProductionBatch $child,
    ): ?int {
        $sentQty = $this->parseSentPartialQty($parent->activity_log);
        if ($sentQty === null || $sentQty < 1) {
            return null;
        }

        $parentQty = (int) $parent->quantity;
        $childQty = (int) $child->quantity;

        // Classic bug: parent and split child both still show full route qty (e.g. 209 / 209).
        if ($childQty === $parentQty && $parentQty > $sentQty) {
            return $sentQty;
        }

        if ($childQty > $sentQty && $parentQty + $sentQty === $childQty + $parentQty) {
            return $sentQty;
        }

        return null;
    }

    /**
     * @param  mixed  $activityLog
     */
    private function parseReceivedPartialQty(mixed $activityLog): ?int
    {
        return $this->parsePartialQtyFromLog($activityLog, $this->receivedPartialPatterns);
    }

    /**
     * @param  mixed  $activityLog
     */
    private function parseSentPartialQty(mixed $activityLog): ?int
    {
        return $this->parsePartialQtyFromLog($activityLog, $this->sentPartialPatterns);
    }

    /**
     * @param  mixed  $activityLog
     * @param  list<string>  $patterns
     */
    private function parsePartialQtyFromLog(mixed $activityLog, array $patterns): ?int
    {
        if (! is_array($activityLog)) {
            return null;
        }

        foreach ($activityLog as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $action = (string) ($entry['action'] ?? '');
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $action, $matches) === 1) {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }
}
