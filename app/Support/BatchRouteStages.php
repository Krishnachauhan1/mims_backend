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
            return self::sortStages($custom);
        }

        if ($batch->work_order_id) {
            $order = WorkOrder::query()->find($batch->work_order_id);
            $orderStages = $order?->stages ?? [];
            if (is_array($orderStages) && $orderStages !== []) {
                return self::sortStages($orderStages);
            }
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
}
