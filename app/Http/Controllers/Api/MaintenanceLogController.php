<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machinery;
use App\Models\MachineryMaintenanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MachineryMaintenanceLog::query()
            ->with('machinery')
            ->latest('performed_at')
            ->latest('id');

        if ($request->filled('machinery_id')) {
            $query->where('machinery_id', $request->integer('machinery_id'));
        }

        $logs = $query->get()->map(fn (MachineryMaintenanceLog $log) => $this->format($log));

        return response()->json($logs);
    }

    public function store(Request $request, Machinery $machinery): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'performed_at' => 'nullable|date',
            'status' => 'nullable|string|in:completed,scheduled,in_progress',
            'performed_by' => 'nullable|string|max:255',
            'set_machine_status' => 'nullable|string|in:maintenance,active,inactive',
        ]);

        $log = MachineryMaintenanceLog::create([
            'machinery_id' => $machinery->id,
            'type' => $data['type'],
            'notes' => $data['notes'] ?? null,
            'performed_at' => $data['performed_at'] ?? now()->toDateString(),
            'status' => $data['status'] ?? 'completed',
            'performed_by' => $data['performed_by'] ?? $request->user()?->name,
        ]);

        $machineStatus = $data['set_machine_status'] ?? 'maintenance';
        $machinery->update(['status' => $machineStatus]);

        return response()->json($this->format($log->fresh('machinery')), 201);
    }

    private function format(MachineryMaintenanceLog $log): array
    {
        $machine = $log->machinery;

        return [
            'id' => $log->id,
            'machinery_id' => $log->machinery_id,
            'machine_id' => $log->machinery_id,
            'machine_name' => $machine?->name,
            'machine_code' => $machine?->machine_code,
            'type' => $log->type,
            'notes' => $log->notes,
            'performed_at' => $log->performed_at?->format('Y-m-d'),
            'status' => $log->status,
            'performed_by' => $log->performed_by,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
