<?php

namespace App\Http\Controllers\Web;

use App\Models\Client;
use App\Models\Item;
use App\Models\Machinery;
use App\Models\WorkOrder;
use App\Models\Workflow;
use App\Services\AppNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkflowController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('workflow.view');

        $tab = (string) $request->query('tab', 'all');
        $search = trim((string) $request->query('q', ''));

        $query = WorkOrder::query()->latest();

        $query->when($tab === 'draft', fn ($q) => $q->where('supervisor_status', 'draft'));
        $query->when($tab === 'pending', fn ($q) => $q->where('supervisor_status', 'pending'));
        $query->when($tab === 'wip', fn ($q) => $q->where('supervisor_status', 'wip'));

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('route_card_no', 'like', "%{$search}%")
                    ->orWhere('product', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('workflow_name', 'like', "%{$search}%")
                    ->orWhere('supervisor_status', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(25)->withQueryString();

        return view('workflow.index', compact('orders', 'search', 'tab'));
    }

    public function create(): View
    {
        $this->authorizePermission('workflow.move');

        return view('workflow.form', [
            'order' => new WorkOrder([
                'supervisor_status' => 'draft',
                'priority' => 'normal',
                'unit' => 'pcs',
                'quantity' => 1,
            ]),
            'workflows' => Workflow::query()->where('is_active', true)->orderBy('name')->get(),
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(),
            'items' => Item::query()->where('status', 'active')->orderBy('name')->get(),
            'machines' => Machinery::query()->orderBy('sequence')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('workflow.move');

        $data = $this->validated($request);
        $workflow = Workflow::query()->findOrFail($data['workflow_id']);

        $client = isset($data['client_id'])
            ? Client::query()->find($data['client_id'])
            : null;
        $item = isset($data['item_id'])
            ? Item::query()->find($data['item_id'])
            : null;

        $stages = $this->normalizeStages($workflow->stages ?? [], $request->input('stages', []));

        $order = WorkOrder::create([
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'client_id' => $client?->id,
            'client_name' => $client?->name,
            'item_id' => $item?->id,
            'product' => $item?->name ?? $data['product'] ?? null,
            'item_code' => $item?->code,
            'unit' => $item?->unit ?? $data['unit'] ?? 'pcs',
            'quantity' => $data['quantity'],
            'remarks' => $data['remarks'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'supervisor_status' => $data['supervisor_status'] ?? 'draft',
            'stages' => $stages,
            'created_by' => auth()->user()->name,
            'updated_by' => auth()->user()->name,
        ]);

        if (blank($order->route_card_no)) {
            $order->update(['route_card_no' => 'WO-'.$order->id]);
        }

        if ($order->supervisor_status === 'pending') {
            AppNotificationService::workflowAssigned($order->fresh());
        }

        return redirect()
            ->route('web.workflow.show', $order)
            ->with('success', 'Workflow created successfully.');
    }

    public function show(WorkOrder $workOrder): View
    {
        $this->authorizePermission('workflow.view');

        return view('workflow.show', [
            'order' => $workOrder,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'workflow_id' => 'required|integer|exists:workflows,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'item_id' => 'nullable|integer|exists:items,id',
            'product' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:2000',
            'priority' => 'nullable|string|in:normal,urgent',
            'supervisor_status' => 'nullable|string|in:draft,pending',
            'stages' => 'nullable|array',
            'stages.*.machine_id' => 'nullable|integer|exists:machinery,id',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $templateStages
     * @param  array<int, array<string, mixed>>  $submittedStages
     * @return list<array<string, mixed>>
     */
    private function normalizeStages(array $templateStages, array $submittedStages): array
    {
        $machines = Machinery::query()
            ->whereIn('id', collect($submittedStages)->pluck('machine_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $normalized = [];
        foreach ($templateStages as $index => $stage) {
            $order = (int) ($stage['order'] ?? ($index + 1));
            $machineId = data_get($submittedStages, "{$index}.machine_id");
            $machine = $machineId ? $machines->get((int) $machineId) : null;

            $normalized[] = [
                'name' => (string) ($stage['name'] ?? ('Stage '.$order)),
                'order' => $order,
                'machine_id' => $machine?->id,
                'machine_name' => $machine?->name,
                'machine_code' => $machine?->machine_code,
            ];
        }

        return $normalized;
    }
}
