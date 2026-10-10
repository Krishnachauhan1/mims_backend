<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Gauge;
use App\Models\Item;
use App\Models\QualityInspection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('documents')) {
            return response()->json([
                'message' => 'Documents storage is not set up. Run: php artisan migrate --force',
            ], 503);
        }

        try {
            $query = Document::query()->latest();

            if ($request->filled('module')) {
                $query->where('module', (string) $request->query('module'));
            }

            if ($request->filled('entity_id')) {
                $this->applyEntityIdFilter(
                    $query,
                    (string) $request->query('module', ''),
                    (string) $request->query('entity_id'),
                );
            }

            $user = $request->user();
            $documents = [];

            foreach ($query->get() as $document) {
                try {
                    $documents[] = $this->format($document, $user);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return response()->json(array_values($documents));
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Could not load documents',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not load documents',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => 'required|string|max:100',
            'entity_id' => 'required|string|max:255',
            'label' => 'nullable|string|max:255',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower($uploaded->getClientOriginalExtension() ?: 'bin');
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(['message' => 'Only PDF or image files are allowed'], 422);
        }

        $storedName = uniqid('doc_', true).'.'.$extension;
        $path = $uploaded->storeAs('documents', $storedName);

        $document = Document::create([
            'module' => $data['module'],
            'entity_id' => $this->normalizeEntityId($data['module'], $data['entity_id']),
            'label' => $data['label'] ?? $uploaded->getClientOriginalName(),
            'file_name' => $uploaded->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $uploaded->getMimeType() ?: 'application/pdf',
            'size_bytes' => $uploaded->getSize() ?: 0,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json($this->format($document, $request->user()), 201);
    }

    /**
     * JSON/base64 upload — use when Hostinger ModSecurity blocks multipart POST /documents.
     */
    public function storeFromBase64(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => 'required|string|max:100',
            'entity_id' => 'required|string|max:255',
            'label' => 'nullable|string|max:255',
            'file_name' => 'required|string|max:255',
            'file_base64' => 'required|string|max:14500000',
            'mime_type' => 'nullable|string|max:100',
        ]);

        $originalName = basename(str_replace(["\0", '/', '\\'], '', $data['file_name']));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin');
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(['message' => 'Only PDF or image files are allowed'], 422);
        }

        $payload = preg_replace('/\s+/', '', $data['file_base64']);
        $raw = base64_decode((string) $payload, true);
        if ($raw === false || $raw === '') {
            return response()->json(['message' => 'Invalid file payload'], 422);
        }

        $maxBytes = 10240 * 1024;
        if (strlen($raw) > $maxBytes) {
            return response()->json(['message' => 'File too large (max 10 MB)'], 422);
        }

        $storedName = uniqid('doc_', true).'.'.$extension;
        $path = 'documents/'.$storedName;
        Storage::put($path, $raw);

        $mime = $data['mime_type'] ?? match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        $document = Document::create([
            'module' => $data['module'],
            'entity_id' => $this->normalizeEntityId($data['module'], $data['entity_id']),
            'label' => $data['label'] ?? $originalName,
            'file_name' => $originalName,
            'file_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => strlen($raw),
            'user_id' => $request->user()?->id,
        ]);

        return response()->json($this->format($document, $request->user()), 201);
    }

    public function download(Document $document): BinaryFileResponse|JsonResponse
    {
        try {
            if (! Storage::exists($document->file_path)) {
                return response()->json(['message' => 'File not found'], 404);
            }

            $path = Storage::path($document->file_path);

            if (! is_readable($path)) {
                return response()->json(['message' => 'File is not readable on server'], 404);
            }

            return response()->file($path, [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$this->safeDownloadFilename($document->file_name).'"',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Download failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function safeDownloadFilename(string $filename): string
    {
        $clean = trim(str_replace(["\r", "\n", '"'], '', $filename));

        return $clean !== '' ? $clean : 'document';
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->assertCanDeleteDocument($request->user(), $document);

        if (Storage::exists($document->file_path)) {
            Storage::delete($document->file_path);
        }

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted',
        ]);
    }

    private function format(Document $document, ?User $user = null): array
    {
        $sizeBytes = (int) ($document->size_bytes ?? 0);

        return [
            'id' => $document->id,
            'module' => $this->jsonSafeString($document->module),
            'entity_id' => $this->jsonSafeString($document->entity_id),
            'label' => $this->jsonSafeString($document->label),
            'file_name' => $this->jsonSafeString($document->file_name),
            'mime_type' => $this->jsonSafeString($document->mime_type ?: 'application/octet-stream'),
            'size_bytes' => $sizeBytes,
            'size_kb' => (int) round($sizeBytes / 1024),
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'download_url' => url("/api/documents/{$document->id}/download"),
            'can_delete' => $this->safeCanDeleteDocument($user, $document),
        ];
    }

    private function safeCanDeleteDocument(?User $user, Document $document): bool
    {
        try {
            return $this->canDeleteDocument($user, $document);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    private function applyEntityIdFilter($query, string $module, string $entityId): void
    {
        $entityId = trim($entityId);
        if ($entityId === '') {
            return;
        }

        if ($module === 'gauge') {
            $keys = collect([$entityId]);
            $gauge = Gauge::query()
                ->where('instrument_id', $entityId)
                ->orWhere('id', ctype_digit($entityId) ? (int) $entityId : -1)
                ->first();
            if ($gauge !== null) {
                $keys->push($gauge->instrument_id, (string) $gauge->id);
            }
            $query->whereIn('entity_id', $keys->filter()->unique()->values()->all());

            return;
        }

        if ($module !== 'item') {
            $query->where('entity_id', $entityId);

            return;
        }

        $keys = collect([$entityId]);

        if (ctype_digit($entityId)) {
            $item = Item::query()->find((int) $entityId);
            if ($item !== null && filled($item->code)) {
                $keys->push(trim((string) $item->code));
            }
        } else {
            $item = Item::query()->where('code', $entityId)->first();
            if ($item !== null) {
                $keys->push((string) $item->id);
            }
        }

        $query->whereIn('entity_id', $keys->filter()->unique()->values()->all());
    }

    private function normalizeEntityId(string $module, string $entityId): string
    {
        $entityId = trim($entityId);
        if ($module !== 'item' || $entityId === '') {
            return $entityId;
        }

        if (ctype_digit($entityId)) {
            return $entityId;
        }

        $item = Item::query()->where('code', $entityId)->first();

        return $item !== null ? (string) $item->id : $entityId;
    }

    private function jsonSafeString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $clean !== false ? $clean : preg_replace('/[^\x20-\x7E]/', '_', $value) ?? $value;
    }

    private function assertCanDeleteDocument(?User $user, Document $document): void
    {
        if ($this->canDeleteDocument($user, $document)) {
            return;
        }

        throw new HttpException(
            403,
            'QC completed — photos can only be deleted by admin',
        );
    }

    private function canDeleteDocument(?User $user, Document $document): bool
    {
        if ($document->module !== 'quality_inspection') {
            return true;
        }

        $inspection = QualityInspection::query()->find($document->entity_id);
        if ($inspection === null || $inspection->status === 'pending') {
            return true;
        }

        return $this->isAdminUser($user);
    }

    private function isAdminUser(?User $user): bool
    {
        return $user !== null && strcasecmp((string) $user->role, 'admin') === 0;
    }
}
