<?php

namespace App\Services;

use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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
                'address' => $vendor->address ?? '',
                'city' => $vendor->city ?? '',
                'status' => $vendor->status ?? 'active',
            ])->all(),
            'clients' => Client::query()->latest()->get()->map(fn (Client $client) => [
                'name' => $client->name,
                'contact_person' => $client->contact_person ?? '',
                'email' => $client->email ?? '',
                'phone' => $client->phone ?? '',
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
                ->where('category', 'product')
                ->orderBy('code')
                ->get()
                ->map(fn (Item $item) => [
                    'item_code' => $item->code,
                    'item_description' => $item->description ?? '',
                    'moq' => $item->min_stock_alert ?? 0,
                    'unit' => $item->unit ?? 'pcs',
                    'status' => $item->status ?? 'active',
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
                'address' => $this->nullable($row['address'] ?? null),
                'city' => $this->nullable($row['city'] ?? null),
                'status' => $this->normalizeStatus($row['status'] ?? 'active'),
            ];

            $validator = Validator::make($payload, [
                'name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
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
            $unit = $this->rowValue($row, ['unit']) ?: 'pcs';
            $status = $this->normalizeStatus($this->rowValue($row, ['status']) ?: 'active');

            $existing = Item::query()->where('code', $code)->first();

            $payload = [
                'code' => $code,
                'name' => $code,
                'description' => $description !== '' ? $description : null,
                'unit' => $unit,
                'min_stock_alert' => $minStockAlert,
                'category' => 'product',
                'status' => $status,
            ];

            $validator = Validator::make($payload, [
                'code' => 'required|string|max:255|unique:items,code,'.($existing?->id ?? 'NULL'),
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:255',
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
}
