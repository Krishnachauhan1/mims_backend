<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = Client::latest()->get()->map(
            fn (Client $client) => $this->format($client),
        );

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($this->validatedPayload($request));

        return response()->json($this->format($client), 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $client->update($this->validatedPayload($request, isUpdate: true));

        return response()->json($this->format($client->fresh()));
    }

    public function updateStatus(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:active,inactive',
        ]);

        $client->update(['status' => $data['status']]);

        return response()->json($this->format($client->fresh()));
    }

    private function validatedPayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'gst_number' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'total_orders' => 'nullable|integer|min:0',
            'last_order_date' => 'nullable|date',
            'items' => 'nullable|array',
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.default_qty' => 'nullable|integer|min:0',
        ];

        $data = $request->validate($rules);

        if (! array_key_exists('status', $data) && ! $isUpdate) {
            $data['status'] = 'active';
        }

        if (! array_key_exists('total_orders', $data) && ! $isUpdate) {
            $data['total_orders'] = 0;
        }

        return $data;
    }

    private function format(Client $client): array
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
}
