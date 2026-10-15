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

    private readonly int $chunkRows;

    private readonly int $maxDataCells;

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
        ?int $chunkRows = null,
        ?int $maxDataCells = null,
    ) {
        $limits = config('keyword_intelligence.workbook');
        $this->maxFileSizeBytes = $maxFileSizeBytes ?? (int) ($limits['max_file_size_bytes'] ?? 10_485_760);
        $this->maxSheets = $maxSheets ?? (int) ($limits['max_sheets'] ?? 5);
        $this->maxRowsPerSheet = $maxRowsPerSheet ?? (int) ($limits['max_rows_per_sheet'] ?? 10_000);
        $this->maxColumnsPerSheet = $maxColumnsPerSheet ?? (int) ($limits['max_columns_per_sheet'] ?? 50);
        $this->maxSampleRows = $maxSampleRows ?? (int) ($limits['max_sample_rows'] ?? 10);
        $this->headerScanRows = $headerScanRows ?? (int) ($limits['header_scan_rows'] ?? 10);
        $this->chunkRows = $chunkRows ?? (int) ($limits['chunk_rows'] ?? 5_000);
        $this->maxDataCells = $maxDataCells ?? (int) ($limits['max_data_cells'] ?? 500_000);
        $this->maxZipEntries = $maxZipEntries ?? (int) ($limits['max_zip_entries'] ?? 2_048);
        $this->maxUncompressedBytes = $maxUncompressedBytes ?? (int) ($limits['max_uncompressed_bytes'] ?? 104_857_600);
        $this->maxEntryUncompressedBytes = $maxEntryUncompressedBytes ?? (int) ($limits['max_entry_uncompressed_bytes'] ?? 26_214_400);
        $this->maxCompressionRatio = $maxCompressionRatio ?? (int) ($limits['max_compression_ratio'] ?? 100);

        foreach ([$this->maxFileSizeBytes, $this->maxSheets, $this->maxRowsPerSheet, $this->maxColumnsPerSheet, $this->maxSampleRows, $this->headerScanRows, $this->chunkRows, $this->maxDataCells, $this->maxZipEntries, $this->maxUncompressedBytes, $this->maxEntryUncompressedBytes, $this->maxCompressionRatio] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Workbook limits must be positive integers.');
            }
        }
    }

    /**
     * @return array{sheets:array<int,array{name:string,headers:array<int,string>,sample_rows:array<int,array{row_number:int,values:array<string,mixed>}>,row_estimate:int,header_row:int,total_rows:int,column_count:int}>}
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
        $allocatedDataCells = 0;
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

            $worksheetName = (string) $info['worksheetName'];
            $headerSpreadsheet = $this->loadRange($absolutePath, $worksheetName, 1, min($totalRows, $this->headerScanRows), $columnCount);
            try {
                $headerSheet = $headerSpreadsheet->getSheetByName($worksheetName);
                if ($headerSheet === null) {
                    throw new InvalidArgumentException('The XLSX workbook could not be read.');
                }

                $headerRow = $this->detectHeaderRow($headerSheet, $columnCount, $totalRows);
                $headers = $this->headers($headerSheet, $columnCount, $headerRow);
            } finally {
                $headerSpreadsheet->disconnectWorksheets();
            }
            $rowEstimate = max(0, $totalRows - $headerRow);
            if ($rowEstimate > $this->maxRowsPerSheet) {
                throw new InvalidArgumentException('The workbook exceeds the configured row limit.');
            }

            $remainingDataCells = $this->maxDataCells - $allocatedDataCells;
            if ($columnCount > 0 && $rowEstimate > intdiv($remainingDataCells, $columnCount)) {
                throw new InvalidArgumentException('The workbook exceeds the configured data cell budget.');
            }
            $allocatedDataCells += $rowEstimate * $columnCount;

            $sampleRows = [];
            for ($startRow = $headerRow + 1; $startRow <= $totalRows && count($sampleRows) < $this->maxSampleRows; $startRow += $this->chunkRows) {
                $endRow = min($totalRows, $startRow + $this->chunkRows - 1);
                $chunkSpreadsheet = $this->loadRange($absolutePath, $worksheetName, $startRow, $endRow, $columnCount);
                try {
                    $chunkSheet = $chunkSpreadsheet->getSheetByName($worksheetName);
                    if ($chunkSheet === null) {
                        throw new InvalidArgumentException('The XLSX workbook could not be read.');
                    }

                    $remaining = $this->maxSampleRows - count($sampleRows);
                    array_push($sampleRows, ...array_slice($this->rows($chunkSheet, $headers, $startRow, $endRow), 0, $remaining));
                } finally {
                    $chunkSpreadsheet->disconnectWorksheets();
                }
            }

            $sheets[] = [
                'name' => $worksheetName,
                'headers' => $headers,
                'sample_rows' => $sampleRows,
                'row_estimate' => $rowEstimate,
                'header_row' => $headerRow,
                'total_rows' => $totalRows,
                'column_count' => $columnCount,
            ];
        }

        return ['sheets' => $sheets];
    }

    /**
     * Stream non-empty worksheet rows without retaining more than one configured chunk.
     *
     * @param  array{sheets:array<int,array{name:string,headers:array<int,string>,sample_rows:array<int,array{row_number:int,values:array<string,mixed>}>,row_estimate:int,header_row:int,total_rows:int,column_count:int}>}|null  $inspection
     * @return \Generator<int,array{sheet_name:string,headers:array<int,string>,rows:array<int,array{row_number:int,values:array<string,mixed>}>}>
     */
    public function chunks(string $absolutePath, ?array $inspection = null): \Generator
    {
        $this->assertSafeWorkbook($absolutePath);
        $this->assertSafeZipArchive($absolutePath);
        $inspection ??= $this->inspect($absolutePath);
        $this->assertSafeInspection($inspection);

        foreach ($inspection['sheets'] as $sheet) {
            for ($startRow = $sheet['header_row'] + 1; $startRow <= $sheet['total_rows']; $startRow += $this->chunkRows) {
                $endRow = min($sheet['total_rows'], $startRow + $this->chunkRows - 1);
                $chunkSpreadsheet = $this->loadRange(
                    $absolutePath,
                    $sheet['name'],
                    $startRow,
                    $endRow,
                    $sheet['column_count'],
                );
                try {
                    $chunkSheet = $chunkSpreadsheet->getSheetByName($sheet['name']);
                    if ($chunkSheet === null) {
                        throw new InvalidArgumentException('The XLSX workbook could not be read.');
                    }

                    $rows = $this->rows($chunkSheet, $sheet['headers'], $startRow, $endRow);
                    if ($rows !== []) {
                        yield [
                            'sheet_name' => $sheet['name'],
                            'headers' => $sheet['headers'],
                            'rows' => $rows,
                        ];
                    }
                } finally {
                    $chunkSpreadsheet->disconnectWorksheets();
                }
            }
        }
    }

    /** @param array<string,mixed> $inspection */
    private function assertSafeInspection(array $inspection): void
    {
        $sheets = $inspection['sheets'] ?? null;
        if (! is_array($sheets) || count($sheets) > $this->maxSheets) {
            throw new InvalidArgumentException('The workbook exceeds the configured sheet limit.');
        }

        $allocatedDataCells = 0;
        foreach ($sheets as $sheet) {
            if (! is_array($sheet)
                || ! is_string($sheet['name'] ?? null)
                || ! is_array($sheet['headers'] ?? null)
                || ! is_int($sheet['header_row'] ?? null)
                || ! is_int($sheet['total_rows'] ?? null)
                || ! is_int($sheet['column_count'] ?? null)) {
                throw new InvalidArgumentException('The workbook inspection metadata is invalid.');
            }

            $headerRow = $sheet['header_row'];
            $totalRows = $sheet['total_rows'];
            $columnCount = $sheet['column_count'];
            $dataRows = max(0, $totalRows - $headerRow);
            if ($headerRow < 1 || $headerRow > $this->headerScanRows || $dataRows > $this->maxRowsPerSheet) {
                throw new InvalidArgumentException('The workbook exceeds the configured row limit.');
            }
            if ($columnCount < 1 || $columnCount > $this->maxColumnsPerSheet || count($sheet['headers']) !== $columnCount) {
                throw new InvalidArgumentException('The workbook exceeds the configured column limit.');
            }
            if ($dataRows > intdiv($this->maxDataCells - $allocatedDataCells, $columnCount)) {
                throw new InvalidArgumentException('The workbook exceeds the configured data cell budget.');
            }
            $allocatedDataCells += $dataRows * $columnCount;
        }
    }

    private function loadRange(string $absolutePath, string $worksheetName, int $startRow, int $endRow, int $columnCount): object
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setIgnoreRowsWithNoCells(true);
        $reader->setReadFilter(new KeywordWorkbookReadFilter($startRow, $endRow, $columnCount));
        $reader->setLoadSheetsOnly([$worksheetName]);

        try {
            return $reader->load($absolutePath);
        } catch (ReaderException $exception) {
            throw new InvalidArgumentException('The XLSX workbook could not be read.', previous: $exception);
        }
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
            $score = count(array_unique($mapping));
            if (! in_array('keyword', $mapping, true) || ($row > 1 && $score < 2)) {
                continue;
            }

            $candidates[$row] = $score;
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
    private function rows(object $sheet, array $headers, int $startRow, int $endRow): array
    {
        $rows = [];
        for ($row = $startRow; $row <= $endRow; $row++) {
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
    public function __construct(
        private readonly int $minRow,
        private readonly int $maxRow,
        private readonly int $maxColumn,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->minRow
            && $row <= $this->maxRow
            && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumn;
    }
}
