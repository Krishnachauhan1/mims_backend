<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetService
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function parseUpload(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->parseExcel($file);
        }

        return $this->parseCsvFile($file->getRealPath());
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    private function parseExcel(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Could not read Excel file: '.$exception->getMessage());
        }

        $sheet = $spreadsheet->getSheet(0);
        $matrix = $sheet->toArray(null, true, true, false);

        if ($matrix === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headerIndex = $this->detectHeaderLineIndexFromMatrix($matrix);
        $headers = $this->normalizeHeaders(
            array_map(fn ($value) => trim((string) $value), $matrix[$headerIndex] ?? []),
        );

        $rows = [];
        for ($i = $headerIndex + 1, $count = count($matrix); $i < $count; $i++) {
            $values = array_map(fn ($value) => trim((string) ($value ?? '')), $matrix[$i] ?? []);
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $values[$index] ?? '';
            }

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    private function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not read uploaded file.');
        }

        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $lines[] = $this->stripBom($line);
        }
        fclose($handle);

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headerIndex = $this->detectHeaderLineIndex($lines);
        $headers = $this->normalizeHeaders(str_getcsv(trim($lines[$headerIndex])));
        $rows = [];

        for ($i = $headerIndex + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public function csvDownload(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $header) {
                    $line[] = $row[$header] ?? '';
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public function xlsxDownload(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();

            foreach ($headers as $columnIndex => $header) {
                $sheet->setCellValue([$columnIndex + 1, 1], $header);
            }

            foreach ($rows as $rowIndex => $row) {
                foreach ($headers as $columnIndex => $header) {
                    $sheet->setCellValue([$columnIndex + 1, $rowIndex + 2], $row[$header] ?? '');
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function usesExcelFormat(string $entity): bool
    {
        return $entity === 'machinery';
    }

    public function downloadFilename(string $entity, string $kind): string
    {
        $extension = $this->usesExcelFormat($entity) ? 'xlsx' : 'csv';

        return "{$entity}_{$kind}.{$extension}";
    }

    /**
     * @return array{headers: list<string>, sample: list<string>}
     */
    public function templateDefinition(string $entity): array
    {
        return match ($entity) {
            'users' => [
                'headers' => [
                    'name', 'email', 'password', 'role', 'department',
                    'designation', 'phone', 'is_active',
                ],
                'sample' => [
                    'John Operator', 'john@example.com', 'ChangeMe123', 'operator',
                    'Production', 'machine_operator', '9876543210', 'yes',
                ],
            ],
            'vendors' => [
                'headers' => [
                    'name', 'contact_person', 'email', 'phone', 'gst_number',
                    'address', 'city', 'status',
                ],
                'sample' => [
                    'ABC Supplies', 'Raj Kumar', 'sales@abc.com', '9876500001',
                    '27AABCU9603R1ZM', 'Plot 12 Industrial Area', 'Mumbai', 'active',
                ],
            ],
            'clients' => [
                'headers' => [
                    'name', 'contact_person', 'email', 'phone', 'gst_number',
                    'address', 'city', 'status',
                ],
                'sample' => [
                    'XYZ Industries', 'Priya Sharma', 'orders@xyz.com', '9876500002',
                    '27AABCU9603R1ZN', 'Sector 5', 'Pune', 'active',
                ],
            ],
            'inventory' => [
                'headers' => [
                    'name', 'sku', 'type', 'quantity', 'unit',
                    'batch_number', 'location', 'min_stock', 'status',
                ],
                'sample' => [
                    'MS Round Bar 25mm', 'MS-RB-25', 'raw_material', '100', 'pcs',
                    'BATCH-001', 'Store A', '10', 'available',
                ],
            ],
            'items' => [
                'headers' => [
                    'item_code', 'category', 'item_description', 'material_grade', 'diameter_mm',
                    'cutting_weight', 'final_weight', 'cutting_size',
                    'moq', 'unit', 'status', 'remark', 'inactive_date', 'documents_count',
                ],
                'sample' => [
                    'EN18-53', 'raw_material', 'EN18 DIA 53', 'EN18', '53',
                    '', '', '', '100', 'kg', 'active', '', '', '0',
                ],
            ],
            'work-orders' => [
                'headers' => [
                    'route_card_no', 'workflow_name', 'item_code', 'product',
                    'client_name', 'quantity', 'unit', 'pr_number',
                    'priority', 'remarks', 'status',
                ],
                'sample' => [
                    'RC-2026-001', 'Standard Manufacturing', 'C7201003', 'PIN',
                    'XYZ Industries', '100', 'pcs', 'PR-001',
                    'normal', 'Bulk assign — add machine route in app later', 'pending',
                ],
            ],
            'machinery' => [
                'headers' => [
                    'sequence', 'machine_code', 'name', 'type', 'location',
                    'capacity', 'manufacturer', 'status', 'default_stage', 'notes',
                ],
                'sample' => [
                    '1', 'CNC-001', 'Bansaw Machine', 'Raw', 'Machining Bay 1',
                    '500 units/day', 'Haas Automation', 'active', 'Raw Material', '',
                ],
            ],
            default => throw new \InvalidArgumentException("Unknown entity: {$entity}"),
        };
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return list<string>
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        return array_map(function (string $header): string {
            $header = $this->stripBom(trim($header));
            $header = strtolower($header);
            $header = preg_replace('/[\s\-]+/', '_', $header) ?? $header;

            return trim($header, '_');
        }, $rawHeaders);
    }

    /**
     * @param  list<string|null>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    /**
     * @param  list<string>  $lines
     */
    private function detectHeaderLineIndex(array $lines): int
    {
        $limit = min(count($lines), 30);

        for ($i = 0; $i < $limit; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $headers = $this->normalizeHeaders(str_getcsv($line));
            if ($this->isLikelyHeaderRow($headers)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $headers
     */
    private function isLikelyHeaderRow(array $headers): bool
    {
        $set = array_flip(array_filter($headers, fn (string $header): bool => $header !== ''));

        if (isset($set['route_card_no']) && isset($set['workflow_name'])) {
            return true;
        }

        if (isset($set['machine_code']) && isset($set['name'])) {
            return true;
        }

        if (isset($set['item_code']) || isset($set['sku']) || isset($set['email'])) {
            return true;
        }

        if (isset($set['code']) && (isset($set['moq']) || isset($set['item_description']))) {
            return true;
        }

        $contactHeaders = ['name', 'contact_person', 'email', 'phone', 'address'];
        $contactMatches = 0;
        foreach ($contactHeaders as $header) {
            if (isset($set[$header])) {
                $contactMatches++;
            }
        }
        if ($contactMatches >= 3) {
            return true;
        }

        return isset($set['name']) && isset($set['type']) && isset($set['quantity']);
    }

    /**
     * @param  list<list<mixed>>  $matrix
     */
    private function detectHeaderLineIndexFromMatrix(array $matrix): int
    {
        $limit = min(count($matrix), 30);

        for ($i = 0; $i < $limit; $i++) {
            $headers = $this->normalizeHeaders(
                array_map(fn ($value) => trim((string) ($value ?? '')), $matrix[$i] ?? []),
            );
            if ($this->isLikelyHeaderRow($headers)) {
                return $i;
            }
        }

        return 0;
    }
}
