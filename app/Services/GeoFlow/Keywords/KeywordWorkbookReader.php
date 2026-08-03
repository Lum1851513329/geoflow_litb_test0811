<?php

namespace App\Services\GeoFlow\Keywords;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

final class KeywordWorkbookReader
{
    public function __construct(
        private readonly int $maxFileSizeBytes = 10_485_760,
        private readonly int $maxSheets = 5,
        private readonly int $maxRowsPerSheet = 10_000,
        private readonly int $maxColumnsPerSheet = 50,
        private readonly int $maxSampleRows = 10,
    ) {
        foreach ([$maxFileSizeBytes, $maxSheets, $maxRowsPerSheet, $maxColumnsPerSheet, $maxSampleRows] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Workbook limits must be positive integers.');
            }
        }
    }

    /**
     * @return array{sheets:array<int,array{name:string,headers:array<int,string>,sample_rows:array<int,array<string,mixed>>,row_estimate:int,rows:array<int,array<string,mixed>>}>}
     */
    public function inspect(string $absolutePath): array
    {
        $this->assertSafeWorkbook($absolutePath);

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
            $rowEstimate = max(0, ((int) $info['totalRows']) - 1);
            $columnCount = (int) $info['totalColumns'];

            if ($rowEstimate > $this->maxRowsPerSheet) {
                throw new InvalidArgumentException('The workbook exceeds the configured row limit.');
            }

            if ($columnCount > $this->maxColumnsPerSheet) {
                throw new InvalidArgumentException('The workbook exceeds the configured column limit.');
            }

            $reader->setReadFilter(new KeywordWorkbookReadFilter($this->maxRowsPerSheet + 1, $this->maxColumnsPerSheet));
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

            $headers = $this->headers($sheet, $columnCount);
            $rows = $this->rows($sheet, $headers, $rowEstimate);
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

    /** @return array<int,string> */
    private function headers(object $sheet, int $columnCount): array
    {
        $headers = [];
        $occurrences = [];
        for ($column = 1; $column <= $columnCount; $column++) {
            $value = $sheet->getCell(Coordinate::stringFromColumnIndex($column).'1')->getValue();
            $base = $this->normalizeHeader($this->stringValue($value));
            $base = $base === '' ? 'column_'.$column : $base;
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $headers[] = $occurrences[$base] === 1 ? $base : $base.'_'.$occurrences[$base];
        }

        return $headers;
    }

    /** @param array<int,string> $headers
     * @return array<int,array<string,mixed>>
     */
    private function rows(object $sheet, array $headers, int $rowEstimate): array
    {
        $rows = [];
        for ($row = 2; $row <= $rowEstimate + 1; $row++) {
            $values = [];
            $hasValue = false;
            foreach ($headers as $index => $header) {
                $value = $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).$row)->getValue();
                $value = $this->cellValue($value);
                $values[$header] = $value;
                $hasValue = $hasValue || $value !== null && $value !== '';
            }

            if ($hasValue) {
                $rows[] = $values;
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
