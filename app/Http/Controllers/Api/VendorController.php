<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(): JsonResponse
    {
        $vendors = Vendor::latest()->get()->map(
            fn (Vendor $vendor) => $this->format($vendor),
        );

        return response()->json($vendors);
    }

    public function store(Request $request): JsonResponse
    {
        $vendor = Vendor::create($this->validatedPayload($request));

        return response()->json($this->format($vendor), 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($this->validatedPayload($request, isUpdate: true));

        return response()->json($this->format($vendor->fresh()));
    }

    public function updateStatus(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:active,inactive',
        ]);

        $vendor->update(['status' => $data['status']]);

        return response()->json($this->format($vendor->fresh()));
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
            'items.*.rate' => 'nullable|numeric|min:0',
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

    private function format(Vendor $vendor): array
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
}
