<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProfileController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->format($this->resolve()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $profile = CompanyProfile::query()->updateOrCreate(['id' => 1], $data);

        return response()->json([
            'success' => true,
            'message' => 'Company profile saved',
            'data' => $this->format($profile),
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $profile = $this->resolve();
        $profile->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Company profile updated',
            'data' => $this->format($profile->fresh()),
        ]);
    }

    private function resolve(): CompanyProfile
    {
        return CompanyProfile::query()->firstOrCreate(
            ['id' => 1],
            ['company_name' => config('app.name')],
        );
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_name' => 'nullable|string|max:255',
            'gst' => 'nullable|string|max:100',
            'gst_number' => 'nullable|string|max:100',
            'addresses' => 'nullable|array',
            'addresses.*' => 'nullable|string|max:500',
            'contacts' => 'nullable|array',
            'contacts.*.name' => 'nullable|string|max:255',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.phone' => 'nullable|string|max:30',
        ]);

        return [
            'company_name' => $data['company_name'] ?? null,
            'gst_number' => $data['gst_number'] ?? $data['gst'] ?? null,
            'addresses' => collect($data['addresses'] ?? [])
                ->map(fn ($address) => trim((string) $address))
                ->filter()
                ->values()
                ->all(),
            'contacts' => collect($data['contacts'] ?? [])
                ->map(fn ($contact) => [
                    'name' => trim((string) ($contact['name'] ?? '')),
                    'email' => trim((string) ($contact['email'] ?? '')),
                    'phone' => trim((string) ($contact['phone'] ?? '')),
                ])
                ->filter(fn (array $contact) => $contact['name'] !== ''
                    || $contact['email'] !== ''
                    || $contact['phone'] !== '')
                ->values()
                ->all(),
        ];
    }

    private function format(CompanyProfile $profile): array
    {
        return [
            'company_name' => $profile->company_name,
            'gst' => $profile->gst_number,
            'gst_number' => $profile->gst_number,
            'addresses' => $profile->addresses ?? [],
            'contacts' => $profile->contacts ?? [],
        ];
    }
}
