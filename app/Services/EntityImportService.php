<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\FinishedGood;
use App\Models\Gauge;
use App\Http\Controllers\Api\InventoryController;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Machinery;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\Workflow;
use App\Services\AppNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EntityImportService
{
    public function __construct(private PermissionService $permissions) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(string $entity, array $rows): array
    {
        return match ($entity) {
            'users' => $this->importUsers($rows),
            'vendors' => $this->importVendors($rows),
            'clients' => $this->importClients($rows),
            'inventory' => $this->importInventory($rows),
            'items' => $this->importItems($rows),
            'work-orders' => $this->importWorkOrders($rows),
            'machinery' => $this->importMachinery($rows),
            'gauges' => $this->importGauges($rows),
            'finished-goods' => $this->importFinishedGoods($rows),
            default => throw new \InvalidArgumentException("Unknown entity: {$entity}"),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportRows(string $entity): array
    {
        return match ($entity) {
            'users' => User::query()->latest()->get()->map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'password' => '',
                'role' => $user->role,
                'department' => $user->department ?? '',
                'designation' => $user->designation ?? '',
                'phone' => $user->phone ?? '',
                'is_active' => $user->is_active ? 'yes' : 'no',
            ])->all(),
            'vendors' => Vendor::query()->latest()->get()->map(fn (Vendor $vendor) => [
                'name' => $vendor->name,
                'contact_person' => $vendor->contact_person ?? '',
                'email' => $vendor->email ?? '',
                'phone' => $vendor->phone ?? '',
                'gst_number' => $vendor->gst_number ?? '',
                'address' => $vendor->address ?? '',
                'city' => $vendor->city ?? '',
                'status' => $vendor->status ?? 'active',
            ])->all(),
            'clients' => Client::query()->latest()->get()->map(fn (Client $client) => [
                'name' => $client->name,
                'contact_person' => $client->contact_person ?? '',
                'email' => $client->email ?? '',
                'phone' => $client->phone ?? '',
                'gst_number' => $client->gst_number ?? '',
                'address' => $client->address ?? '',
                'city' => $client->city ?? '',
                'status' => $client->status ?? 'active',
            ])->all(),
            'inventory' => InventoryItem::query()->latest()->get()->map(fn (InventoryItem $item) => [
                'name' => $item->name,
                'sku' => $item->sku,
                'type' => $item->type,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'batch_number' => $item->batch_number ?? '',
                'location' => $item->location ?? '',
                'min_stock' => $item->min_stock ?? 0,
                'status' => $item->status ?? 'available',
            ])->all(),
            'items' => Item::query()
                ->whereIn('category', ['product', 'raw_material'])
                ->orderBy('category')
                ->orderBy('code')
                ->get()
                ->map(fn (Item $item) => [
                    'item_code' => $item->code,
                    'category' => $item->category ?? 'product',
                    'item_description' => $item->description ?? '',
                    'material_grade' => $item->grade ?? '',
                    'diameter_mm' => $item->diameter_mm ?? '',
                    'cutting_weight' => $item->cutting_weight ?? '',
                    'final_weight' => $item->final_weight ?? '',
                    'cutting_size' => $item->cutting_size ?? '',
                    'moq' => $item->min_stock_alert ?? 0,
                    'unit' => $item->unit ?? 'pcs',
                    'status' => $item->status ?? 'active',
                    'remark' => $item->remark ?? '',
                    'inactive_date' => $item->inactive_date?->format('d/m/Y') ?? '',
                    'documents_count' => Document::query()
                        ->where('module', 'item')
                        ->where('entity_id', (string) $item->id)
                        ->count(),
                ])->all(),
            'work-orders' => WorkOrder::query()
                ->latest()
                ->get()
                ->map(fn (WorkOrder $order) => [
                    'route_card_no' => $order->route_card_no ?? '',
                    'workflow_name' => $order->workflow_name ?? '',
                    'item_code' => $order->item_code ?? '',
                    'product' => $order->product ?? '',
                    'client_name' => $order->client_name !== '—' ? ($order->client_name ?? '') : '',
                    'quantity' => $order->quantity ?? 1,
                    'unit' => $order->unit ?? 'pcs',
                    'pr_number' => $order->pr_number ?? '',
                    'priority' => $order->priority ?? 'normal',
                    'remarks' => $order->remarks ?? '',
                    'status' => $order->supervisor_status ?? 'pending',
                ])->all(),
            'machinery' => Machinery::query()
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
                ->map(fn (Machinery $machine) => [
                    'sequence' => $machine->sequence ?? 0,
                    'machine_code' => $machine->machine_code,
                    'name' => $machine->name,
                    'type' => $machine->type ?? '',
                    'location' => $machine->location ?? '',
                    'capacity' => $machine->capacity ?? '',
                    'manufacturer' => $machine->manufacturer ?? '',
                    'status' => $machine->status ?? 'active',
                    'default_stage' => $machine->default_stage ?? '',
                    'notes' => $machine->notes ?? '',
                ])->all(),
            'finished-goods' => FinishedGood::query()
                ->orderBy('batch_number')
                ->get()
                ->map(fn (FinishedGood $good) => [
                    'product' => $good->product_name,
                    'sku' => $good->sku ?? '',
                    'batch_number' => $good->batch_number ?? '',
                    'quantity' => $good->quantity ?? 0,
                    'stock' => $good->stock ?? 0,
                    'dispatched' => $good->dispatched ?? 0,
                    'unit' => $good->unit ?? 'pcs',
                    'location' => $good->location ?? '',
                    'warehouse' => $good->warehouse ?? '',
                    'status' => $good->status ?? '',
                    'quality_status' => $good->quality_status ?? '',
                    'production_date' => $good->production_date?->format('Y-m-d') ?? '',
                    'pr_number' => $good->pr_number ?? '',
                    'workflow' => $good->workflow ?? '',
                ])->all(),
            'gauges' => Gauge::query()
                ->orderBy('instrument_id')
                ->get()
                ->map(fn (Gauge $gauge) => [
                    'sl_no' => '',
                    'instrument_name' => $gauge->name,
                    'ident_no' => $gauge->instrument_id,
                    'certificate_no' => $gauge->certificate_number ?? '',
                    'location' => $gauge->location ?? '',
                    'make' => $gauge->manufacturer ?? '',
                    'range' => $gauge->measuring_range ?? '',
                    'least_count_range' => $gauge->least_count ?? '',
                    'cal_agency' => $gauge->calibration_agency ?? '',
                    'cal_freq' => ($gauge->calibration_frequency_months ?? 12).' MONTHS',
                    'cal_date' => $gauge->last_calibration_date?->format('d/m/Y') ?? '',
                    'trigger_date' => $gauge->calibration_trigger_date?->format('d/m/Y') ?? '',
                    'cal_due_date' => $gauge->next_calibration_date?->format('d/m/Y') ?? '',
                    'cal_status' => strtoupper((string) ($gauge->calibration_status ?? 'valid')),
                ])->all(),
            default => throw new \InvalidArgumentException("Unknown entity: {$entity}"),
        };
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importUsers(array $rows): array
    {
        $result = $this->emptyResult();
        $roles = $this->permissions->roleKeys();
        $designations = $this->permissions->jobDesignationKeys();

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $email = strtolower(trim($row['email'] ?? ''));

            if ($email === '') {
                $result['errors'][] = "Row {$line}: email is required";
                $result['skipped']++;

                continue;
            }

            $existing = User::query()->where('email', $email)->first();
            $isUpdate = $existing !== null;

            $payload = [
                'name' => trim($row['name'] ?? ''),
                'email' => $email,
                'role' => trim($row['role'] ?? 'operator'),
                'department' => $this->nullable($row['department'] ?? null),
                'designation' => $this->nullable($row['designation'] ?? null),
                'phone' => $this->nullable($row['phone'] ?? null),
                'is_active' => $this->parseBool($row['is_active'] ?? 'yes'),
            ];

            $password = trim($row['password'] ?? '');
            if ($password !== '') {
                $payload['password'] = $password;
            } elseif (! $isUpdate) {
                $result['errors'][] = "Row {$line}: password is required for new users";
                $result['skipped']++;

                continue;
            }

            $validator = Validator::make($payload, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users,email,'.($existing?->id ?? 'NULL'),
                'role' => 'required|string|in:'.implode(',', $roles),
                'department' => 'nullable|string|max:255',
                'designation' => 'nullable|string|max:100'.($designations !== [] ? '|in:'.implode(',', $designations) : ''),
                'phone' => 'nullable|string|max:30',
                'is_active' => 'boolean',
                'password' => ($isUpdate ? 'nullable' : 'required').'|string|min:6',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            if ($isUpdate) {
                $existing->update($data);
                $result['updated']++;
            } else {
                User::create([
                    ...$data,
                    'permissions' => $this->permissions->permissionsForRole($data['role']),
                ]);
                $result['created']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importVendors(array $rows): array
    {
        return $this->importContacts($rows, Vendor::class);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importClients(array $rows): array
    {
        return $this->importContacts($rows, Client::class);
    }

    /**
     * @param  class-string<Vendor|Client>  $modelClass
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importContacts(array $rows, string $modelClass): array
    {
        $result = $this->emptyResult();

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim($row['name'] ?? '');

            if ($name === '') {
                $result['errors'][] = "Row {$line}: name is required";
                $result['skipped']++;

                continue;
            }

            $payload = [
                'name' => $name,
                'contact_person' => $this->nullable($row['contact_person'] ?? null),
                'email' => $this->nullable($row['email'] ?? null),
                'phone' => $this->nullable($row['phone'] ?? null),
                'gst_number' => $this->nullable(
                    $row['gst_number'] ?? $row['gst_no'] ?? $row['gst'] ?? null,
                ),
                'address' => $this->nullable($row['address'] ?? null),
                'city' => $this->nullable($row['city'] ?? null),
                'status' => $this->normalizeStatus($row['status'] ?? 'active'),
            ];

            $validator = Validator::make($payload, [
                'name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'gst_number' => 'nullable|string|max:15',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:active,inactive',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();
            $existing = $modelClass::query()->where('name', $name)->first();

            if ($existing !== null) {
                $existing->update($data);
                $result['updated']++;
            } else {
                $modelClass::create([
                    ...$data,
                    'total_orders' => 0,
                ]);
                $result['created']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importInventory(array $rows): array
    {
        $result = $this->emptyResult();

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $sku = trim($row['sku'] ?? '');

            if ($sku === '') {
                $result['errors'][] = "Row {$line}: sku is required";
                $result['skipped']++;

                continue;
            }

            $existing = InventoryItem::query()->where('sku', $sku)->first();

            $payload = [
                'name' => trim($row['name'] ?? ''),
                'sku' => $sku,
                'type' => trim($row['type'] ?? 'raw_material'),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'unit' => trim($row['unit'] ?? 'pcs') ?: 'pcs',
                'batch_number' => $this->nullable($row['batch_number'] ?? null),
                'location' => $this->nullable($row['location'] ?? null),
                'min_stock' => (int) ($row['min_stock'] ?? 0),
                'status' => trim($row['status'] ?? 'available') ?: 'available',
            ];

            $validator = Validator::make($payload, [
                'name' => 'required|string|max:255',
                'sku' => 'required|string|max:255|unique:inventory_items,sku,'.($existing?->id ?? 'NULL'),
                'type' => 'required|string|max:100',
                'status' => 'nullable|string|max:100',
                'quantity' => 'required|integer|min:0',
                'unit' => 'nullable|string|max:50',
                'batch_number' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'min_stock' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();

            if ($existing !== null) {
                $existing->update($data);
                $result['updated']++;
            } else {
                InventoryItem::create($data);
                $result['created']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importItems(array $rows): array
    {
        $result = $this->emptyResult();

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $code = $this->rowValue($row, ['item_code', 'code', 'sku']);

            if ($code === '' || $this->isHeaderLikeItemCode($code)) {
                $result['skipped']++;

                continue;
            }

            $description = $this->rowValue($row, [
                'item_description',
                'description',
                'item_name',
                'name',
            ]);
            $moqRaw = $this->rowValue($row, ['moq', 'min_stock_alert', 'min_stock', 'min_alert']);
            $minStockAlert = max(0, (int) preg_replace('/[^\d]/', '', $moqRaw));
            $grade = $this->rowValue($row, ['material_grade', 'grade', 'material grade']);
            $diameterMm = $this->parseOptionalNumber(
                $this->rowValue($row, ['diameter_mm', 'diameter', 'dia', 'dia_mm'])
            );
            $cuttingWeight = $this->parseOptionalNumber(
                $this->rowValue($row, ['cutting_weight', 'cutting weight'])
            );
            $finalWeight = $this->parseOptionalNumber(
                $this->rowValue($row, ['final_weight', 'final weight'])
            );
            $cuttingSize = $this->rowValue($row, ['cutting_size', 'cutting size']);
            $unit = $this->rowValue($row, ['unit']) ?: 'pcs';
            $status = $this->normalizeStatus($this->rowValue($row, ['status']) ?: 'active');
            $remark = $this->rowValue($row, ['remark', 'remarks']);
            $category = $this->normalizeItemCategory(
                $this->rowValue($row, ['category', 'item_type', 'type'])
            );

            if ($status === 'inactive' && $remark === '') {
                $result['errors'][] = "Row {$line}: remark is required when status is inactive";
                $result['skipped']++;

                continue;
            }

            $existing = Item::query()->where('code', $code)->first();

            $payload = [
                'code' => $code,
                'name' => $code,
                'description' => $description !== '' ? $description : null,
                'grade' => $grade !== '' ? $grade : null,
                'diameter_mm' => $diameterMm,
                'cutting_weight' => $cuttingWeight,
                'final_weight' => $finalWeight,
                'cutting_size' => $cuttingSize !== '' ? $cuttingSize : null,
                'remark' => $remark !== '' ? $remark : null,
                'unit' => $unit,
                'min_stock_alert' => $minStockAlert,
                'category' => $category,
                'status' => $status,
            ];

            if ($status === 'inactive') {
                $wasInactive = $existing !== null && $existing->status === 'inactive';
                $payload['inactive_date'] = ($wasInactive && $existing->inactive_date)
                    ? $existing->inactive_date->format('Y-m-d')
                    : now()->toDateString();
            } else {
                $payload['inactive_date'] = null;
            }

            $validator = Validator::make($payload, [
                'code' => 'required|string|max:255|unique:items,code,'.($existing?->id ?? 'NULL'),
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:255',
                'grade' => 'nullable|string|max:50',
                'diameter_mm' => 'nullable|numeric|min:0',
                'cutting_weight' => 'nullable|numeric|min:0',
                'final_weight' => 'nullable|numeric|min:0',
                'cutting_size' => 'nullable|string|max:100',
                'remark' => 'nullable|string|max:1000',
                'inactive_date' => 'nullable|date',
                'unit' => 'nullable|string|max:50',
                'min_stock_alert' => 'nullable|integer|min:0',
                'category' => 'nullable|string|max:100',
                'status' => 'nullable|string|in:active,inactive',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();

            if ($existing !== null) {
                $existing->update($data);
                $result['updated']++;
            } else {
                Item::create($data);
                $result['created']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importMachinery(array $rows): array
    {
        $result = $this->emptyResult();
        $nextSequence = (int) Machinery::query()->max('sequence');

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $machineCode = $this->rowValue($row, ['machine_code', 'code']);
            $name = $this->rowValue($row, ['name', 'machine_name']);

            if ($machineCode === '' && $name === '') {
                $result['skipped']++;

                continue;
            }

            if ($machineCode === '') {
                $result['errors'][] = "Row {$line}: machine_code is required";
                $result['skipped']++;

                continue;
            }

            if ($name === '') {
                $result['errors'][] = "Row {$line}: name is required";
                $result['skipped']++;

                continue;
            }

            $existing = Machinery::query()->where('machine_code', $machineCode)->first();
            $sequenceRaw = $this->rowValue($row, ['sequence', 'seq', 'order']);
            $sequence = $sequenceRaw !== ''
                ? max(0, (int) preg_replace('/[^\d]/', '', $sequenceRaw))
                : ($existing?->sequence ?? ++$nextSequence);

            $payload = [
                'sequence' => $sequence,
                'machine_code' => $machineCode,
                'name' => $name,
                'type' => $this->nullable($this->rowValue($row, ['type', 'machine_type'])),
                'location' => $this->nullable($this->rowValue($row, ['location'])),
                'capacity' => $this->nullable($this->rowValue($row, ['capacity'])),
                'manufacturer' => $this->nullable($this->rowValue($row, ['manufacturer'])),
                'status' => $this->normalizeMachineryStatus($this->rowValue($row, ['status']) ?: 'active'),
                'default_stage' => $this->nullable($this->rowValue($row, ['default_stage', 'stage'])),
                'notes' => $this->nullable($this->rowValue($row, ['notes', 'note'])),
            ];

            $validator = Validator::make($payload, [
                'sequence' => 'nullable|integer|min:0',
                'machine_code' => 'required|string|max:255|unique:machinery,machine_code,'.($existing?->id ?? 'NULL'),
                'name' => 'required|string|max:255',
                'type' => 'nullable|string|max:100',
                'location' => 'nullable|string|max:255',
                'capacity' => 'nullable|string|max:255',
                'manufacturer' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:active,maintenance,inactive',
                'default_stage' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();

            if ($existing !== null) {
                $existing->update($data);
                $result['updated']++;
            } else {
                Machinery::create($data);
                $result['created']++;
            }
        }

        return $result;
    }

    private function normalizeMachineryStatus(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['maintenance', 'inactive'], true) ? $value : 'active';
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importWorkOrders(array $rows): array
    {
        $result = $this->emptyResult();
        $createdBy = auth()->user()?->name ?? 'Excel Import';

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $workflowName = $this->rowValue($row, ['workflow_name', 'workflow']);
            $itemCode = $this->rowValue($row, ['item_code', 'code']);
            $product = $this->rowValue($row, ['product', 'item_description', 'item_name']);
            $routeCardNo = $this->rowValue($row, ['route_card_no', 'route_card', 'route_card_number']);
            $clientName = $this->rowValue($row, ['client_name', 'client']);
            $quantityRaw = $this->rowValue($row, ['quantity', 'qty']);
            $unit = $this->rowValue($row, ['unit']) ?: 'pcs';
            $prNumber = $this->rowValue($row, ['pr_number', 'pr_no']);
            $priority = strtolower($this->rowValue($row, ['priority']) ?: 'normal');
            $remarks = $this->rowValue($row, ['remarks', 'remark']);
            $statusRaw = strtolower($this->rowValue($row, ['status', 'supervisor_status']) ?: 'pending');

            if ($workflowName === '' && $itemCode === '' && $product === '') {
                $result['skipped']++;

                continue;
            }

            if ($workflowName === '') {
                $result['errors'][] = "Row {$line}: workflow_name is required";
                $result['skipped']++;

                continue;
            }

            $workflow = $this->resolveWorkflowForImport($workflowName);
            if ($workflow === null) {
                $result['errors'][] = "Row {$line}: workflow \"{$workflowName}\" not found";
                $result['skipped']++;

                continue;
            }

            $quantity = max(1, (int) preg_replace('/[^\d]/', '', $quantityRaw));

            $item = null;
            if ($itemCode !== '') {
                $item = Item::query()->where('code', $itemCode)->first();
                if ($item === null) {
                    $result['errors'][] = "Row {$line}: item_code \"{$itemCode}\" not found";
                    $result['skipped']++;

                    continue;
                }
            }

            if ($product === '' && $item !== null) {
                $product = $item->description ?? $item->name ?? $item->code;
            }

            if ($product === '') {
                $result['errors'][] = "Row {$line}: product or item_code is required";
                $result['skipped']++;

                continue;
            }

            $clientId = null;
            if ($clientName !== '') {
                $client = Client::query()->where('name', $clientName)->first();
                if ($client === null) {
                    $result['errors'][] = "Row {$line}: client \"{$clientName}\" not found";
                    $result['skipped']++;

                    continue;
                }
                $clientId = $client->id;
            }

            if (! in_array($priority, ['normal', 'urgent'], true)) {
                $priority = 'normal';
            }

            if (! in_array($statusRaw, ['draft', 'pending'], true)) {
                $statusRaw = 'pending';
            }

            $stages = $this->stageNamesFromWorkflow($workflow);

            $payload = [
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'client_id' => $clientId,
                'client_name' => $clientName !== '' ? $clientName : '—',
                'item_id' => $item?->id,
                'product' => $product,
                'item_code' => $item?->code ?? ($itemCode !== '' ? $itemCode : null),
                'unit' => $item?->unit ?? $unit,
                'quantity' => $quantity,
                'pr_number' => $this->nullable($prNumber),
                'route_card_no' => $this->nullable($routeCardNo),
                'remarks' => $this->nullable($remarks),
                'stages' => $stages,
                'is_job_work' => collect($stages)->contains(
                    fn (array $stage) => str_contains(strtolower($stage['name']), 'job work'),
                ),
                'supervisor_status' => $statusRaw,
                'priority' => $priority,
                'flow_step' => 'workflow',
                'created_by' => $createdBy,
            ];

            $existing = null;
            if ($routeCardNo !== '') {
                $existing = WorkOrder::query()->where('route_card_no', $routeCardNo)->first();
            }

            if ($existing !== null) {
                if ($existing->supervisor_status !== 'draft') {
                    $result['errors'][] = "Row {$line}: route_card_no \"{$routeCardNo}\" already exists";
                    $result['skipped']++;

                    continue;
                }

                $existing->update($payload);
                if ($statusRaw === 'pending') {
                    AppNotificationService::workflowAssigned($existing->fresh());
                }
                $result['updated']++;

                continue;
            }

            $order = WorkOrder::create($payload);
            if ($statusRaw === 'pending') {
                AppNotificationService::workflowAssigned($order);
            }
            $result['created']++;
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importFinishedGoods(array $rows): array
    {
        $result = $this->emptyResult();

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $product = $this->sanitizeImportField(
                $this->rowValue($row, ['product', 'product_name', 'name']),
            );
            $batchNumber = $this->sanitizeImportField(
                $this->rowValue($row, ['batch_number', 'batch', 'batch_no']),
            );
            if ($batchNumber === '') {
                $batchNumber = $this->sanitizeImportField($this->rowValue($row, ['sku']));
            }

            if ($product === '' && $batchNumber === '') {
                $result['skipped']++;

                continue;
            }

            if ($product === '') {
                $result['errors'][] = "Row {$line}: product is required";
                $result['skipped']++;

                continue;
            }

            if ($batchNumber === '') {
                $batchNumber = $this->autoFinishedGoodBatchNumber($product);
            }

            $quantity = max(0, (int) preg_replace(
                '/[^\d-]/',
                '',
                $this->rowValue($row, ['quantity', 'qty', 'q_ty', 'stock_qty']),
            ));
            $stockRaw = $this->rowValue($row, ['stock']);
            $stock = $stockRaw !== ''
                ? max(0, (int) preg_replace('/[^\d-]/', '', $stockRaw))
                : $quantity;
            $dispatched = max(
                0,
                (int) preg_replace('/[^\d-]/', '', $this->rowValue($row, ['dispatched'])),
            );

            $productionDate = $this->parseImportDate(
                $this->rowValue($row, ['production_date', 'production date']),
            )?->format('Y-m-d') ?? now()->toDateString();

            $payload = [
                'product_name' => $product,
                'sku' => $this->nullable($this->sanitizeImportField($this->rowValue($row, ['sku']))),
                'batch_number' => $batchNumber,
                'quantity' => $quantity,
                'stock' => $stock,
                'dispatched' => $dispatched,
                'unit' => $this->sanitizeImportField($this->rowValue($row, ['unit'])) ?: 'pcs',
                'location' => $this->nullable($this->sanitizeImportField($this->rowValue($row, ['location']))),
                'warehouse' => $this->nullable($this->sanitizeImportField($this->rowValue($row, ['warehouse']))),
                'status' => $this->nullable($this->sanitizeImportField($this->rowValue($row, ['status'])))
                    ?? 'In Stock',
                'quality_status' => $this->nullable(
                    $this->sanitizeImportField($this->rowValue($row, ['quality_status'])),
                ) ?? 'approved',
                'production_date' => $productionDate,
                'pr_number' => $this->nullable($this->rowValue($row, ['pr_number'])),
                'workflow' => $this->nullable($this->rowValue($row, ['workflow'])),
                'flow_step' => 'finished_goods',
            ];

            $validator = Validator::make($payload, [
                'product_name' => 'required|string|max:255',
                'sku' => 'nullable|string|max:255',
                'batch_number' => 'required|string|max:255',
                'quantity' => 'nullable|integer|min:0',
                'stock' => 'nullable|integer|min:0',
                'dispatched' => 'nullable|integer|min:0',
                'unit' => 'nullable|string|max:50',
                'location' => 'nullable|string|max:255',
                'warehouse' => 'nullable|string|max:255',
                'status' => 'nullable|string|max:100',
                'quality_status' => 'nullable|string|max:100',
                'production_date' => 'nullable|date',
                'pr_number' => 'nullable|string|max:255',
                'workflow' => 'nullable|string|max:255',
                'flow_step' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();
            $data['quality_status'] = $data['quality_status'] ?? 'approved';
            $data['status'] = $data['status'] ?? 'In Stock';
            $existing = FinishedGood::query()
                ->where('product_name', $product)
                ->first();

            if ($existing === null) {
                $existing = FinishedGood::query()
                    ->where('batch_number', $batchNumber)
                    ->first();
            }

            try {
                if ($existing !== null) {
                    $existing->update($data);
                    InventoryController::syncFromFinishedGood($existing->fresh());
                    $result['updated']++;
                } else {
                    $created = FinishedGood::query()->create($data);
                    InventoryController::syncFromFinishedGood($created->fresh());
                    $result['created']++;
                }
            } catch (\Throwable $exception) {
                $result['errors'][] = "Row {$line}: ".$exception->getMessage();
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function autoFinishedGoodBatchNumber(string $product): string
    {
        $slug = Str::upper(Str::slug(trim($product), '-'));
        $slug = Str::limit($slug, 48, '');

        if ($slug === '') {
            return 'AUTO-'.Str::upper(Str::random(10));
        }

        return 'AUTO-'.$slug;
    }

    private function sanitizeImportField(string $value): string
    {
        $value = trim(str_replace("\n", ' ', $value));
        if ($value === '') {
            return '';
        }

        $normalized = strtolower(rtrim($value, '.'));

        if (in_array($normalized, [
            'no',
            'n/a',
            'na',
            'nil',
            'null',
            'none',
            '-',
            '—',
            '___',
            '.',
        ], true)) {
            return '';
        }

        return $value;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importGauges(array $rows): array
    {
        $result = $this->emptyResult();

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $instrumentId = $this->rowValue($row, ['ident_no', 'ident_no_', 'instrument_id', 'ident']);
            $name = $this->rowValue($row, ['instrument_name', 'name', 'instrument']);

            if ($instrumentId === '' && $name === '') {
                $result['skipped']++;

                continue;
            }

            if ($this->isHeaderLikeGaugeIdent($instrumentId) || $this->isHeaderLikeGaugeIdent($name)) {
                $result['skipped']++;

                continue;
            }

            if ($instrumentId === '') {
                $result['errors'][] = "Row {$line}: ident_no is required";
                $result['skipped']++;

                continue;
            }

            if ($name === '') {
                $result['errors'][] = "Row {$line}: instrument_name is required";
                $result['skipped']++;

                continue;
            }

            $calStatusRaw = $this->rowValue($row, ['cal_status', 'calibration_status', 'status']);
            [$operationalStatus, $calibrationStatus] = $this->normalizeGaugeImportStatus($calStatusRaw);

            $nextDue = $this->parseImportDate(
                $this->rowValue($row, ['cal_due_date', 'next_calibration_date', 'next_due_date']),
            );
            $lastCal = $this->parseImportDate(
                $this->rowValue($row, ['cal_date', 'last_calibration_date']),
            );
            $trigger = $this->parseImportDate(
                $this->rowValue($row, ['trigger_date', 'calibration_trigger_date']),
            );

            if ($trigger === null && $nextDue !== null) {
                $trigger = $nextDue->copy()->subDays(30);
            }

            $freqMonths = $this->parseCalibrationFrequencyMonths(
                $this->rowValue($row, ['cal_freq', 'calibration_frequency_months', 'interval_months']),
            );

            if ($freqMonths === null && $lastCal !== null && $nextDue !== null) {
                $freqMonths = max(1, $lastCal->diffInMonths($nextDue));
            }

            $freqMonths ??= 12;

            $payload = [
                'instrument_id' => $instrumentId,
                'name' => $name,
                'type' => $this->nullable($this->rowValue($row, ['type', 'category'])),
                'manufacturer' => $this->nullable($this->rowValue($row, ['make', 'manufacturer'])),
                'location' => $this->nullable($this->rowValue($row, ['location'])),
                'measuring_range' => $this->nullable($this->rowValue($row, ['range', 'measuring_range'])),
                'least_count' => $this->nullableGaugeField($this->rowValue($row, [
                    'least_count_range',
                    'least_count',
                    'least_count/range',
                ])),
                'calibration_agency' => $this->nullable($this->rowValue($row, [
                    'cal_agency',
                    'calibration_agency',
                ])),
                'calibration_frequency_months' => $freqMonths,
                'certificate_number' => $this->nullable($this->rowValue($row, [
                    'certificate_no',
                    'certificate_number',
                ])),
                'status' => $operationalStatus,
                'calibration_status' => $calibrationStatus,
                'last_calibration_date' => $lastCal?->format('Y-m-d'),
                'calibration_trigger_date' => $trigger?->format('Y-m-d'),
                'next_calibration_date' => $nextDue?->format('Y-m-d'),
            ];

            $validator = Validator::make($payload, [
                'instrument_id' => 'required|string|max:255',
                'name' => 'required|string|max:255',
                'type' => 'nullable|string|max:255',
                'manufacturer' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'measuring_range' => 'nullable|string|max:255',
                'least_count' => 'nullable|string|max:255',
                'calibration_agency' => 'nullable|string|max:255',
                'calibration_frequency_months' => 'nullable|integer|min:1|max:120',
                'certificate_number' => 'nullable|string|max:255',
                'status' => 'nullable|string|max:255',
                'calibration_status' => 'nullable|string|max:255',
                'last_calibration_date' => 'nullable|date',
                'calibration_trigger_date' => 'nullable|date',
                'next_calibration_date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();
                $result['skipped']++;

                continue;
            }

            $data = $validator->validated();
            $existing = Gauge::query()->where('instrument_id', $instrumentId)->first();

            if ($existing !== null) {
                $existing->update($data);
                $result['updated']++;
            } else {
                Gauge::create($data);
                $result['created']++;
            }
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeGaugeImportStatus(string $raw): array
    {
        $normalized = strtoupper(preg_replace('/\s+/', ' ', trim(str_replace("\n", ' ', $raw))) ?? '');

        if ($normalized === '' || str_contains($normalized, 'NEWLY ADDED')) {
            return ['active', 'valid'];
        }

        if (str_contains($normalized, 'UNDER MAINTENANCE')) {
            return ['under_maintenance', 'valid'];
        }

        $calibrationStatus = match (true) {
            str_contains($normalized, 'OVERDUE') => 'overdue',
            str_contains($normalized, 'DUE SOON') => 'due_soon',
            str_contains($normalized, 'FAIL') => 'failed',
            str_contains($normalized, 'DONE'),
            str_contains($normalized, 'VALID') => 'valid',
            default => 'valid',
        };

        return ['active', $calibrationStatus];
    }

    private function parseCalibrationFrequencyMonths(string $value): ?int
    {
        $value = trim(str_replace("\n", ' ', $value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*YEAR/i', $value, $matches) === 1) {
            return min(120, max(1, (int) $matches[1] * 12));
        }

        if (preg_match('/(\d+)\s*MONTH/i', $value, $matches) === 1) {
            return min(120, max(1, (int) $matches[1]));
        }

        if (preg_match('/(\d+)/', $value, $matches) === 1) {
            $number = (int) $matches[1];

            return min(120, max(1, $number <= 10 ? $number * 12 : $number));
        }

        return null;
    }

    private function parseImportDate(string $value): ?Carbon
    {
        $value = trim(str_replace("\n", ' ', $value));
        if ($value === '' || $value === '___' || $value === '-') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) {
                return Carbon::create(1899, 12, 30)->addDays((int) round($serial));
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableGaugeField(?string $value): ?string
    {
        $value = trim(str_replace("\n", ' ', (string) $value));
        if ($value === '' || $value === '___' || $value === '-') {
            return null;
        }

        return $value;
    }

    private function isHeaderLikeGaugeIdent(string $value): bool
    {
        $normalized = strtolower(trim(str_replace("\n", ' ', $value)));

        return in_array($normalized, [
            'ident_no',
            'ident. no.',
            'ident no',
            'instrument_name',
            'instrument name',
            'sl_no',
            'sl no',
        ], true);
    }

    /**
     * Stage names only — no machine / work-route columns from Excel.
     *
     * @return list<array{name: string, order: int}>
     */
    private function stageNamesFromWorkflow(Workflow $workflow): array
    {
        $stages = [];
        foreach (array_values($workflow->stages ?? []) as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $name = trim((string) ($stage['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $stages[] = [
                'name' => $name,
                'order' => (int) ($stage['order'] ?? ($index + 1)),
            ];
        }

        return $stages;
    }

    private function resolveWorkflowForImport(string $workflowName): ?Workflow
    {
        $name = trim($workflowName);
        if ($name === '') {
            $name = 'Standard Manufacturing';
        }

        $existing = Workflow::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (Workflow::query()->exists()) {
            return null;
        }

        return Workflow::create([
            'name' => $name,
            'stages' => $this->defaultWorkflowStages(),
            'is_active' => true,
        ]);
    }

    /**
     * @return list<array{name: string, order: int}>
     */
    private function defaultWorkflowStages(): array
    {
        return [
            ['name' => 'Raw Material', 'order' => 1],
            ['name' => 'Cutting', 'order' => 2],
            ['name' => 'Machining', 'order' => 3],
            ['name' => 'Assembly', 'order' => 4],
            ['name' => 'Quality Check', 'order' => 5],
            ['name' => 'Packaging', 'order' => 6],
            ['name' => 'Job Work', 'order' => 7],
            ['name' => 'Finished Goods', 'order' => 8],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $keys
     */
    private function rowValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($row[$key])) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function parseOptionalNumber(string $value): ?float
    {
        $value = trim(str_replace(',', '', $value));
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function isHeaderLikeItemCode(string $code): bool
    {
        $normalized = strtolower(trim($code));

        return in_array($normalized, [
            'item_code',
            'item code',
            'code',
            'sku',
            's.no',
            'sno',
            's_no',
        ], true);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function emptyResult(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseBool(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['1', 'yes', 'true', 'active', 'y'], true);
    }

    private function normalizeStatus(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['inactive', 'no', '0', 'false'], true) ? 'inactive' : 'active';
    }

    private function normalizeItemCategory(string $value): string
    {
        $value = strtolower(trim(str_replace([' ', '-'], '_', $value)));

        if (in_array($value, ['raw_material', 'rawmaterial', 'raw', 'rm'], true)) {
            return 'raw_material';
        }

        return 'product';
    }
}
