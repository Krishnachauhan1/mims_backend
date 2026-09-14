<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InventoryTransaction;
use App\Models\JobWork;
use App\Models\Vendor;
use App\Services\JobWorkFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobWorkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('bundle') || $request->query('bundle') === '1') {
            return $this->screenPayload($request);
        }

        return response()->json(
            JobWork::query()->latest()->get()->map(fn (JobWork $jobWork) => $this->format($jobWork)),
        );
    }

    /**
     * Combined payload for Job Work screen — use GET /job-work?bundle=1 (no extra path segment).
     */
    private function screenPayload(Request $request): JsonResponse
    {
        $jobWork = JobWork::query()
            ->latest()
            ->get()
            ->map(fn (JobWork $jobWork) => $this->format($jobWork));

        $movements = $this->buildMovementsPayload($request);

        $clients = Client::query()
            ->where('status', 'active')
            ->latest()
            ->get()
            ->map(fn (Client $client) => $this->formatClient($client));

        $vendors = Vendor::query()
            ->where('status', 'active')
            ->latest()
            ->get()
            ->map(fn (Vendor $vendor) => $this->formatVendor($vendor));

        return response()->json([
            'job_work' => $jobWork->values(),
            'movements' => $movements['data'],
            'clients' => $clients->values(),
            'vendors' => $vendors->values(),
            'meta' => [
                'movements' => $movements['meta'],
            ],
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $payload = $this->buildMovementsPayload($request);

        return response()->json([
            'data' => $payload['data'],
            'meta' => $payload['meta'],
        ]);
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

    /**
     * @return array{data: \Illuminate\Support\Collection<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function buildMovementsPayload(Request $request): array
    {
        $query = InventoryTransaction::query()
            ->where('flow_source', JobWorkFlowService::FLOW_SOURCE)
            ->with('user:id,name')
            ->latest();

        $direction = $request->string('direction', 'all')->toString();
        if ($direction === 'out') {
            $query->where('type', 'checkout');
        } elseif ($direction === 'in') {
            $query->where('type', 'checkin');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('material_name', 'like', $term)
                    ->orWhere('batch_number', 'like', $term)
                    ->orWhere('destination', 'like', $term)
                    ->orWhere('supplier', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        $rows = $query->get()->map(
            fn (InventoryTransaction $transaction) => $this->formatMovement($transaction),
        );

        $statsQuery = InventoryTransaction::query()
            ->where('flow_source', JobWorkFlowService::FLOW_SOURCE);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $statsQuery->where(function ($builder) use ($term): void {
                $builder
                    ->where('material_name', 'like', $term)
                    ->orWhere('batch_number', 'like', $term)
                    ->orWhere('destination', 'like', $term)
                    ->orWhere('supplier', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        return [
            'data' => $rows->values(),
            'meta' => [
                'total' => $rows->count(),
                'out_count' => (int) (clone $statsQuery)->where('type', 'checkout')->count(),
                'in_count' => (int) (clone $statsQuery)->where('type', 'checkin')->count(),
            ],
        ];
    }

    private function formatClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'contact_person' => $client->contact_person,
            'email' => $client->email,
            'phone' => $client->phone,
            'gst_number' => $client->gst_number,
            'address' => $client->address,
            'city' => $client->city,
            'status' => $client->status,
            'total_orders' => $client->total_orders,
            'last_order_date' => $client->last_order_date?->format('Y-m-d'),
            'created_at' => $client->created_at?->format('Y-m-d'),
            'items' => $client->items ?? [],
        ];
    }

    private function formatVendor(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'contact_person' => $vendor->contact_person,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'gst_number' => $vendor->gst_number,
            'address' => $vendor->address,
            'city' => $vendor->city,
            'status' => $vendor->status,
            'total_orders' => $vendor->total_orders,
            'last_order_date' => $vendor->last_order_date?->format('Y-m-d'),
            'created_at' => $vendor->created_at?->format('Y-m-d'),
            'items' => $vendor->items ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMovement(InventoryTransaction $transaction): array
    {
        $isOut = $transaction->type === 'checkout';
        $phase = $transaction->receipt_phase ?? $transaction->status;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'direction' => $isOut ? 'out' : 'in',
            'material_name' => $transaction->material_name,
            'supplier' => $transaction->supplier,
            'destination' => $transaction->destination,
            'vendor_name' => $isOut ? $transaction->destination : $transaction->supplier,
            'quantity' => $transaction->quantity,
            'unit' => $transaction->unit,
            'batch_number' => $transaction->batch_number,
            'notes' => $transaction->notes,
            'status' => $transaction->status,
            'receipt_phase' => $transaction->receipt_phase,
            'movement_status' => $isOut
                ? ($transaction->status === JobWorkFlowService::CHECKOUT_STATUS_SENT ? 'sent' : ($transaction->status ?? 'sent'))
                : ($phase === JobWorkFlowService::CHECKIN_PHASE_RECEIVED ? 'received' : 'awaiting_return'),
            'user' => $transaction->user?->name ?? 'System',
            'datetime' => $transaction->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'),
            'flow_source' => $transaction->flow_source,
            'production_batch_id' => $transaction->production_batch_id,
            'job_work_id' => $transaction->job_work_id,
            'work_order_id' => $transaction->work_order_id,
        ];
    }
}
