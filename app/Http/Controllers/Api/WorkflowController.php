<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Workflow::query()->latest()->get()->map(fn (Workflow $workflow) => $this->format($workflow)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $workflow = Workflow::create($this->validatedPayload($request));

        return response()->json($this->format($workflow), 201);
    }

    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $workflow->update($this->validatedPayload($request));

        return response()->json($this->format($workflow->fresh()));
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $workflow->delete();

        return response()->json(['message' => 'Workflow deleted']);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'stages' => 'required|array|min:1',
            'stages.*.name' => 'required|string|max:255',
            'stages.*.order' => 'required|integer|min:1',
            'stages.*.machine_id' => 'nullable|integer',
            'stages.*.machine_name' => 'nullable|string|max:255',
            'stages.*.machine_code' => 'nullable|string|max:255',
        ]) + ['is_active' => true];
    }

    private function format(Workflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'stages' => $workflow->stages ?? [],
            'is_active' => $workflow->is_active,
            'created_at' => $workflow->created_at?->format('Y-m-d'),
        ];
    }
}
