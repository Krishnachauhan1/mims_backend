<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemMachineTime;
use App\Models\ProductionBatch;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Models\Workflow;
use Carbon\Carbon;

class ItemMachineTimeService
{
    /**
     * @return array{grade: string, diameter_mm: float}|null
     */
    public function resolveMaterialSpecFromWorkOrder(?int $workOrderId): ?array
    {
        if ($workOrderId === null || $workOrderId <= 0) {
            return null;
        }

        $line = WorkOrderMaterial::query()
            ->where('work_order_id', $workOrderId)
            ->orderBy('id')
            ->first();

        if ($line === null) {
            return null;
        }

        $grade = $this->normalizeGrade($line->grade);
        $diameter = $line->diameter_mm !== null ? round((float) $line->diameter_mm, 2) : null;

        if ($grade !== '' && $diameter !== null && $diameter > 0) {
            return [
                'grade' => $grade,
                'diameter_mm' => $diameter,
            ];
        }

        return $this->resolveMaterialSpecFromInventoryName(
            $line->inventoryItem?->name,
        );
    }

    /**
     * @return array{grade: string, diameter_mm: float}|null
     */
    public function resolveMaterialSpecFromBatch(ProductionBatch $batch): ?array
    {
        $spec = $this->resolveMaterialSpecFromWorkOrder($batch->work_order_id);
        if ($spec !== null) {
            return $spec;
        }

        return $this->resolveMaterialSpecFromInventoryName($batch->product_name);
    }

    /**
     * @return array{grade: string, diameter_mm: float}|null
     */
    public function resolveMaterialSpecFromInventoryName(?string $name): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $item = Item::query()
            ->where(function ($query) use ($name) {
                $query->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->orWhereRaw('LOWER(code) = ?', [strtolower($name)]);
            })
            ->whereNotNull('grade')
            ->where('grade', '!=', '')
            ->whereNotNull('diameter_mm')
            ->first();

        if ($item !== null) {
            return [
                'grade' => $this->normalizeGrade((string) $item->grade),
                'diameter_mm' => round((float) $item->diameter_mm, 2),
            ];
        }

        if (preg_match('/\b(EN\d+[A-Z]?|EN8D|HC|HEX|MS)\b/i', $name, $gradeMatch)) {
            $grade = $this->normalizeGrade($gradeMatch[1]);
            if (preg_match('/(?:dia|diameter|d)\s*[:.]?\s*(\d+(?:\.\d+)?)\s*(?:mm)?/i', $name, $diaMatch)) {
                return [
                    'grade' => $grade,
                    'diameter_mm' => round((float) $diaMatch[1], 2),
                ];
            }
            if (preg_match('/\b(\d+(?:\.\d+)?)\s*mm\b/i', $name, $diaMatch)) {
                return [
                    'grade' => $grade,
                    'diameter_mm' => round((float) $diaMatch[1], 2),
                ];
            }
        }

