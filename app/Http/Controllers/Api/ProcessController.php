<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Process;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Process::query()->latest()->get()->map(fn (Process $process) => $this->format($process)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $process = Process::create($this->validatedPayload($request));

        return response()->json($this->format($process), 201);
    }

    public function update(Request $request, Process $process): JsonResponse
    {
        $process->update($this->validatedPayload($request));

        return response()->json($this->format($process->fresh()));
    }

    public function destroy(Process $process): JsonResponse
    {
        $process->delete();

        return response()->json(['message' => 'Process deleted']);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'process_type' => 'nullable|string|max:100',
            'rate' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:100',
        ]);
    }

    private function format(Process $process): array
    {
        return [
            'id' => $process->id,
            'name' => $process->name,
            'description' => $process->description,
            'process_type' => $process->process_type,
            'rate' => $process->rate,
            'unit' => $process->unit,
            'status' => $process->status,
        ];
    }
}
