<?php

namespace App\Services;

class PoCheckinItemService
{
    /**
     * @param  array<int, array<string, mixed>>  $rawItems
     * @param  array<int, array<string, mixed>>|null  $existingItems
     * @return array<int, array<string, mixed>>
     */
    public function normalizeTrackedItems(array $rawItems, ?array $existingItems = null): array
    {
        $existingByName = [];
        foreach ($existingItems ?? [] as $existing) {
            if (! is_array($existing)) {
                continue;
            }
            $name = $existing['item_name'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $existingByName[trim($name)] = $this->normalizeSingleItem($existing);
            }
        }

        $items = [];
        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $name = $raw['item_name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $name = trim($name);
            $existing = $existingByName[$name] ?? null;
            $ordered = (int) round((float) (
                $raw['ordered_quantity']
                ?? $raw['quantity']
                ?? ($existing['ordered_quantity'] ?? 0)
            ));

            if ($ordered < 1) {
                continue;
            }

            $received = $existing !== null
                ? (int) ($existing['received_quantity'] ?? 0)
                : (int) ($raw['received_quantity'] ?? 0);

            $received = min($received, $ordered);
            $status = $existing['status'] ?? $raw['status'] ?? null;

            $items[] = $this->buildItem(
                $name,
                $ordered,
                $received,
                $raw['unit'] ?? ($existing['unit'] ?? 'pcs'),
                is_string($status) ? $status : null,
                is_array($existing)
                    ? (is_array($existing['receipt_history'] ?? null) ? $existing['receipt_history'] : [])
                    : (is_array($raw['receipt_history'] ?? null) ? $raw['receipt_history'] : []),
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeSingleItem(array $raw): array
    {
        $name = trim((string) ($raw['item_name'] ?? ''));
        $ordered = (int) round((float) ($raw['ordered_quantity'] ?? $raw['quantity'] ?? 0));
        $received = min((int) ($raw['received_quantity'] ?? 0), max($ordered, 0));

        return $this->buildItem(
            $name,
            max($ordered, $received),
            $received,
            $raw['unit'] ?? 'pcs',
            $raw['status'] ?? null,
            is_array($raw['receipt_history'] ?? null) ? $raw['receipt_history'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildItem(
        string $name,
        int $ordered,
        int $received,
        string $unit = 'pcs',
        ?string $status = null,
        array $receiptHistory = [],
    ): array {
        $received = min(max($received, 0), max($ordered, 0));
        $pending = max(0, $ordered - $received);
        $resolvedStatus = $status ?? $this->deriveItemStatus($ordered, $received, 'pending');

        if ($resolvedStatus === 'order_received' && $received < $ordered) {
            $resolvedStatus = $this->deriveItemStatus($ordered, $received, 'pending');
        }

        if ($received >= $ordered && $resolvedStatus === 'pending') {
            $resolvedStatus = 'checked_in';
        }

        return [
            'item_name' => $name,
            'ordered_quantity' => $ordered,
            'received_quantity' => $received,
            'pending_quantity' => $pending,
            'unit' => $unit,
            'status' => $resolvedStatus,
            'receipt_history' => array_values($receiptHistory),
        ];
    }

    public function deriveItemStatus(int $ordered, int $received, string $current = 'pending'): string
    {
        if ($current === 'order_received' && $received >= $ordered) {
            return 'order_received';
        }

        if ($received >= $ordered && $ordered > 0) {
            return 'checked_in';
        }

        if ($received > 0) {
            return 'partial';
        }

        return 'pending';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function derivePoPhase(array $items): string
    {
        if ($items === []) {
            return 'order_placed';
        }

        $statuses = array_map(
            fn (array $item) => $item['status'] ?? 'pending',
            $items,
        );

        if (count(array_filter($statuses, fn ($s) => $s === 'order_received')) === count($statuses)) {
            return 'order_received';
        }

        if (count(array_filter($statuses, fn ($s) => in_array($s, ['pending', 'partial'], true))) === 0) {
            return 'checked_in';
        }

        if (in_array('partial', $statuses, true)
            || (in_array('checked_in', $statuses, true) && in_array('pending', $statuses, true))
            || (in_array('order_received', $statuses, true) && count(array_unique($statuses)) > 1)) {
            return 'partial';
        }

        return 'order_placed';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function totalReceivedQuantity(array $items): int
    {
        return array_sum(array_map(
            fn (array $item) => (int) ($item['received_quantity'] ?? 0),
            $items,
        ));
    }

    /**
     * Normalize line items for check-out / dispatch (quantity = amount leaving store).
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array<int, array<string, mixed>>
     */
    public function normalizeCheckoutItems(array $rawItems): array
    {
        return array_values(array_map(function (array $item) {
            $qty = max(1, (int) round((float) (
                $item['quantity']
                ?? $item['ordered_quantity']
                ?? 0
            )));

            return $this->buildItem(
                trim((string) ($item['item_name'] ?? 'Item')),
                $qty,
                $qty,
                (string) ($item['unit'] ?? 'pcs'),
                'checked_in',
                is_array($item['dispatch_history'] ?? null) ? $item['dispatch_history'] : [],
            );
        }, array_filter($rawItems, fn ($raw) => is_array($raw))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function totalLineQuantity(array $items): int
    {
        return array_sum(array_map(
            fn (array $item) => (int) (
                $item['ordered_quantity']
                ?? $item['quantity']
                ?? 0
            ),
            $items,
        ));
    }
}
