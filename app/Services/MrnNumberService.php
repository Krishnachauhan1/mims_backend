<?php

namespace App\Services;

use App\Models\InventoryTransaction;

class MrnNumberService
{
    public function nextMrnNumber(): string
    {
        $year = now()->year;
        $prefix = "MRN-{$year}-";

        $maxSeq = InventoryTransaction::query()
            ->where('mrn_number', 'like', $prefix.'%')
            ->pluck('mrn_number')
            ->map(function (string $mrnNumber) use ($year) {
                if (preg_match('/^MRN-'.$year.'-(\d+)$/', $mrnNumber, $matches) === 1) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return $prefix.str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT);
    }

    public function ensureMrnNumber(InventoryTransaction $transaction): InventoryTransaction
    {
        if ($transaction->type !== 'checkin' || filled($transaction->mrn_number)) {
            return $transaction;
        }

        $transaction->update([
            'mrn_number' => $this->nextMrnNumber(),
        ]);

        return $transaction->fresh();
    }
}
