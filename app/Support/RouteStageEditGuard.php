<?php

namespace App\Support;

use App\Models\ProductionBatch;

final class RouteStageEditGuard
{
    /**
     * Stages before the current step, and any step with closed history, cannot be edited.
     *
     * @param  array<int, array<string, mixed>>  $routeStages
     */
    public static function lockedStageCount(ProductionBatch $batch, array $routeStages): int
    {
        $count = count($routeStages);
        if ($count === 0) {
            return 0;
        }

        $stageOrder = max(1, (int) ($batch->stage_order ?? 1));
        $locked = $stageOrder > 1 ? $stageOrder - 1 : 0;

        $history = $batch->stage_history ?? null;
        if (! is_array($history)) {
            return min($locked, $count);
        }

        foreach ($routeStages as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $name = strtolower(trim((string) ($stage['name'] ?? $stage['machine_name'] ?? '')));
            if ($name === '') {
                continue;
            }
            foreach ($history as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $completed = $entry['completed'] ?? $entry['completed_at'] ?? null;
                if ($completed === null || trim((string) $completed) === '') {
                    continue;
                }
                $stageName = strtolower(trim((string) ($entry['stage'] ?? '')));
                if ($stageName === $name) {
                    $locked = max($locked, $index + 1);
                    break;
                }
            }
        }

        return min($locked, $count);
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentStages
     * @param  array<int, array<string, mixed>>  $newStages
     */
    public static function validate(
        ProductionBatch $batch,
        array $currentStages,
        array $newStages,
    ): ?string {
        $currentStages = array_values(array_filter($currentStages, 'is_array'));
        $newStages = array_values(array_filter($newStages, 'is_array'));

        $locked = self::lockedStageCount($batch, $currentStages);
        if ($locked <= 0) {
            return null;
        }

        if (count($newStages) < $locked) {
            return 'Completed route steps cannot be removed. Add a new stage at the end to outsource again.';
        }

        $compareCount = min($locked, count($currentStages));

        for ($i = 0; $i < $compareCount; $i++) {
            if (
                self::stageSignature($currentStages[$i])
                !== self::stageSignature($newStages[$i])
            ) {
                return 'Completed route steps cannot be edited. Add a new Job Work stage at the end to send for outsource again.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private static function stageSignature(array $stage): string
    {
        $name = strtolower(trim((string) ($stage['name'] ?? $stage['machine_name'] ?? '')));
        $machineId = (int) ($stage['machine_id'] ?? 0);
        $jobWork = filter_var($stage['is_job_work'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (int) ($stage['vendor_id'] ?? 0) > 0;
        $vendorId = (int) ($stage['vendor_id'] ?? 0);
        $rate = round((float) ($stage['rate'] ?? 0), 4);

        return implode('|', [
            $name,
            (string) $machineId,
            $jobWork ? 'jw' : 'in',
            (string) $vendorId,
            (string) $rate,
        ]);
    }
}