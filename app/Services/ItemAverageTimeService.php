<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ProductionBatch;
use App\Models\WorkOrder;
use Carbon\Carbon;

class ItemAverageTimeService
{
    public function recalculateForBatch(ProductionBatch $batch): void
    {
        $itemId = $this->resolveItemIdForBatch($batch);
        if ($itemId === null) {
            return;
        }

        $this->recalculateForItemId($itemId);
    }

    public function recalculateForItemId(int $itemId): void
    {
        $item = Item::query()->find($itemId);
        if ($item === null) {
            return;
        }

        $names = array_values(array_filter(array_unique([
            strtolower(trim((string) $item->code)),
            strtolower(trim((string) $item->name)),
        ])));

        $workOrderIds = WorkOrder::query()
            ->where('item_id', $item->id)
            ->pluck('id');

        if ($workOrderIds->isEmpty() && $names === []) {
            $item->update(['average_time' => null]);

            return;
        }

        $batches = ProductionBatch::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where(function ($query) use ($workOrderIds, $names) {
                $matched = false;

                if ($workOrderIds->isNotEmpty()) {
                    $query->whereIn('work_order_id', $workOrderIds);
                    $matched = true;
                }

                foreach ($names as $name) {
                    if ($matched) {
                        $query->orWhereRaw('LOWER(product_name) = ?', [$name]);
                    } else {
                        $query->whereRaw('LOWER(product_name) = ?', [$name]);
                        $matched = true;
                    }
                }
            })
            ->get();

        $perPieceMinutes = [];
        foreach ($batches as $batch) {
            $seconds = $this->batchDurationSeconds($batch);
            if ($seconds <= 0) {
                continue;
            }

            $quantity = max(1, (int) $batch->quantity);
            $perPieceMinutes[] = ($seconds / 60) / $quantity;
        }

        $item->update([
            'average_time' => $perPieceMinutes === []
                ? null
                : round(array_sum($perPieceMinutes) / count($perPieceMinutes), 2),
        ]);
    }

    private function resolveItemIdForBatch(ProductionBatch $batch): ?int
    {
        if ($batch->work_order_id !== null) {
            $itemId = WorkOrder::query()
                ->whereKey($batch->work_order_id)
                ->value('item_id');

            if ($itemId !== null) {
                return (int) $itemId;
            }
        }

        $productName = strtolower(trim((string) $batch->product_name));
        if ($productName === '') {
            return null;
        }

        $itemId = Item::query()
            ->where(function ($query) use ($productName) {
                $query->whereRaw('LOWER(code) = ?', [$productName])
                    ->orWhereRaw('LOWER(name) = ?', [$productName]);
            })
            ->value('id');

        return $itemId !== null ? (int) $itemId : null;
    }

    private function batchDurationSeconds(ProductionBatch $batch): int
    {
        if ($batch->started_at !== null && $batch->completed_at !== null) {
            $seconds = $batch->started_at->diffInSeconds($batch->completed_at, false);
            if ($seconds > 0) {
                return $seconds;
            }
        }

        $history = array_values($batch->stage_history ?? []);
        if ($history === []) {
            return 0;
        }

        $firstStart = null;
        $lastEnd = null;
        $summedSeconds = 0;
        $hasSummed = false;

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $stageStart = $this->parseTimestamp($entry['started'] ?? $entry['started_at'] ?? null);
            $stageEnd = $this->parseTimestamp($entry['completed'] ?? $entry['completed_at'] ?? null);

            if ($stageStart !== null && ($firstStart === null || $stageStart->lt($firstStart))) {
                $firstStart = $stageStart;
            }

            if ($stageEnd !== null && ($lastEnd === null || $stageEnd->gt($lastEnd))) {
                $lastEnd = $stageEnd;
            }

            $parsed = $this->parseDurationLabel($entry['duration'] ?? null);
            if ($parsed !== null) {
                $summedSeconds += $parsed;
                $hasSummed = true;
            }
        }

        if ($firstStart !== null && $lastEnd !== null && $lastEnd->gte($firstStart)) {
            return $firstStart->diffInSeconds($lastEnd);
        }

        return $hasSummed ? $summedSeconds : 0;
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
}
