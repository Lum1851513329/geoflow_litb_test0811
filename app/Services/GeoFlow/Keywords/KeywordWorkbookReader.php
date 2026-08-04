<?php

namespace App\Services\GeoFlow\Keywords;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use ZipArchive;

final class KeywordWorkbookReader
{
    private readonly int $maxFileSizeBytes;

    private readonly int $maxSheets;

    private readonly int $maxRowsPerSheet;

    private readonly int $maxColumnsPerSheet;

    private readonly int $maxSampleRows;

    private readonly int $headerScanRows;

    private readonly int $maxZipEntries;

    private readonly int $maxUncompressedBytes;

    private readonly int $maxEntryUncompressedBytes;

    private readonly int $maxCompressionRatio;

    public function __construct(
        ?int $maxFileSizeBytes = null,
        ?int $maxSheets = null,
        ?int $maxRowsPerSheet = null,
        ?int $maxColumnsPerSheet = null,
        ?int $maxSampleRows = null,
        ?int $maxZipEntries = null,
        ?int $maxUncompressedBytes = null,
        ?int $maxEntryUncompressedBytes = null,
        ?int $maxCompressionRatio = null,
        ?int $headerScanRows = null,
    ) {
        $limits = config('keyword_intelligence.workbook');
        $this->maxFileSizeBytes = $maxFileSizeBytes ?? (int) ($limits['max_file_size_bytes'] ?? 10_485_760);
        $this->maxSheets = $maxSheets ?? (int) ($limits['max_sheets'] ?? 5);
        $this->maxRowsPerSheet = $maxRowsPerSheet ?? (int) ($limits['max_rows_per_sheet'] ?? 10_000);
        $this->maxColumnsPerSheet = $maxColumnsPerSheet ?? (int) ($limits['max_columns_per_sheet'] ?? 50);
        $this->maxSampleRows = $maxSampleRows ?? (int) ($limits['max_sample_rows'] ?? 10);
        $this->headerScanRows = $headerScanRows ?? (int) ($limits['header_scan_rows'] ?? 10);
        $this->maxZipEntries = $maxZipEntries ?? (int) ($limits['max_zip_entries'] ?? 2_048);
        $this->maxUncompressedBytes = $maxUncompressedBytes ?? (int) ($limits['max_uncompressed_bytes'] ?? 104_857_600);
        $this->maxEntryUncompressedBytes = $maxEntryUncompressedBytes ?? (int) ($limits['max_entry_uncompressed_bytes'] ?? 26_214_400);
        $this->maxCompressionRatio = $maxCompressionRatio ?? (int) ($limits['max_compression_ratio'] ?? 100);

        foreach ([$this->maxFileSizeBytes, $this->maxSheets, $this->maxRowsPerSheet, $this->maxColumnsPerSheet, $this->maxSampleRows, $this->headerScanRows, $this->maxZipEntries, $this->maxUncompressedBytes, $this->maxEntryUncompressedBytes, $this->maxCompressionRatio] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Workbook limits must be positive integers.');
            }
        }
    }

    /**
     * @return array{sheets:array<int,array{name:string,headers:array<int,string>,sample_rows:array<int,array{row_number:int,values:array<string,mixed>}>,row_estimate:int,rows:array<int,array{row_number:int,values:array<string,mixed>}>}>}
     */
    public function inspect(string $absolutePath): array
    {
        $this->assertSafeWorkbook($absolutePath);
        $this->assertSafeZipArchive($absolutePath);

        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $worksheetInfo = $reader->listWorksheetInfo($absolutePath);
        } catch (ReaderException $exception) {
            throw new InvalidArgumentException('The XLSX workbook could not be read.', previous: $exception);
        }

        if (count($worksheetInfo) > $this->maxSheets) {
            throw new InvalidArgumentException('The workbook exceeds the configured sheet limit.');
        }

        $sheets = [];
        foreach ($worksheetInfo as $info) {
            $totalRows = (int) $info['totalRows'];
            $columnCount = (int) $info['totalColumns'];

            // The precise data count is known only after bounded header detection.
            if ($totalRows > $this->maxRowsPerSheet + $this->headerScanRows) {
                throw new InvalidArgumentException('The workbook exceeds the configured row limit.');
            }

            if ($columnCount > $this->maxColumnsPerSheet) {
                throw new InvalidArgumentException('The workbook exceeds the configured column limit.');
            }

            $reader->setReadFilter(new KeywordWorkbookReadFilter($this->maxRowsPerSheet + $this->headerScanRows, $this->maxColumnsPerSheet));
            $reader->setLoadSheetsOnly([(string) $info['worksheetName']]);

            try {
                $spreadsheet = $reader->load($absolutePath);
            } catch (ReaderException $exception) {
                throw new InvalidArgumentException('The XLSX workbook could not be read.', previous: $exception);
            }

            $sheet = $spreadsheet->getSheetByName((string) $info['worksheetName']);
            if ($sheet === null) {
                $spreadsheet->disconnectWorksheets();

                throw new InvalidArgumentException('The XLSX workbook could not be read.');
            }

            $headerRow = $this->detectHeaderRow($sheet, $columnCount, $totalRows);
            $rowEstimate = max(0, $totalRows - $headerRow);
            if ($rowEstimate > $this->maxRowsPerSheet) {
                $spreadsheet->disconnectWorksheets();

                throw new InvalidArgumentException('The workbook exceeds the configured row limit.');
            }

            $headers = $this->headers($sheet, $columnCount, $headerRow);
            $rows = $this->rows($sheet, $headers, $headerRow, $totalRows);
            $spreadsheet->disconnectWorksheets();

            $sheets[] = [
                'name' => (string) $info['worksheetName'],
                'headers' => $headers,
                'sample_rows' => array_slice($rows, 0, $this->maxSampleRows),
                'row_estimate' => $rowEstimate,
                'rows' => $rows,
            ];
        }

        return ['sheets' => $sheets];
    }

    private function assertSafeWorkbook(string $absolutePath): void
    {
        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('Only .xlsx workbooks are supported.');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new InvalidArgumentException('The workbook must be a regular readable file.');
        }

        $size = filesize($absolutePath);
        if ($size === false || $size > $this->maxFileSizeBytes) {
            throw new InvalidArgumentException('The workbook exceeds the configured size limit.');
        }
    }

    private function assertSafeZipArchive(string $absolutePath): void
    {
        $archive = new ZipArchive;
        $opened = $archive->open($absolutePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new InvalidArgumentException('The XLSX workbook must be a readable ZIP archive.');
        }

        try {
            if ($archive->numFiles < 0 || $archive->numFiles > $this->maxZipEntries) {
                throw new InvalidArgumentException('The workbook exceeds the configured ZIP entry limit.');
            }

            $totalUncompressedBytes = 0;
            $worksheetCount = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                if (! is_array($stat)
                    || ! isset($stat['name'], $stat['size'], $stat['comp_size'], $stat['encryption_method'])
                    || ! is_string($stat['name'])
                    || ! is_int($stat['size'])
                    || ! is_int($stat['comp_size'])
                    || $stat['size'] < 0
                    || $stat['comp_size'] < 0
                    || $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    throw new InvalidArgumentException('The workbook ZIP archive has encrypted or invalid entry metadata.');
                }

                if ($stat['size'] > $this->maxEntryUncompressedBytes) {
                    throw new InvalidArgumentException('The workbook ZIP archive exceeds the configured entry size limit.');
                }

                if ($stat['size'] > $this->maxUncompressedBytes - $totalUncompressedBytes) {
                    throw new InvalidArgumentException('The workbook ZIP archive exceeds the configured total uncompressed size limit.');
                }
                $totalUncompressedBytes += $stat['size'];

                if ($stat['size'] > 0 && ($stat['comp_size'] === 0 || $this->maxCompressionRatio < $stat['size'] / $stat['comp_size'])) {
                    throw new InvalidArgumentException('The workbook ZIP archive exceeds the configured compression ratio limit.');
                }

                if (str_starts_with($stat['name'], 'xl/worksheets/') && str_ends_with($stat['name'], '.xml')) {
                    $worksheetCount++;
                    if ($worksheetCount > $this->maxSheets) {
                        throw new InvalidArgumentException('The workbook exceeds the configured sheet limit.');
                    }
                }
            }
        } finally {
            $archive->close();
        }
    }

    /** @return array<int,string> */
    private function detectHeaderRow(object $sheet, int $columnCount, int $totalRows): int
    {
        $mapper = new KeywordColumnMapper;
        $candidates = [];
        $scanThrough = min($totalRows, $this->headerScanRows);

        for ($row = 1; $row <= $scanThrough; $row++) {
            $headers = $this->headers($sheet, $columnCount, $row);
            $mapping = $mapper->suggest($headers, 'header_detection');
            if (! in_array('keyword', $mapping, true)) {
                continue;
            }

            $candidates[$row] = count(array_unique($mapping));
        }

        if ($candidates === []) {
            throw new InvalidArgumentException('The worksheet has no recognizable header row within the configured scan limit.');
        }

        $bestScore = max($candidates);
        $bestRows = array_keys(array_filter($candidates, static fn (int $score): bool => $score === $bestScore));
        if (count($bestRows) !== 1) {
            throw new InvalidArgumentException('The worksheet has an ambiguous header row within the configured scan limit.');
        }

        return $bestRows[0];
    }

    /** @return array<int,string> */
    private function headers(object $sheet, int $columnCount, int $headerRow): array
    {
        $headers = [];
        $occurrences = [];
        for ($column = 1; $column <= $columnCount; $column++) {
            $value = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$headerRow)->getValue();
            $base = $this->normalizeHeader($this->stringValue($value));
            $base = $base === '' ? 'column_'.$column : $base;
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $headers[] = $occurrences[$base] === 1 ? $base : $base.'_'.$occurrences[$base];
        }

        return $headers;
    }

    /** @param array<int,string> $headers
     * @return array<int,array{row_number:int,values:array<string,mixed>}>
     */
    private function rows(object $sheet, array $headers, int $headerRow, int $totalRows): array
    {
        $rows = [];
        for ($row = $headerRow + 1; $row <= $totalRows; $row++) {
            $values = [];
            $hasValue = false;
            foreach ($headers as $index => $header) {
                $value = $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).$row)->getValue();
                $value = $this->cellValue($value);
                $values[$header] = $value;
                $hasValue = $hasValue || $value !== null && $value !== '';
            }

            if ($hasValue) {
                $rows[] = ['row_number' => $row, 'values' => $values];
            }
        }

        return $rows;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '_', $value);

        return trim($value ?? '', '_');
    }

    private function cellValue(mixed $value): mixed
    {
        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    private function stringValue(mixed $value): string
    {
        $value = $this->cellValue($value);

        return is_scalar($value) ? (string) $value : '';
    }
}

final class KeywordWorkbookReadFilter implements IReadFilter
{
    public function __construct(private readonly int $maxRow, private readonly int $maxColumn) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= $this->maxRow
            && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumn;
    }
}
