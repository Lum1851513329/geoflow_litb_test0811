<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use App\Models\KeywordLibrary;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class KnowledgeImportValidator
{
    public const BRAND_KEY = 'lightinthebox';

    public const SITE_KEY = 'lightinthebox-main';

    /** @var array<string, string> */
    public const ROOT_DIRECTORIES = [
        'brand-company' => 'Brand & Company',
        'customer-transaction' => 'Customer Transaction',
        'policy-compliance' => 'Policy & Compliance',
        'product-category' => 'Product Category',
        'new-hot-products' => 'New & Hot Products',
        'logistics-fulfilment' => 'Logistics & Fulfilment',
        'official-news' => 'Official News',
        'investor-relations' => 'Investor Relations',
        'research-reference' => 'Research Reference',
    ];

    private const REQUIRED_DOCUMENT_FIELDS = [
        'document_id',
        'asset_key',
        'canonical_document_key',
        'version_label',
        'lifecycle_status',
        'brand_key',
        'site_key',
        'directory_path',
        'language_code',
        'authority_level',
        'risk_level',
        'review_status',
        'generation_eligible',
        'citation_required',
        'title',
        'source_name',
        'source_url',
        'source_type',
        'source_file_path',
        'source_file_hash',
        'normalized_content_hash',
        'ingestion_method',
        'retrieved_at',
        'effective_date',
        'review_due_at',
        'warnings',
        'cleaned_file_path',
        'cleaned_file_hash',
    ];

    /**
     * @return array{
     *   manifest:list<array<string,mixed>>,
     *   duplicates:list<array<string,mixed>>,
     *   exclusions:list<array<string,mixed>>,
     *   source_checksum:string,
     *   configuration_hash:string,
     *   manifest_path:string
     * }
     */
    public function validate(string $manifestPath, int $keywordLibraryId): array
    {
        if ($keywordLibraryId < 1 || ! KeywordLibrary::query()->whereKey($keywordLibraryId)->exists()) {
            throw new RuntimeException('governed_import.keyword_library_not_found');
        }

        $manifestPath = $this->regularFile($manifestPath, 'manifest');
        $root = dirname($manifestPath);
        $duplicatePath = $this->regularFile($root.'/duplicate_audit.jsonl', 'duplicate_audit');
        $exclusionCandidate = $root.'/exclusion_audit.jsonl';
        $exclusionPath = is_file($exclusionCandidate)
            ? $this->regularFile($exclusionCandidate, 'exclusion_audit')
            : null;

        [$manifest, $manifestBytes] = $this->readJsonLines($manifestPath, 'manifest');
        [$duplicates, $duplicateBytes] = $this->readJsonLines($duplicatePath, 'duplicate_audit');
        [$exclusions, $exclusionBytes] = $exclusionPath === null
            ? [[], '']
            : $this->readJsonLines($exclusionPath, 'exclusion_audit');

        if ($manifest === []) {
            throw new RuntimeException('governed_import.manifest_empty');
        }

        $this->validateDocuments($manifest, $root);
        $targets = $this->validateExclusions($exclusions);
        foreach ($manifest as $record) {
            $targets[(string) $record['document_id']] = (string) $record['source_file_path'];
        }
        $this->validateDuplicates($duplicates, $targets);
        $this->validateKeywordTargets($manifest, $keywordLibraryId);
        $sourceSetHash = $this->validateSummaryHashes($root, $manifestBytes, $duplicateBytes, $exclusionBytes);
        $artifactHashes = [
            'manifest.jsonl' => hash('sha256', $manifestBytes),
            'duplicate_audit.jsonl' => hash('sha256', $duplicateBytes),
            'exclusion_audit.jsonl' => hash('sha256', $exclusionBytes),
        ];

        $configuration = [
            'contract_version' => 1,
            'brand_key' => self::BRAND_KEY,
            'site_key' => self::SITE_KEY,
            'keyword_library_id' => $keywordLibraryId,
            'root_directories' => array_keys(self::ROOT_DIRECTORIES),
            'artifact_hashes' => $artifactHashes,
        ];

        return [
            'manifest' => $manifest,
            'duplicates' => $duplicates,
            'exclusions' => $exclusions,
            'source_checksum' => $sourceSetHash
                ?? hash('sha256', $manifestBytes."\0".$duplicateBytes."\0".$exclusionBytes),
            'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'manifest_path' => $manifestPath,
        ];
    }

    /** @param list<array<string,mixed>> $records */
    private function validateDocuments(array &$records, string $root): void
    {
        $documentIds = [];
        $assetKeys = [];
        $cleanedPaths = [];
        $activeHashes = [];

        foreach ($records as $index => &$record) {
            $row = $index + 1;
            $this->requireFields($record, self::REQUIRED_DOCUMENT_FIELDS, "manifest row {$row}");
            $this->requireStrings($record, [
                'document_id', 'asset_key', 'canonical_document_key', 'version_label',
                'lifecycle_status', 'brand_key', 'site_key', 'directory_path',
                'language_code', 'authority_level', 'risk_level', 'review_status',
                'title', 'source_name', 'source_type', 'source_file_path',
                'source_file_hash', 'normalized_content_hash', 'ingestion_method',
                'cleaned_file_path', 'cleaned_file_hash',
            ], "manifest row {$row}");
            $this->requireBoolean($record, 'generation_eligible', "manifest row {$row}");
            $this->requireBoolean($record, 'citation_required', "manifest row {$row}");
            if (! is_array($record['warnings']) || ! array_is_list($record['warnings'])) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.warnings_invalid");
            }
            foreach ($record['warnings'] as $warning) {
                if (! is_string($warning)) {
                    throw new RuntimeException("governed_import.manifest_row_{$row}.warnings_invalid");
                }
            }

            if ($record['brand_key'] !== self::BRAND_KEY || $record['site_key'] !== self::SITE_KEY) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.ownership_invalid");
            }
            if (! isset(self::ROOT_DIRECTORIES[$record['directory_path']])) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.directory_invalid");
            }
            if ($record['language_code'] !== 'en') {
                throw new RuntimeException("governed_import.manifest_row_{$row}.language_invalid");
            }
            if (! in_array($record['authority_level'], ['first_party', 'regulatory_filing', 'mixed_research', 'third_party'], true)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.authority_invalid");
            }
            if (! in_array($record['lifecycle_status'], ['active', 'superseded'], true)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.lifecycle_invalid");
            }
            if ($record['asset_key'] !== 'knowledge:'.$record['document_id']) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.asset_key_invalid");
            }
            $this->requireHash($record['source_file_hash'], "manifest row {$row} source_file_hash");
            $this->requireHash($record['normalized_content_hash'], "manifest row {$row} normalized_content_hash");
            $this->requireHash($record['cleaned_file_hash'], "manifest row {$row} cleaned_file_hash");
            $this->validateOptionalDate($record['retrieved_at'], "manifest row {$row} retrieved_at", true);
            $this->validateOptionalDate($record['effective_date'], "manifest row {$row} effective_date");
            $this->validateOptionalDate($record['review_due_at'], "manifest row {$row} review_due_at");

            if (
                in_array($record['directory_path'], ['policy-compliance', 'customer-transaction'], true)
                && $record['generation_eligible']
                && (! $this->nonEmptyString($record['source_url']) || ! $this->nonEmptyString($record['retrieved_at']))
            ) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.policy_provenance_invalid");
            }
            if (! is_string($record['source_url'])) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.source_url_invalid");
            }

            $documentId = (string) $record['document_id'];
            $assetKey = (string) $record['asset_key'];
            $cleanedPath = (string) $record['cleaned_file_path'];
            if (isset($documentIds[$documentId]) || isset($assetKeys[$assetKey]) || isset($cleanedPaths[$cleanedPath])) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.identity_not_unique");
            }
            $documentIds[$documentId] = true;
            $assetKeys[$assetKey] = true;
            $cleanedPaths[$cleanedPath] = true;

            if ($record['lifecycle_status'] === 'active') {
                $activeHash = $record['brand_key'].'\0'.$record['language_code'].'\0'.$record['normalized_content_hash'];
                if (isset($activeHashes[$activeHash])) {
                    throw new RuntimeException("governed_import.manifest_row_{$row}.active_hash_not_unique");
                }
                $activeHashes[$activeHash] = true;
            }

            $cleanedFile = $this->packageFile($root, $cleanedPath, "manifest row {$row} cleaned file");
            $bytes = file_get_contents($cleanedFile);
            if ($bytes === false || ! hash_equals((string) $record['cleaned_file_hash'], hash('sha256', $bytes))) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.cleaned_file_hash_mismatch");
            }
            $record['_content'] = $this->stripFrontMatter($bytes);
            $record['keyword_node_ids'] = $this->normalizeIds($record['keyword_node_ids'] ?? [], "manifest row {$row} keyword_node_ids");
            $record['keyword_cluster_ids'] = $this->normalizeIds($record['keyword_cluster_ids'] ?? [], "manifest row {$row} keyword_cluster_ids");
        }
        unset($record);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,string>
     */
    private function validateExclusions(array $records): array
    {
        $targets = [];
        foreach ($records as $index => $record) {
            $row = $index + 1;
            $this->requireFields($record, [
                'document_id', 'canonical_document_key', 'source_file_path', 'source_file_hash',
                'normalized_content_hash', 'language_code', 'reason', 'source_kind', 'warnings',
            ], "exclusion row {$row}");
            $this->requireStrings($record, [
                'document_id', 'canonical_document_key', 'source_file_path', 'source_file_hash',
                'normalized_content_hash', 'language_code', 'reason', 'source_kind',
            ], "exclusion row {$row}");
            $this->requireHash($record['source_file_hash'], "exclusion row {$row} source_file_hash");
            $this->requireHash($record['normalized_content_hash'], "exclusion row {$row} normalized_content_hash");
            if ($record['language_code'] === 'en' || ! is_array($record['warnings']) || ! array_is_list($record['warnings'])) {
                throw new RuntimeException("governed_import.exclusion_row_{$row}.invalid");
            }
            $id = (string) $record['document_id'];
            $path = (string) $record['source_file_path'];
            if (isset($targets[$id]) && $targets[$id] !== $path) {
                throw new RuntimeException("governed_import.exclusion_row_{$row}.identity_not_unique");
            }
            $targets[$id] = $path;
        }

        return $targets;
    }

    /** @param list<array<string,mixed>> $records
     * @param  array<string,string>  $targets
     */
    private function validateDuplicates(array $records, array $targets): void
    {
        $sourcePaths = [];
        foreach ($records as $index => $record) {
            $row = $index + 1;
            $this->requireFields($record, [
                'kind', 'source_file_path', 'source_file_hash', 'normalized_content_hash', 'reason',
            ], "duplicate row {$row}");
            $this->requireStrings($record, [
                'kind', 'source_file_path', 'source_file_hash', 'normalized_content_hash', 'reason',
            ], "duplicate row {$row}");
            $this->requireHash($record['source_file_hash'], "duplicate row {$row} source_file_hash");
            $this->requireHash($record['normalized_content_hash'], "duplicate row {$row} normalized_content_hash");
            if (! in_array($record['kind'], ['exact', 'near', 'txt_mirror_excluded'], true)) {
                throw new RuntimeException("governed_import.duplicate_row_{$row}.kind_invalid");
            }
            if (isset($sourcePaths[$record['source_file_path']])) {
                throw new RuntimeException("governed_import.duplicate_row_{$row}.source_not_unique");
            }
            $sourcePaths[$record['source_file_path']] = true;

            $hasTargetId = $this->nonEmptyString($record['duplicate_of_document_id'] ?? null);
            $hasTargetPath = $this->nonEmptyString($record['duplicate_of_source_file_path'] ?? null);
            if ($hasTargetId !== $hasTargetPath || ($record['kind'] !== 'txt_mirror_excluded' && ! $hasTargetId)) {
                throw new RuntimeException("governed_import.duplicate_row_{$row}.target_missing");
            }
            if ($hasTargetId) {
                $targetId = (string) $record['duplicate_of_document_id'];
                $targetPath = (string) $record['duplicate_of_source_file_path'];
                if (($targets[$targetId] ?? null) !== $targetPath) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.target_invalid");
                }
            }
            if (array_key_exists('similarity', $record)) {
                if (! is_int($record['similarity']) && ! is_float($record['similarity'])) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.similarity_invalid");
                }
                if ($record['similarity'] < 0 || $record['similarity'] > 1) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.similarity_invalid");
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $records */
    private function validateKeywordTargets(array $records, int $libraryId): void
    {
        $nodeIds = [];
        $clusterIds = [];
        foreach ($records as $record) {
            $nodeIds = [...$nodeIds, ...$record['keyword_node_ids']];
            $clusterIds = [...$clusterIds, ...$record['keyword_cluster_ids']];
        }
        $nodeIds = array_values(array_unique($nodeIds));
        $clusterIds = array_values(array_unique($clusterIds));

        if ($nodeIds !== []) {
            $nodeLibraries = DB::table('keyword_nodes')
                ->leftJoin('keyword_node_keyword', 'keyword_node_keyword.node_id', '=', 'keyword_nodes.id')
                ->leftJoin('keywords', 'keywords.id', '=', 'keyword_node_keyword.keyword_id')
                ->whereIn('keyword_nodes.id', $nodeIds)
                ->get(['keyword_nodes.id', 'keywords.library_id'])
                ->groupBy('id');
            foreach ($nodeIds as $nodeId) {
                $libraryIds = $nodeLibraries->get($nodeId, collect())
                    ->pluck('library_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
                if ($libraryIds !== [$libraryId]) {
                    throw new RuntimeException("governed_import.keyword_node_{$nodeId}.library_invalid");
                }
            }
        }

        if ($clusterIds !== []) {
            $clusters = DB::table('keyword_clusters')
                ->leftJoin('keywords', 'keywords.id', '=', 'keyword_clusters.primary_keyword_id')
                ->whereIn('keyword_clusters.id', $clusterIds)
                ->get(['keyword_clusters.id', 'keywords.library_id'])
                ->keyBy('id');
            foreach ($clusterIds as $clusterId) {
                if ((int) ($clusters->get($clusterId)?->library_id ?? 0) !== $libraryId) {
                    throw new RuntimeException("governed_import.keyword_cluster_{$clusterId}.library_invalid");
                }
            }
        }
    }

    private function validateSummaryHashes(string $root, string $manifest, string $duplicates, string $exclusions): ?string
    {
        $path = $root.'/run_summary.json';
        if (! is_file($path)) {
            return null;
        }
        $path = $this->regularFile($path, 'run_summary');
        $summary = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($summary)) {
            throw new RuntimeException('governed_import.run_summary_invalid');
        }
        if (($summary['brand_key'] ?? null) !== self::BRAND_KEY || ($summary['site_key'] ?? null) !== self::SITE_KEY) {
            throw new RuntimeException('governed_import.run_summary_ownership_invalid');
        }
        $sourceSetHash = $summary['source_set_hash'] ?? null;
        $this->requireHash($sourceSetHash, 'run_summary source_set_hash');
        $expected = [
            'manifest.jsonl' => hash('sha256', $manifest),
            'duplicate_audit.jsonl' => hash('sha256', $duplicates),
        ];
        if (is_file($root.'/exclusion_audit.jsonl')) {
            $expected['exclusion_audit.jsonl'] = hash('sha256', $exclusions);
        }
        foreach ($expected as $name => $hash) {
            if (! hash_equals($hash, (string) ($summary['artifact_hashes'][$name] ?? ''))) {
                throw new RuntimeException("governed_import.run_summary_hash_mismatch.{$name}");
            }
        }

        return $sourceSetHash;
    }

    /** @return array{list<array<string,mixed>>,string} */
    private function readJsonLines(string $path, string $label): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("governed_import.{$label}_read_failed");
        }
        if ($bytes === '') {
            return [[], $bytes];
        }

        $lines = explode("\n", $bytes);
        if (end($lines) === '') {
            array_pop($lines);
        }
        $records = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                throw new RuntimeException("governed_import.{$label}_line_".($index + 1).'_empty');
            }
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException("governed_import.{$label}_line_".($index + 1).'_json_invalid', 0, $exception);
            }
            if (! is_array($record) || array_is_list($record)) {
                throw new RuntimeException("governed_import.{$label}_line_".($index + 1).'_object_required');
            }
            $records[] = $record;
        }

        return [$records, $bytes];
    }

    /** @param array<string,mixed> $record
     * @param  list<string>  $fields
     */
    private function requireFields(array $record, array $fields, string $label): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $record)) {
                throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}.{$field}_missing"));
            }
        }
    }

    /** @param array<string,mixed> $record
     * @param  list<string>  $fields
     */
    private function requireStrings(array $record, array $fields, string $label): void
    {
        foreach ($fields as $field) {
            if (! $this->nonEmptyString($record[$field] ?? null)) {
                throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}.{$field}_invalid"));
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function requireBoolean(array $record, string $field, string $label): void
    {
        if (! is_bool($record[$field] ?? null)) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}.{$field}_invalid"));
        }
    }

    private function requireHash(mixed $value, string $label): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_invalid"));
        }
    }

    private function validateOptionalDate(mixed $value, string $label, bool $allowTimestamp = false): void
    {
        if ($value === '') {
            return;
        }
        if (! is_string($value)) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_invalid"));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $valid = $date !== false && $date->format('Y-m-d') === $value;
        if (! $valid && $allowTimestamp) {
            try {
                new DateTimeImmutable($value);
                $valid = true;
            } catch (\Exception) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_invalid"));
        }
    }

    /** @return list<int> */
    private function normalizeIds(mixed $ids, string $label): array
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_invalid"));
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_invalid"));
            }
            $normalized[] = (int) $id;
        }

        return array_values(array_unique($normalized));
    }

    private function regularFile(string $path, string $label): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new RuntimeException("governed_import.{$label}_not_readable");
        }

        return $resolved;
    }

    private function packageFile(string $root, string $relativePath, string $label): string
    {
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || in_array('.', explode('/', $relativePath), true)
            || in_array('..', explode('/', $relativePath), true)
        ) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_path_invalid"));
        }
        $resolved = $this->regularFile($root.'/'.$relativePath, str_replace(' ', '_', $label));
        $root = realpath($root);
        if ($root === false || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}_path_escape"));
        }

        return $resolved;
    }

    private function stripFrontMatter(string $content): string
    {
        if (! str_starts_with($content, "---\n")) {
            return $content;
        }
        $end = strpos($content, "\n---\n", 4);
        if ($end === false) {
            throw new RuntimeException('governed_import.cleaned_front_matter_invalid');
        }

        return ltrim(substr($content, $end + 5), "\n");
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
