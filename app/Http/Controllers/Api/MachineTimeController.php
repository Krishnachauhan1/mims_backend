<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemMachineTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MachineTimeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grade' => 'required|string|max:50',
            'diameter_mm' => 'required|numeric|min:0',
        ]);

        $grade = $this->normalizeGrade($data['grade']);
        $diameter = round((float) $data['diameter_mm'], 2);

        $rows = ItemMachineTime::query()
            ->where('grade', $grade)
            ->where('diameter_mm', $diameter)
            ->with('machine:id,name,machine_code')
            ->get()
            ->map(fn (ItemMachineTime $row) => $this->format($row))
            ->values();

        return response()->json($rows);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'machine_id' => 'required|integer|exists:machinery,id',
            'grade' => 'required|string|max:50',
            'diameter_mm' => 'required|numeric|min:0',
            'per_piece_time' => 'required|numeric|min:0',
            'item_id' => 'nullable|integer|exists:items,id',
        ]);

        $grade = $this->normalizeGrade($data['grade']);
        $diameter = round((float) $data['diameter_mm'], 2);

        $row = ItemMachineTime::query()->updateOrCreate(
            [
                'machine_id' => $data['machine_id'],
                'grade' => $grade,
                'diameter_mm' => $diameter,
            ],
            [
                'item_id' => $data['item_id'] ?? null,
                'per_piece_time' => round((float) $data['per_piece_time'], 2),
            ],
        );

        $row->load('machine:id,name,machine_code');

        return response()->json($this->format($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ItemMachineTime $row): array
    {
        return [
            'machine_id' => $row->machine_id,
            'machine_name' => $row->machine?->name,
            'machine_code' => $row->machine?->machine_code,
            'grade' => $row->grade,
            'diameter_mm' => $row->diameter_mm,
            'per_piece_time' => $row->per_piece_time,
            'average_time' => $row->average_time,
        ];
    }

    private function normalizeGrade(string $grade): string
    {
        return strtoupper(trim($grade));
    }
}
