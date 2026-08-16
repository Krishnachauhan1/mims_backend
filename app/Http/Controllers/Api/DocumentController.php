<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Document::query()->latest();

        if ($request->filled('module')) {
            $query->where('module', $request->string('module'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->string('entity_id'));
        }

        $documents = $query->get()->map(fn (Document $document) => $this->format($document));

        return response()->json($documents);
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
            'entity_id' => $data['entity_id'],
            'label' => $data['label'] ?? $uploaded->getClientOriginalName(),
            'file_name' => $uploaded->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $uploaded->getMimeType() ?: 'application/pdf',
            'size_bytes' => $uploaded->getSize() ?: 0,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json($this->format($document), 201);
    }

    public function download(Document $document): BinaryFileResponse|JsonResponse
    {
        if (! Storage::exists($document->file_path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download(
            Storage::path($document->file_path),
            $document->file_name,
            ['Content-Type' => $document->mime_type],
        );
    }

    public function destroy(Document $document): JsonResponse
    {
        if (Storage::exists($document->file_path)) {
            Storage::delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    private function format(Document $document): array
    {
        return [
            'id' => $document->id,
            'module' => $document->module,
            'entity_id' => $document->entity_id,
            'label' => $document->label,
            'file_name' => $document->file_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'size_kb' => (int) round($document->size_bytes / 1024),
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'download_url' => url("/api/documents/{$document->id}/download"),
        ];
    }
}
