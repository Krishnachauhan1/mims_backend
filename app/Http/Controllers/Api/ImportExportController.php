<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityImportService;
use App\Services\SpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportExportController extends Controller
{
    private const ENTITIES = ['users', 'vendors', 'clients', 'inventory', 'items'];

    public function __construct(
        private SpreadsheetService $spreadsheet,
        private EntityImportService $importer,
    ) {}

    public function template(string $entity): StreamedResponse|JsonResponse
    {
        if (! $this->isValidEntity($entity)) {
            return response()->json(['message' => 'Invalid entity'], 404);
        }

        $definition = $this->spreadsheet->templateDefinition($entity);

        return $this->spreadsheet->csvDownload(
            "{$entity}_template.csv",
            $definition['headers'],
            [$definition['sample']],
        );
    }

    public function export(string $entity): StreamedResponse|JsonResponse
    {
        if (! $this->isValidEntity($entity)) {
            return response()->json(['message' => 'Invalid entity'], 404);
        }

        $definition = $this->spreadsheet->templateDefinition($entity);
        $rows = $this->importer->exportRows($entity);

        return $this->spreadsheet->csvDownload(
            "{$entity}_export.csv",
            $definition['headers'],
            $rows,
        );
    }

    public function import(Request $request, string $entity): JsonResponse
    {
        if (! $this->isValidEntity($entity)) {
            return response()->json(['message' => 'Invalid entity'], 404);
        }

        $request->validate([
            'file' => 'required|file|max:5120',
        ]);

        try {
            $parsed = $this->spreadsheet->parseUpload($request->file('file'));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($parsed['rows'] === []) {
            return response()->json(['message' => 'The uploaded file has no data rows'], 422);
        }

        $result = $this->importer->import($entity, $parsed['rows']);

        return response()->json([
            'message' => 'Import completed',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => array_slice($result['errors'], 0, 20),
            'error_count' => count($result['errors']),
        ]);
    }

    private function isValidEntity(string $entity): bool
    {
        return in_array($entity, self::ENTITIES, true);
    }
}
