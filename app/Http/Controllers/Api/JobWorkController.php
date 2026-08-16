<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobWorkController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            JobWork::query()->latest()->get()->map(fn (JobWork $jobWork) => $this->format($jobWork)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        $jobWork = JobWork::query()->updateOrCreate(
            [
                'job_number' => $payload['job_number'],
            ],
            $payload,
        );

        return response()->json($this->format($jobWork), 201);
    }

    public function update(Request $request, JobWork $jobWork): JsonResponse
    {
        $jobWork->update($this->validatedPayload($request, isUpdate: true));

        return response()->json($this->format($jobWork->fresh()));
    }

    public function updateStatus(Request $request, JobWork $jobWork): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|max:100',
        ]);

        $jobWork->update($data);

        return response()->json($this->format($jobWork->fresh()));
    }

    public function destroy(JobWork $jobWork): JsonResponse
    {
        $jobWork->delete();

        return response()->json(['message' => 'Job work deleted']);
    }

    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate
            ? 'required|string|max:255'
            : 'required|string|max:255|unique:job_works,job_number';

        return $request->validate([
            'work_order_id' => 'nullable|integer',
            'batch_id' => 'nullable|integer',
            'job_number' => $rule,
            'vendor_name' => 'required|string|max:255',
            'vendor_id' => 'nullable|integer',
            'product_name' => 'required|string|max:255',
            'process_name' => 'nullable|string|max:255',
            'quantity' => 'nullable|integer|min:0',
            'material_sent' => 'nullable|string|max:255',
            'quantity_sent' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'rate' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'client_name' => 'nullable|string|max:255',
            'client_id' => 'nullable|integer',
            'due_date' => 'nullable|date',
            'sent_date' => 'nullable|date',
            'expected_return_date' => 'nullable|date',
            'actual_return_date' => 'nullable|date',
            'status' => 'nullable|string|max:100',
            'from_workflow' => 'nullable|boolean',
            'workflow_stage' => 'nullable|string|max:255',
            'workflow_name' => 'nullable|string|max:255',
            'pr_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
    }

    private function format(JobWork $jobWork): array
    {
        return [
            'id' => $jobWork->id,
            'work_order_id' => $jobWork->work_order_id,
            'batch_id' => $jobWork->batch_id,
            'job_number' => $jobWork->job_number,
            'vendor_name' => $jobWork->vendor_name,
            'vendor_id' => $jobWork->vendor_id,
            'product_name' => $jobWork->product_name,
            'process_name' => $jobWork->process_name,
            'quantity' => $jobWork->quantity,
            'material_sent' => $jobWork->material_sent,
            'quantity_sent' => $jobWork->quantity_sent,
            'unit' => $jobWork->unit,
            'rate' => $jobWork->rate,
            'total_amount' => $jobWork->total_amount,
            'client_name' => $jobWork->client_name,
            'client_id' => $jobWork->client_id,
            'due_date' => $jobWork->due_date?->format('Y-m-d'),
            'sent_date' => $jobWork->sent_date?->format('Y-m-d'),
            'expected_return_date' => $jobWork->expected_return_date?->format('Y-m-d'),
            'actual_return_date' => $jobWork->actual_return_date?->format('Y-m-d'),
            'status' => $jobWork->status,
            'from_workflow' => $jobWork->from_workflow,
            'workflow_stage' => $jobWork->workflow_stage,
            'workflow_name' => $jobWork->workflow_name,
            'pr_number' => $jobWork->pr_number,
            'notes' => $jobWork->notes,
        ];
    }
}
