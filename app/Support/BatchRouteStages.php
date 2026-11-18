<?php

namespace App\Support;

use App\Models\ProductionBatch;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Schema;

final class BatchRouteStages
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function resolveForBatch(ProductionBatch $batch): array
    {
        $custom = $batch->route_stages ?? null;
        if (is_array($custom) && $custom !== []) {
            $custom = self::sortStages($custom);
            $orderStages = self::workOrderStages($batch);
            if ($orderStages !== [] && count($orderStages) > count($custom)) {
                return self::mergeRouteTail($custom, $orderStages);
            }

            return $custom;
        }

        $orderStages = self::workOrderStages($batch);
        if ($orderStages !== []) {
            return $orderStages;
        }

        $workflowStages = $batch->workflow?->stages ?? [];
        if (is_array($workflowStages) && $workflowStages !== []) {
            return self::sortStages($workflowStages);
        }

        return [];
    }

    public static function usesCustomRoute(ProductionBatch $batch): bool
    {
        if (! Schema::hasColumn('production_batches', 'route_stages')) {
            return false;
        }

        $custom = $batch->route_stages ?? null;

        return is_array($custom) && $custom !== [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    public static function sortStages(array $stages): array
    {
        $stages = array_values(array_filter($stages, 'is_array'));
        usort(
            $stages,
            fn (array $left, array $right) => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)),
        );

        return array_values($stages);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function totalStageCount(ProductionBatch $batch): int
    {
        $stages = self::resolveForBatch($batch);

        return max(count($stages), (int) ($batch->total_stages ?? 0), 1);
    }

    /**
     * When a line-level route snapshot omits trailing steps, keep the work-order tail.
     *
     * @param  array<int, array<string, mixed>>  $primary
     * @param  array<int, array<string, mixed>>  $donor
     * @return array<int, array<string, mixed>>
     */
    public static function mergeRouteTail(array $primary, array $donor): array
    {
        $maxOrder = 0;
        foreach ($primary as $stage) {
            $maxOrder = max($maxOrder, (int) ($stage['order'] ?? 0));
        }

        $merged = $primary;
        foreach ($donor as $stage) {
            if ((int) ($stage['order'] ?? 0) > $maxOrder) {
                $merged[] = $stage;
            }
        }

        return self::sortStages($merged);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function workOrderStages(ProductionBatch $batch): array
    {
        if (! $batch->work_order_id) {
            return [];
        }

        $order = WorkOrder::query()->find($batch->work_order_id);
        $orderStages = $order?->stages ?? [];
        if (! is_array($orderStages) || $orderStages === []) {
            return [];
        }

        return self::sortStages($orderStages);
    }
}
