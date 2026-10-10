<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Gauge;
use App\Models\GaugeCalibration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class GaugeController extends Controller
{
    public function index(): JsonResponse
    {
        $gauges = Gauge::with('calibrations')->latest()->get()->map(
            fn (Gauge $gauge) => $this->format($gauge),
        );

        return response()->json($gauges);
    }

    public function store(Request $request): JsonResponse
    {
        $gauge = Gauge::create($this->validatedPayload($request));

        return response()->json($this->format($gauge->fresh('calibrations')), 201);
    }

    public function update(Request $request, Gauge $gauge): JsonResponse
    {
        $gauge->update($this->validatedPayload($request, $gauge));

        return response()->json($this->format($gauge->fresh('calibrations')));
    }

    public function destroy(Gauge $gauge): JsonResponse
    {
        $gauge->delete();

        return response()->json(['message' => 'Gauge deleted']);
    }

    public function updateMaintenance(Request $request, Gauge $gauge): JsonResponse
    {
        $data = $request->validate([
            'under_maintenance' => 'required|boolean',
        ]);

        $gauge->update([
            'status' => $data['under_maintenance'] ? 'under_maintenance' : 'active',
        ]);

        return response()->json($this->format($gauge->fresh('calibrations')));
    }

    public function storeCalibration(Request $request, Gauge $gauge): JsonResponse
    {
        $data = $request->validate([
            'calibration_date' => 'required|date',
            'performed_by' => 'nullable|string|max:255',
            'result' => 'required|string|in:pass,fail',
            'notes' => 'nullable|string',
            'certificate_ref' => 'nullable|string|max:255',
            'certificate' => 'nullable|string|max:255',
            'certificate_size_kb' => 'nullable|integer|min:0',
        ]);

        $intervalMonths = $this->intervalMonthsFor($gauge);
        $nextDue = $data['result'] === 'pass'
            ? Carbon::parse($data['calibration_date'])->addMonths($intervalMonths)->format('Y-m-d')
            : $gauge->next_calibration_date?->format('Y-m-d');
        $triggerDate = $data['result'] === 'pass' && $nextDue !== null
            ? $this->defaultTriggerDate(Carbon::parse($nextDue))
            : $gauge->calibration_trigger_date?->format('Y-m-d');

        $certificateRef = $data['certificate_ref'] ?? $data['certificate'] ?? null;

        $calibration = GaugeCalibration::create([
            'gauge_id' => $gauge->id,
            'calibration_date' => $data['calibration_date'],
            'next_due_date' => $nextDue,
            'performed_by' => $data['performed_by'] ?? null,
            'result' => $data['result'],
            'certificate_ref' => $certificateRef,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($request->hasFile('certificate_file')) {
            $this->storeCalibrationCertificate($request, $gauge, $calibration, $certificateRef);
        }

        $gaugeUpdates = [
            'last_calibration_date' => $data['calibration_date'],
            'next_calibration_date' => $nextDue,
            'calibration_status' => $data['result'] === 'pass' ? 'valid' : 'failed',
            'status' => $data['result'] === 'pass' ? 'active' : 'under_maintenance',
        ];
        if ($certificateRef !== null && trim($certificateRef) !== '') {
            $gaugeUpdates['certificate_number'] = trim($certificateRef);
        }
        if ($triggerDate !== null) {
            $gaugeUpdates['calibration_trigger_date'] = $triggerDate;
        }
        $gauge->update($gaugeUpdates);

        return response()->json($this->format($gauge->fresh('calibrations')));
    }

    public function attachCalibrationCertificate(
        Request $request,
        Gauge $gauge,
        GaugeCalibration $calibration,
    ): JsonResponse {
        if ($calibration->gauge_id !== $gauge->id) {
            return response()->json(['message' => 'Calibration not found for this instrument'], 404);
        }

        $request->validate([
            'certificate_file' => 'required|file|mimes:pdf|max:10240',
        ]);

        $ref = $calibration->certificate_ref;
        if ($ref === null || trim($ref) === '') {
            $ref = $request->file('certificate_file')->getClientOriginalName();
            $calibration->certificate_ref = $ref;
        }

        $this->storeCalibrationCertificate($request, $gauge, $calibration, $ref);

        return response()->json($this->format($gauge->fresh('calibrations')));
    }

    /**
     * Link an existing document row to a calibration (JSON only — avoids WAF on multipart /certificate).
     */
    public function linkCalibrationDocument(
        Request $request,
        Gauge $gauge,
        GaugeCalibration $calibration,
    ): JsonResponse {
        if ($calibration->gauge_id !== $gauge->id) {
            return response()->json(['message' => 'Calibration not found for this instrument'], 404);
        }

        $data = $request->validate([
            'document_id' => 'required|integer|exists:documents,id',
        ]);

        $document = Document::query()->findOrFail($data['document_id']);
        if ($document->module !== 'gauge') {
            return response()->json(['message' => 'Document is not a gauge attachment'], 422);
        }

        $allowedEntityIds = collect([
            $gauge->instrument_id,
            (string) $gauge->id,
        ])->filter()->unique()->values()->all();

        if (! in_array($document->entity_id, $allowedEntityIds, true)) {
            return response()->json(['message' => 'Document does not belong to this instrument'], 422);
        }

        $calibration->document_id = $document->id;
        if ($calibration->certificate_ref === null || trim((string) $calibration->certificate_ref) === '') {
            $calibration->certificate_ref = $document->file_name;
        }
        $calibration->save();

        return response()->json($this->format($gauge->fresh('calibrations')));
    }

    /**
     * Remove certificate file/link from a calibration (does not delete the calibration row).
     */
    public function clearCalibrationCertificate(
        Gauge $gauge,
        GaugeCalibration $calibration,
    ): JsonResponse {
        if ($calibration->gauge_id !== $gauge->id) {
            return response()->json(['message' => 'Calibration not found for this instrument'], 404);
        }

        if ($calibration->document_id !== null) {
            $document = Document::query()->find($calibration->document_id);
            if ($document !== null) {
                if (Storage::exists($document->file_path)) {
                    Storage::delete($document->file_path);
                }
                $document->delete();
            }
        }

        $calibration->document_id = null;
        $calibration->certificate_ref = null;
        $calibration->save();

        return response()->json($this->format($gauge->fresh('calibrations')));
    }

    private function storeCalibrationCertificate(
        Request $request,
        Gauge $gauge,
        GaugeCalibration $calibration,
        ?string $certificateRef,
    ): void {
        $uploaded = $request->file('certificate_file');
        if ($uploaded === null) {
            return;
        }

        $extension = strtolower($uploaded->getClientOriginalExtension() ?: 'pdf');
        if ($extension !== 'pdf') {
            return;
        }

        $storedName = uniqid('doc_', true).'.'.$extension;
        $path = $uploaded->storeAs('documents', $storedName);

        $fileName = $certificateRef !== null && trim($certificateRef) !== ''
            ? trim($certificateRef)
            : $uploaded->getClientOriginalName();

        if ($calibration->document_id !== null) {
            $existing = Document::query()->find($calibration->document_id);
            if ($existing !== null && Storage::exists($existing->file_path)) {
                Storage::delete($existing->file_path);
            }
            $existing?->delete();
        }

        $document = Document::create([
            'module' => 'gauge',
            'entity_id' => $gauge->instrument_id,
            'label' => 'Calibration certificate',
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $uploaded->getMimeType() ?: 'application/pdf',
            'size_bytes' => $uploaded->getSize() ?: 0,
            'user_id' => $request->user()?->id,
        ]);

        $calibration->document_id = $document->id;
        if ($calibration->certificate_ref === null || trim((string) $calibration->certificate_ref) === '') {
            $calibration->certificate_ref = $fileName;
        }
        $calibration->save();
    }

    private function validatedPayload(Request $request, ?Gauge $gauge = null): array
    {
        $data = $request->validate([
            'instrument_id' => 'required|string|max:255|unique:gauges,instrument_id,'.($gauge?->id ?? 'NULL'),
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'make' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'measuring_range' => 'nullable|string|max:255',
            'range' => 'nullable|string|max:255',
            'least_count' => 'nullable|string|max:255',
            'calibration_agency' => 'nullable|string|max:255',
            'cal_agency' => 'nullable|string|max:255',
            'certificate_number' => 'nullable|string|max:255',
            'certificate_no' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'last_calibration_date' => 'nullable|date',
            'calibration_trigger_date' => 'nullable|date',
            'trigger_date' => 'nullable|date',
            'next_due_date' => 'nullable|date',
            'next_calibration_date' => 'nullable|date',
            'interval_months' => 'nullable|integer|min:1|max:120',
            'calibration_frequency_months' => 'nullable|integer|min:1|max:120',
        ]);

        $intervalMonths = (int) (
            $data['calibration_frequency_months']
            ?? $data['interval_months']
            ?? $gauge?->calibration_frequency_months
            ?? 12
        );
        $intervalMonths = max(1, min(120, $intervalMonths));

        $nextDueRaw = $data['next_due_date'] ?? $data['next_calibration_date'] ?? null;
        $nextDue = $nextDueRaw !== null ? Carbon::parse($nextDueRaw) : null;
        $triggerRaw = $data['calibration_trigger_date'] ?? $data['trigger_date'] ?? null;
        $triggerDate = $triggerRaw !== null
            ? Carbon::parse($triggerRaw)
            : ($nextDue !== null ? $this->defaultTriggerDate($nextDue) : null);

        $certificateNumber = trim((string) (
            $data['certificate_number']
            ?? $data['certificate_no']
            ?? $gauge?->certificate_number
            ?? ''
        ));

        return [
            'instrument_id' => $data['instrument_id'],
            'name' => $data['name'],
            'type' => $data['category'] ?? $data['type'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? $data['make'] ?? null,
            'location' => $data['location'] ?? null,
            'measuring_range' => $data['measuring_range'] ?? $data['range'] ?? null,
            'least_count' => $data['least_count'] ?? null,
            'calibration_agency' => $data['calibration_agency'] ?? $data['cal_agency'] ?? null,
            'calibration_frequency_months' => $intervalMonths,
            'status' => $data['status'] ?? ($gauge?->status ?? 'active'),
            'last_calibration_date' => $data['last_calibration_date'] ?? null,
            'calibration_trigger_date' => $triggerDate?->format('Y-m-d'),
            'next_calibration_date' => $nextDue?->format('Y-m-d'),
            'certificate_number' => $certificateNumber !== '' ? $certificateNumber : null,
            'calibration_status' => $gauge?->calibration_status ?? 'valid',
        ];
    }

    private function format(Gauge $gauge): array
    {
        $latestCertificate = $gauge->certificate_number
            ?: $gauge->calibrations->sortByDesc('calibration_date')->first()?->certificate_ref;

        $triggerDate = $gauge->calibration_trigger_date
            ?? ($gauge->next_calibration_date !== null
                ? $this->defaultTriggerDate($gauge->next_calibration_date)
                : null);

        return [
            'id' => $gauge->id,
            'instrument_id' => $gauge->instrument_id,
            'ident_no' => $gauge->instrument_id,
            'name' => $gauge->name,
            'instrument_name' => $gauge->name,
            'type' => $gauge->type,
            'category' => $gauge->type,
            'manufacturer' => $gauge->manufacturer,
            'make' => $gauge->manufacturer,
            'location' => $gauge->location,
            'measuring_range' => $gauge->measuring_range,
            'range' => $gauge->measuring_range,
            'least_count' => $gauge->least_count,
            'calibration_agency' => $gauge->calibration_agency,
            'cal_agency' => $gauge->calibration_agency,
            'certificate_number' => $latestCertificate,
            'certificate_no' => $latestCertificate,
            'status' => $gauge->status,
            'calibration_status' => $gauge->calibration_status,
            'cal_status' => $this->formatCalibrationStatusLabel($gauge),
            'last_calibration_date' => $gauge->last_calibration_date?->format('Y-m-d'),
            'cal_date' => $gauge->last_calibration_date?->format('Y-m-d'),
            'calibration_trigger_date' => $triggerDate?->format('Y-m-d'),
            'trigger_date' => $triggerDate?->format('Y-m-d'),
            'next_calibration_date' => $gauge->next_calibration_date?->format('Y-m-d'),
            'next_due_date' => $gauge->next_calibration_date?->format('Y-m-d'),
            'cal_due_date' => $gauge->next_calibration_date?->format('Y-m-d'),
            'interval_months' => $this->intervalMonthsFor($gauge),
            'calibration_frequency_months' => $this->intervalMonthsFor($gauge),
            'cal_freq' => $this->intervalMonthsFor($gauge),
            'calibrations' => $gauge->calibrations
                ->sortByDesc('calibration_date')
                ->values()
                ->map(fn (GaugeCalibration $calibration) => [
                    'id' => $calibration->id,
                    'calibration_date' => $calibration->calibration_date?->format('Y-m-d'),
                    'date' => $calibration->calibration_date?->format('Y-m-d'),
                    'next_due_date' => $calibration->next_due_date?->format('Y-m-d'),
                    'performed_by' => $calibration->performed_by,
                    'result' => $calibration->result,
                    'certificate_ref' => $calibration->certificate_ref,
                    'certificate' => $calibration->certificate_ref,
                    'document_id' => $calibration->document_id,
                    'notes' => $calibration->notes,
                ])->all(),
        ];
    }

    private function intervalMonthsFor(Gauge $gauge): int
    {
        $stored = (int) ($gauge->calibration_frequency_months ?? 0);
        if ($stored >= 1) {
            return min(120, $stored);
        }

        if ($gauge->last_calibration_date !== null && $gauge->next_calibration_date !== null) {
            return max(
                1,
                $gauge->last_calibration_date->diffInMonths($gauge->next_calibration_date),
            );
        }

        return 12;
    }

    private function defaultTriggerDate(Carbon $dueDate): Carbon
    {
        return $dueDate->copy()->subDays(30);
    }

    private function formatCalibrationStatusLabel(Gauge $gauge): string
    {
        if ($gauge->status === 'under_maintenance') {
            return 'UNDER MAINTENANCE';
        }

        return match ($gauge->calibration_status) {
            'valid' => 'VALID',
            'failed' => 'FAILED',
            'due_soon' => 'DUE SOON',
            'overdue' => 'OVERDUE',
            default => strtoupper(str_replace('_', ' ', (string) $gauge->calibration_status)),
        };
    }
}
