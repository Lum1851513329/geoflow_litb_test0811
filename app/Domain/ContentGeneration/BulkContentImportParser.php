<?php

namespace App\Domain\ContentGeneration;

use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
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
        foreach (['site', 'preset', 'category', 'topic'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columnIndexByKey)) {
                return ['rows' => [], 'errors' => [['row' => self::HEADER_ROW, 'message' => "Missing required column \"{$requiredColumn}\"."]]];
            }
        }
        if (! isset($columnIndexByKey['brand']) && ! isset($columnIndexByKey['knowledge_base'])) {
            return ['rows' => [], 'errors' => [['row' => self::HEADER_ROW, 'message' => 'Missing required column "brand" (or legacy "knowledge_base").']]];
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
        // Scope is part of groupKey now. Different libraries/brands/sites/directories
        // intentionally create separate tasks; conflicting row modes fail in parseRow.
        return [];
    }

    /**
     * @param  list<mixed>  $rawRow
     * @param  array<string, int>  $columnIndexByKey
     * @return array{row: BulkContentImportRow}|array{error: string}
     */
    private function parseRow(int $rowNumber, array $rawRow, array $columnIndexByKey): array
    {
        $get = fn (string $key): string => isset($columnIndexByKey[$key]) ? trim((string) ($rawRow[$columnIndexByKey[$key]] ?? '')) : '';

        $siteCell = $get('site');
        $channel = $this->resolveByParenthetical($siteCell, fn (string $domain) => DistributionChannel::query()->where('domain', $domain)->first());
        if ($channel === null) {
            return ['error' => "Could not resolve site \"{$siteCell}\" to a distribution channel."];
        }

        $presetCell = $get('preset');
        // Presets are normally supplied as their canonical key (e.g.
        // litb.geo_product_buying_guide.en). Keep accepting the optional
        // parenthetical form for backwards compatibility, but do not route
        // canonical keys through the domain-oriented resolver: that resolver
        // intentionally returns null when no parentheses are present.
        $presetLookup = fn (string $key) => PromptPreset::query()
            ->where('preset_key', $key)
            ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
            ->first();
        $preset = $presetLookup($presetCell);
        if ($preset === null) {
            $preset = $this->resolveByParenthetical($presetCell, $presetLookup);
        }
        if ($preset === null) {
            return ['error' => "Could not resolve preset \"{$presetCell}\" to a preset with a blueprint template."];
        }

        $categoryName = $get('category');
        $category = Category::query()->where('name', $categoryName)->first();
        if ($category === null) {
            return ['error' => "Could not resolve category \"{$categoryName}\"."];
        }

        $knowledgeBaseName = $get('knowledge_base');
        $knowledgeBase = null;
        $scope = ['knowledge_mode' => 'legacy_manual', 'knowledge_brand_id' => null, 'knowledge_site_id' => null, 'knowledge_directory_id' => null];
        $brandCell = $get('brand');
        if ($knowledgeBaseName !== '' && ($brandCell !== '' || $get('directory') !== '' || $get('knowledge_site') !== '')) {
            return ['error' => 'Do not mix brand/directory/knowledge_site with legacy knowledge_base in one row.'];
        }
        if ($brandCell !== '') {
            $brand = $this->resolveUnique(KnowledgeBrand::query(), $brandCell, ['key', 'name']);
            if ($brand === null) {
                return ['error' => "Could not resolve brand \"{$brandCell}\" uniquely; use its key."];
            }
            $site = null;
            if ($get('knowledge_site') !== '') {
                $site = $this->resolveUnique(KnowledgeSite::where('brand_id', $brand->id), $get('knowledge_site'), ['key', 'canonical_host'], $brand->key);
                if ($site === null) {
                    return ['error' => 'Could not resolve knowledge_site uniquely within the selected brand.'];
                }
            }
            $directory = null;
            if ($get('directory') !== '') {
                $directory = $this->resolveUnique(KnowledgeDirectory::where('brand_id', $brand->id), $get('directory'), ['path', 'key', 'name'], $brand->key);
                // Imported opportunity files may carry the approved keyword
                // taxonomy path (for example
                // "Fashion & Apparel/Women's Clothing"), while the current
                // knowledge tree may only have its brand-level root
                // directories. In that case, safely fall back to the unique
                // top-level root instead of rejecting every row. Ambiguous
                // roots still fail closed and require a full path.
                if ($directory === null && str_contains($get('directory'), '/')) {
                    $root = trim(strtok($get('directory'), '/'));
                    $rootAliases = [
                        'Brand & Company' => 'Brand & Company',
                        'Logistics & Fulfilment' => 'Logistics & Fulfilment',
                        'Official News' => 'Official News',
                        'Investor Relations' => 'Investor Relations',
                        'Research Reference' => 'Research Reference',
                        'Help Center' => 'Customer Transaction',
                        'About LightInTheBox' => 'Brand & Company',
                    ];
                    if (!isset($rootAliases[$root]) && $root !== '') {
                        // Product taxonomy roots (Fashion, Home, Shoes, etc.)
                        // are intentionally stored under the single product
                        // directory in the current knowledge tree.
                        $root = 'Product Category';
                    } else {
                        $root = $rootAliases[$root] ?? $root;
                    }
                    if ($root !== '') {
                        $directory = $this->resolveUnique(KnowledgeDirectory::where('brand_id', $brand->id), $root, ['path', 'key', 'name'], $brand->key);
                    }
                }
                if ($directory === null) {
                    return ['error' => 'Could not resolve directory uniquely within the selected brand; use its full path.'];
                }
            }
            try {
                $scope = app(GenerationKnowledgeScopeResolver::class)->normalize([
                    'knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $brand->id,
                    'knowledge_site_id' => $site?->id, 'knowledge_directory_id' => $directory?->id,
                ]);
            } catch (ValidationException $exception) {
                return ['error' => implode(' ', $exception->validator->errors()->all())];
            }
        } else {
            if ($get('directory') !== '' || $get('knowledge_site') !== '' || $knowledgeBaseName === '') {
                return ['error' => 'brand is required (or supply knowledge_base alone for a legacy row).'];
            }
            $knowledgeBase = $this->resolveUnique(KnowledgeBase::query(), $knowledgeBaseName, ['name']);
            if ($knowledgeBase === null) {
                return ['error' => "Could not resolve knowledge base \"{$knowledgeBaseName}\" uniquely."];
            }
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
            knowledgeBaseId: $knowledgeBase?->id,
            topic: $topic,
            coreQuestion: ($get('core_question') !== '') ? $get('core_question') : null,
            primaryKeyword: ($get('primary_keyword') !== '') ? $get('primary_keyword') : null,
            referenceUrls: $referenceUrls,
            knowledgeMode: $scope['knowledge_mode'],
            knowledgeBrandId: $scope['knowledge_brand_id'],
            knowledgeSiteId: $scope['knowledge_site_id'],
            knowledgeDirectoryId: $scope['knowledge_directory_id'],
        )];
    }

    private function resolveUnique(Builder $query, string $cell, array $columns, ?string $brandKey = null): mixed
    {
        // Try exact names/keys first, including names containing parentheses.
        $matches = (clone $query)->where(function (Builder $q) use ($columns, $cell): void {
            foreach ($columns as $column) {
                $q->orWhere($column, $cell);
            }
        })->limit(2)->get();
        if ($matches->count() > 1) {
            return null;
        }
        // Reference labels end with a stable key/full path. Balance parentheses
        // so directory paths such as /products/shoes(us) survive a round trip.
        if (count($columns) > 1 && str_ends_with($cell, ')')) {
            $depth = 0;
            for ($index = strlen($cell) - 1; $index >= 0; $index--) {
                if ($cell[$index] === ')') {
                    $depth++;
                } elseif ($cell[$index] === '(' && --$depth === 0) {
                    $key = trim(substr($cell, $index + 1, -1));
                    $encodedMatches = $query->where($columns[0], $key)->limit(2)->get();
                    $labelParts = explode(' / ', trim(substr($cell, 0, $index)), 2);
                    if ($encodedMatches->isNotEmpty() && $brandKey !== null && count($labelParts) === 2 && $labelParts[0] !== $brandKey) {
                        // Global dropdown labels are brand-qualified. Never retarget
                        // another brand's label to this brand's shared key/path. A
                        // literal name without an encoded match has no qualifier.
                        return null;
                    }
                    // A literal display name must never override a reference
                    // label pointing at a different scope (or vice versa).
                    $matches = $matches->merge($encodedMatches);
                    break;
                }
            }
        }

        return $matches->count() === 1 ? $matches->first() : null;
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
