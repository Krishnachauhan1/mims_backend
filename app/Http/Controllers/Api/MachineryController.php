<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machinery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MachineryController extends Controller
{
    public function index(): JsonResponse
    {
        $machines = Machinery::query()
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (Machinery $machine) => $this->format($machine));

        return response()->json($machines);
    }

    public function store(Request $request): JsonResponse
    {
        $machine = Machinery::create($this->validatedPayload($request));

        return response()->json($this->format($machine), 201);
    }

    public function update(Request $request, Machinery $machinery): JsonResponse
    {
        $machinery->update($this->validatedPayload($request, $machinery));

        return response()->json($this->format($machinery->fresh()));
    }

    public function destroy(Machinery $machinery): JsonResponse
    {
        $machinery->delete();

        return response()->json(['message' => 'Machine deleted']);
    }

    private function validatedPayload(Request $request, ?Machinery $machine = null): array
    {
        return $request->validate([
            'sequence' => 'nullable|integer|min:0',
            'machine_code' => 'required|string|max:255|unique:machinery,machine_code,'.($machine?->id ?? 'NULL'),
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'capacity' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,maintenance,inactive',
            'default_stage' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
    }

    private function format(Machinery $machine): array
    {
        return [
            'id' => $machine->id,
            'sequence' => $machine->sequence,
            'machine_code' => $machine->machine_code,
            'name' => $machine->name,
            'type' => $machine->type,
            'location' => $machine->location,
            'capacity' => $machine->capacity,
            'manufacturer' => $machine->manufacturer,
            'status' => $machine->status,
            'default_stage' => $machine->default_stage,
            'notes' => $machine->notes,
        ];
    }
}
