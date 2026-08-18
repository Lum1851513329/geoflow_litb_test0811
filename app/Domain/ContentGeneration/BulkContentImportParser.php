<?php

namespace App\Domain\ContentGeneration;

use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class BulkContentImportParser
{
    private const HEADER_ROW = 1;

    private const INSTRUCTION_ROW = 2;

    private const FIRST_DATA_ROW = 3;

    /**
     * @return array{rows: list<BulkContentImportRow>, errors: list<array{row: int, message: string}>}
     */
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();

        $columnIndexByKey = [];
        foreach ($sheet->rangeToArray('A'.self::HEADER_ROW.':'.$sheet->getHighestDataColumn().self::HEADER_ROW)[0] as $index => $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $columnIndexByKey[$key] = $index;
            }
        }
        foreach (['site', 'preset', 'category', 'topic', 'knowledge_base'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columnIndexByKey)) {
                return ['rows' => [], 'errors' => [['row' => self::HEADER_ROW, 'message' => "Missing required column \"{$requiredColumn}\"."]]];
            }
        }

        $rows = [];
        $errors = [];
        for ($rowNumber = self::FIRST_DATA_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $rawRow = $sheet->rangeToArray('A'.$rowNumber.':'.$sheet->getHighestDataColumn().$rowNumber)[0];
            if ($this->isBlankRow($rawRow)) {
                continue;
            }

            $result = $this->parseRow($rowNumber, $rawRow, $columnIndexByKey);
            if (isset($result['error'])) {
                $errors[] = ['row' => $rowNumber, 'message' => $result['error']];

                continue;
            }
            $rows[] = $result['row'];
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    /**
     * @param  list<BulkContentImportRow>  $rows
     * @return list<array{row: int, message: string}>
     */
    public function conflicts(array $rows): array
    {
        $knowledgeBaseIdByGroup = [];
        $errors = [];
        foreach ($rows as $row) {
            $groupKey = $row->groupKey();
            if (! array_key_exists($groupKey, $knowledgeBaseIdByGroup)) {
                $knowledgeBaseIdByGroup[$groupKey] = $row->knowledgeBaseId;

                continue;
            }
            if ($knowledgeBaseIdByGroup[$groupKey] !== $row->knowledgeBaseId) {
                $errors[] = ['row' => $row->rowNumber, 'message' => 'knowledge_base differs from an earlier row in the same site/preset/category group.'];
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $rawRow
     * @param  array<string, int>  $columnIndexByKey
     * @return array{row: BulkContentImportRow}|array{error: string}
     */
    private function parseRow(int $rowNumber, array $rawRow, array $columnIndexByKey): array
    {
        $get = fn (string $key): string => trim((string) ($rawRow[$columnIndexByKey[$key]] ?? ''));

        $siteCell = $get('site');
        $channel = $this->resolveByParenthetical($siteCell, fn (string $domain) => DistributionChannel::query()->where('domain', $domain)->first());
        if ($channel === null) {
            return ['error' => "Could not resolve site \"{$siteCell}\" to a distribution channel."];
        }

        $presetCell = $get('preset');
        $preset = $this->resolveByParenthetical($presetCell, fn (string $key) => PromptPreset::query()
            ->where('preset_key', $key)
            ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
            ->first());
        if ($preset === null) {
            return ['error' => "Could not resolve preset \"{$presetCell}\" to a preset with a blueprint template."];
        }

        $categoryName = $get('category');
        $category = Category::query()->where('name', $categoryName)->first();
        if ($category === null) {
            return ['error' => "Could not resolve category \"{$categoryName}\"."];
        }

        $knowledgeBaseName = $get('knowledge_base');
        $knowledgeBase = KnowledgeBase::query()->where('name', $knowledgeBaseName)->first();
        if ($knowledgeBase === null) {
            return ['error' => "Could not resolve knowledge base \"{$knowledgeBaseName}\"."];
        }

        $topic = $get('topic');
        if ($topic === '') {
            return ['error' => 'topic is required.'];
        }

        $referenceUrls = array_values(array_filter(array_map('trim', explode("\n", $get('reference_urls')))));

        return ['row' => new BulkContentImportRow(
            rowNumber: $rowNumber,
            distributionChannelId: (int) $channel->id,
            promptPresetId: (int) $preset->id,
            categoryId: (int) $category->id,
            knowledgeBaseId: (int) $knowledgeBase->id,
            topic: $topic,
            coreQuestion: ($get('core_question') !== '') ? $get('core_question') : null,
            primaryKeyword: ($get('primary_keyword') !== '') ? $get('primary_keyword') : null,
            referenceUrls: $referenceUrls,
        )];
    }

    private function resolveByParenthetical(string $cell, \Closure $resolve): mixed
    {
        if (preg_match('/\(([^()]+)\)\s*$/u', $cell, $matches) !== 1) {
            return null;
        }

        return $resolve(trim($matches[1]));
    }

    /** @param list<mixed> $row */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
