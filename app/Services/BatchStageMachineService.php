<?php

namespace App\Services;

use App\Models\Machinery;
use App\Models\ProductionBatch;
use App\Models\WorkOrder;

class BatchStageMachineService
{
    /**
     * @param  array<int, WorkOrder>  $workOrdersById
     * @return array<string, mixed>|null
     */
    public function currentStageForBatch(
        ProductionBatch $batch,
        array $workOrdersById = [],
    ): ?array {
        $stages = $this->resolveStagesForBatch($batch, $workOrdersById);
        if ($stages === []) {
            return null;
        }

        $stageOrder = max(1, (int) ($batch->stage_order ?? 1));
        $index = min($stageOrder - 1, count($stages) - 1);

        return $stages[$index] ?? null;
    }

    public function machineIdForStage(array $stage): ?int
    {
        if (! empty($stage['machine_id'])) {
            return (int) $stage['machine_id'];
        }

        $stageCode = strtolower(trim((string) ($stage['machine_code'] ?? '')));
        $stageMachineName = strtolower(trim((string) ($stage['machine_name'] ?? '')));
        $stageName = strtolower(trim((string) ($stage['name'] ?? '')));

        foreach (Machinery::query()->get() as $machine) {
            if ($this->stageMatchesMachinery($stage, (int) $machine->id)) {
                return (int) $machine->id;
            }
        }

        return null;
    }

    public function stageMatchesMachinery(array $stage, int $machineryId): bool
    {
        if (isset($stage['machine_id']) && (int) $stage['machine_id'] === $machineryId) {
            return true;
        }

        $machinery = Machinery::query()->find($machineryId);
        if ($machinery === null) {
            return false;
        }

        $machineCode = strtolower(trim((string) ($machinery->machine_code ?? '')));
        $machineName = strtolower(trim((string) ($machinery->name ?? '')));
        $machineDefaultStage = strtolower(trim((string) ($machinery->default_stage ?? '')));
        $stageCode = strtolower(trim((string) ($stage['machine_code'] ?? '')));
        $stageMachineName = strtolower(trim((string) ($stage['machine_name'] ?? '')));
        $stageName = strtolower(trim((string) ($stage['name'] ?? '')));

        if ($machineCode !== '' && $stageCode !== '' && $machineCode === $stageCode) {
            return true;
        }

        if ($machineName !== '' && $stageMachineName !== '' && $machineName === $stageMachineName) {
            return true;
        }

        if ($stageName !== '') {
            if ($machineDefaultStage !== '' && $stageName === $machineDefaultStage) {
                return true;
            }

            if ($machineName !== '' && str_contains($machineName, $stageName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, WorkOrder>  $workOrdersById
     * @return array<int, array<string, mixed>>
     */
    public function resolveStagesForBatch(
        ProductionBatch $batch,
        array $workOrdersById = [],
    ): array {
        $stages = [];

        if ($batch->work_order_id) {
            $orderId = (int) $batch->work_order_id;
            $order = $workOrdersById[$orderId] ?? WorkOrder::query()->find($orderId);
            $orderStages = $order?->stages ?? [];
            if (is_array($orderStages) && $orderStages !== []) {
                $stages = $orderStages;
            }
        }

        if ($stages === []) {
            $workflowStages = $batch->workflow?->stages ?? [];
            if (is_array($workflowStages) && $workflowStages !== []) {
                $stages = $workflowStages;
            }
        }

        if ($stages === []) {
            return [];
        }

        $stages = $this->normalizeStages($stages);
        if ($stages === []) {
            return [];
        }

        return $this->enrichStagesWithMachinery($this->sortStages($stages));
    }

    /**
     * @param  mixed  $stages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStages(mixed $stages): array
    {
        if (! is_array($stages)) {
            return [];
        }

        $normalized = [];
        foreach ($stages as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $normalized[] = $stage;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function enrichStagesWithMachinery(array $stages): array
    {
        $machinery = Machinery::query()->get();

        return array_map(function (array $stage) use ($machinery) {
            if (! empty($stage['machine_id'])) {
                return $stage;
            }

            $stageCode = strtolower(trim((string) ($stage['machine_code'] ?? '')));
            $stageMachineName = strtolower(trim((string) ($stage['machine_name'] ?? '')));
            $stageName = strtolower(trim((string) ($stage['name'] ?? '')));

            foreach ($machinery as $machine) {
                $machineCode = strtolower(trim((string) ($machine->machine_code ?? '')));
                $machineName = strtolower(trim((string) ($machine->name ?? '')));
                $defaultStage = strtolower(trim((string) ($machine->default_stage ?? '')));

                if ($stageCode !== '' && $machineCode !== '' && $stageCode === $machineCode) {
                    return $this->attachMachineToStage($stage, $machine);
                }

                if ($stageMachineName !== '' && $machineName !== '' && $stageMachineName === $machineName) {
                    return $this->attachMachineToStage($stage, $machine);
                }

                if ($stageName !== '') {
                    if ($defaultStage !== '' && $stageName === $defaultStage) {
                        return $this->attachMachineToStage($stage, $machine);
                    }

                    if ($machineName !== '' && str_contains($machineName, $stageName)) {
                        return $this->attachMachineToStage($stage, $machine);
                    }
                }
            }

            return $stage;
        }, $stages);
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return array<string, mixed>
     */
    private function attachMachineToStage(array $stage, Machinery $machine): array
    {
        $stage['machine_id'] = $machine->id;
        $stage['machine_name'] = $machine->name;
        $stage['machine_code'] = $machine->machine_code;

        return $stage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function sortStages(array $stages): array
    {
        $stages = $this->normalizeStages($stages);

        usort(
            $stages,
            fn (array $left, array $right) => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)),
        );

        return array_values($stages);
    }
}