        return null;
    }

    public function recalculateAveragesForBatch(ProductionBatch $batch): void
    {
        $spec = $this->resolveMaterialSpecFromBatch($batch);
        if ($spec === null) {
            return;
        }

        $this->recalculateAveragesForMaterialSpec(
            $spec['grade'],
            $spec['diameter_mm'],
        );
    }

    public function recalculateAveragesForMaterialSpec(string $grade, float $diameterMm): void
    {
        $grade = $this->normalizeGrade($grade);
        $diameter = round($diameterMm, 2);

        $workOrderIds = WorkOrderMaterial::query()
            ->where('grade', $grade)
            ->where('diameter_mm', $diameter)
            ->pluck('work_order_id')
            ->unique()
            ->values();

        if ($workOrderIds->isEmpty()) {
            return;
        }

        $batches = ProductionBatch::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereIn('work_order_id', $workOrderIds)
            ->get();

        /** @var array<int, list<float>> $samplesByMachine */
        $samplesByMachine = [];

        foreach ($batches as $batch) {
            $quantity = max(1, (int) $batch->quantity);
            $stages = $this->resolveStagesForBatch($batch);
            $history = array_values($batch->stage_history ?? []);

            foreach ($history as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $seconds = $this->stageDurationSeconds($entry);
                if ($seconds <= 0) {
                    continue;
                }

                $machineId = $this->resolveMachineIdForHistoryEntry($entry, $stages, $index);
                if ($machineId === null || $machineId <= 0) {
                    continue;
                }

                $samplesByMachine[$machineId][] = ($seconds / 60) / $quantity;
            }
        }

        foreach ($samplesByMachine as $machineId => $samples) {
            if ($samples === []) {
                continue;
            }

            ItemMachineTime::query()->updateOrCreate(
                [
                    'machine_id' => $machineId,
                    'grade' => $grade,
                    'diameter_mm' => $diameter,
                ],
                [
                    'average_time' => round(array_sum($samples) / count($samples), 2),
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveStagesForBatch(ProductionBatch $batch): array
    {
        $stages = [];

        if ($batch->work_order_id) {
            $order = WorkOrder::query()->find($batch->work_order_id);
            $orderStages = $order?->stages ?? [];
            if (is_array($orderStages) && $orderStages !== []) {
                $stages = $orderStages;
            }
        }

        if ($stages === [] && $batch->workflow_id) {
            $workflow = Workflow::query()->find($batch->workflow_id);
            $workflowStages = $workflow?->stages ?? [];
            if (is_array($workflowStages) && $workflowStages !== []) {
                $stages = $workflowStages;
            }
        }

        if ($stages === []) {
            return [];
        }

        usort($stages, fn (array $left, array $right) => ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0)));

        return array_values($stages);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<int, array<string, mixed>>  $stages
     */
    private function resolveMachineIdForHistoryEntry(array $entry, array $stages, int $index): ?int
    {
        if (! empty($entry['machine_id'])) {
            return (int) $entry['machine_id'];
        }

        $stageName = strtolower(trim((string) ($entry['stage'] ?? '')));
        if ($stageName !== '') {
            foreach ($stages as $stage) {
                $name = strtolower(trim((string) ($stage['name'] ?? '')));
                if ($name !== '' && $name === $stageName && ! empty($stage['machine_id'])) {
                    return (int) $stage['machine_id'];
                }
            }
        }

        $stage = $stages[$index] ?? null;
        if (is_array($stage) && ! empty($stage['machine_id'])) {
            return (int) $stage['machine_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function stageDurationSeconds(array $entry): int
    {
        $stageStart = $this->parseTimestamp($entry['started'] ?? $entry['started_at'] ?? null);
        $stageEnd = $this->parseTimestamp($entry['completed'] ?? $entry['completed_at'] ?? null);

        if ($stageStart !== null && $stageEnd !== null && $stageEnd->gte($stageStart)) {
            return $stageStart->diffInSeconds($stageEnd);
        }

        return $this->parseDurationLabel($entry['duration'] ?? null) ?? 0;
    }

    private function parseTimestamp(mixed $raw): ?Carbon
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = trim($raw);
        if ($value === '' || $value === '—' || $value === '-') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDurationLabel(mixed $raw): ?int
    {
        if (! is_string($raw)) {
            return null;
        }

        $label = trim($raw);
        if ($label === '' || $label === '—' || $label === '-') {
            return null;
        }

        $seconds = 0;
        $matched = false;

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*d/i', $label, $days)) {
            foreach ($days[1] as $value) {
                $seconds += (int) round(((float) $value) * 86400);
                $matched = true;
            }
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*h/i', $label, $hours)) {
            foreach ($hours[1] as $value) {
                $seconds += (int) round(((float) $value) * 3600);
                $matched = true;
            }
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*m(?!s)/i', $label, $minutes)) {
            foreach ($minutes[1] as $value) {
                $seconds += (int) round(((float) $value) * 60);
                $matched = true;
            }
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*s/i', $label, $secs)) {
            foreach ($secs[1] as $value) {
                $seconds += (int) round((float) $value);
                $matched = true;
            }
        }

        if (! $matched && preg_match('/^\d+(?:\.\d+)?$/', $label)) {
            return (int) round(((float) $label) * 60);
        }

        return $matched ? $seconds : null;
    }

    private function normalizeGrade(?string $grade): string
    {
        return strtoupper(trim((string) $grade));
    }
}
