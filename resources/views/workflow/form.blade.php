@extends('layouts.app')

@section('title', 'New Workflow')
@section('page-title', 'Create Workflow')

@section('content')
<div class="toolbar">
    <a href="{{ route('web.workflow.index') }}" class="btn btn-secondary">← Back to list</a>
</div>

<div class="card">
    <form method="POST" action="{{ route('web.workflow.store') }}">
        @csrf

        <div class="form-grid">
            <div style="grid-column:1/-1;">
                <label for="workflow_id">Workflow Template *</label>
                <select id="workflow_id" name="workflow_id" required>
                    <option value="">Select template…</option>
                    @foreach ($workflows as $workflow)
                        <option value="{{ $workflow->id }}" @selected(old('workflow_id') == $workflow->id)>
                            {{ $workflow->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="client_id">Client</label>
                <select id="client_id" name="client_id">
                    <option value="">— None —</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="item_id">Product / Item</label>
                <select id="item_id" name="item_id">
                    <option value="">— Select item —</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}" @selected(old('item_id') == $item->id)>
                            {{ $item->name }} ({{ $item->code }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="quantity">Quantity *</label>
                <input id="quantity" type="number" min="1" name="quantity" value="{{ old('quantity', $order->quantity) }}" required>
            </div>

            <div>
                <label for="unit">Unit</label>
                <input id="unit" name="unit" value="{{ old('unit', $order->unit) }}">
            </div>

            <div>
                <label for="priority">Priority</label>
                <select id="priority" name="priority">
                    <option value="normal" @selected(old('priority', $order->priority) === 'normal')>Normal</option>
                    <option value="urgent" @selected(old('priority', $order->priority) === 'urgent')>Urgent</option>
                </select>
            </div>

            <div>
                <label for="supervisor_status">Save as</label>
                <select id="supervisor_status" name="supervisor_status">
                    <option value="draft" @selected(old('supervisor_status', 'draft') === 'draft')>Draft</option>
                    <option value="pending" @selected(old('supervisor_status') === 'pending')>Submit to Tracking</option>
                </select>
            </div>

            <div style="grid-column:1/-1;">
                <label for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks" rows="3">{{ old('remarks') }}</textarea>
            </div>
        </div>

        @if ($workflows->isNotEmpty())
            @php
                $selectedWorkflow = $workflows->firstWhere('id', (int) old('workflow_id')) ?? $workflows->first();
            @endphp
            <div style="margin-top:24px;">
                <h3 style="margin:0 0 12px;font-size:16px;">Stage machine assignment</h3>
                <p class="text-muted" style="margin:0 0 12px;">Assign machines per stage (optional).</p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Stage</th>
                                <th>Machine</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($selectedWorkflow->stages ?? [] as $index => $stage)
                                <tr>
                                    <td>{{ $stage['name'] ?? ('Stage '.($index + 1)) }}</td>
                                    <td>
                                        <select name="stages[{{ $index }}][machine_id]">
                                            <option value="">— Auto / None —</option>
                                            @foreach ($machines as $machine)
                                                <option value="{{ $machine->id }}">
                                                    {{ $machine->name }} ({{ $machine->machine_code }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div style="margin-top:24px;display:flex;gap:10px;">
            <button class="btn btn-primary" type="submit">Create Workflow</button>
            <a class="btn btn-secondary" href="{{ route('web.workflow.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
