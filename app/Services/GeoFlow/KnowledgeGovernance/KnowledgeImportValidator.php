<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeSite;
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

    private const FRONT_MATTER_FIELDS = [
        'document_id', 'asset_key', 'canonical_document_key', 'version_label',
        'lifecycle_status', 'brand_key', 'site_key', 'directory_path',
        'language_code', 'authority_level', 'risk_level', 'review_status',
        'generation_eligible', 'citation_required', 'title', 'source_name',
        'source_url', 'source_type', 'source_file_path', 'source_file_hash',
        'normalized_content_hash', 'ingestion_method', 'retrieved_at',
        'effective_date', 'review_due_at', 'warnings',
    ];

    private const SUMMARY_VALIDATIONS = [
        'source_hashes_unchanged',
        'zero_active_exact_hash_duplicates',
        'no_unknown_owner',
        'english_only_manifest',
        'policy_eligibility_rules',
        'valid_duplicate_targets',
        'cleaned_manifest_paths_unique',
    ];

    private const REQUIRED_ARTIFACTS = [
        'manifest.jsonl',
        'duplicate_audit.jsonl',
        'exclusion_audit.jsonl',
        'near_duplicate_review.jsonl',
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
        $exclusionPath = $this->regularFile($root.'/exclusion_audit.jsonl', 'exclusion_audit');
        $nearReviewPath = $this->regularFile($root.'/near_duplicate_review.jsonl', 'near_duplicate_review');

        [$manifest, $manifestBytes] = $this->readJsonLines($manifestPath, 'manifest');
        [$duplicates, $duplicateBytes] = $this->readJsonLines($duplicatePath, 'duplicate_audit');
        [$exclusions, $exclusionBytes] = $this->readJsonLines($exclusionPath, 'exclusion_audit');
        [$nearReviews, $nearReviewBytes] = $this->readJsonLines($nearReviewPath, 'near_duplicate_review');

        if ($manifest === []) {
            throw new RuntimeException('governed_import.manifest_empty');
        }

        $this->validateDocuments($manifest, $root);
        [$targets, $paths] = $this->validateExclusions($exclusions);
        foreach ($manifest as $record) {
            $this->addIdentity($targets, $paths, $record, true, 'manifest');
        }
        $this->validateNearReviews($nearReviews, $targets, $paths);
        $this->validateDuplicates($duplicates, $targets, $paths);
        $this->validateKeywordTargets($manifest, $keywordLibraryId);
        $artifactBytes = [
            'manifest.jsonl' => $manifestBytes,
            'duplicate_audit.jsonl' => $duplicateBytes,
            'exclusion_audit.jsonl' => $exclusionBytes,
            'near_duplicate_review.jsonl' => $nearReviewBytes,
        ];
        $computedSourceSetHash = $this->sourceSetHash($manifest, $duplicates, $exclusions);
        $sourceSetHash = $this->validateSummary(
            $root,
            $artifactBytes,
            $manifest,
            $duplicates,
            $exclusions,
            $nearReviews,
            $computedSourceSetHash,
        );
        $this->preflightDatabase($manifest);
        $artifactHashes = [
            ...array_map(static fn (string $bytes): string => hash('sha256', $bytes), $artifactBytes),
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
            'source_checksum' => $sourceSetHash,
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
        $activeCanonicalKeys = [];

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
            if (! in_array($record['risk_level'], ['low', 'medium', 'high'], true)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.risk_invalid");
            }
            if (! in_array($record['review_status'], ['reviewed', 'unreviewed'], true)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.review_invalid");
            }
            if (! in_array($record['source_type'], ['official_web_page', 'compiled_report'], true)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.source_type_invalid");
            }
            if ($record['ingestion_method'] !== 'read_only_folder') {
                throw new RuntimeException("governed_import.manifest_row_{$row}.ingestion_method_invalid");
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
            if ($record['source_url'] !== '' && ! $this->isHttpUrl($record['source_url'])) {
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
                if (isset($activeCanonicalKeys[$record['canonical_document_key']])) {
                    throw new RuntimeException("governed_import.manifest_row_{$row}.active_canonical_key_not_unique");
                }
                $activeCanonicalKeys[$record['canonical_document_key']] = true;
            }

            $cleanedFile = $this->packageFile($root, $cleanedPath, "manifest row {$row} cleaned file");
            $bytes = file_get_contents($cleanedFile);
            if ($bytes === false || ! hash_equals((string) $record['cleaned_file_hash'], hash('sha256', $bytes))) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.cleaned_file_hash_mismatch");
            }
            [$frontMatter, $body] = $this->parseGovernedDocument($bytes, $row);
            foreach (self::FRONT_MATTER_FIELDS as $field) {
                if (! array_key_exists($field, $frontMatter) || $frontMatter[$field] !== $record[$field]) {
                    throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_{$field}_mismatch");
                }
            }
            $normalizedBody = $this->normalizeMarkdown($body);
            if ($normalizedBody !== $body) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.body_not_normalized");
            }
            $contentHash = hash('sha256', $normalizedBody);
            if (! hash_equals((string) $record['normalized_content_hash'], $contentHash)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.normalized_content_hash_mismatch");
            }
            $documentId = $this->documentId(
                (string) $record['brand_key'],
                (string) $record['site_key'],
                (string) $record['canonical_document_key'],
                $contentHash,
            );
            if (! hash_equals((string) $record['document_id'], $documentId)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.document_id_mismatch");
            }
            $record['_content'] = $body;
            $record['keyword_node_ids'] = $this->normalizeIds($record['keyword_node_ids'] ?? [], "manifest row {$row} keyword_node_ids");
            $record['keyword_cluster_ids'] = $this->normalizeIds($record['keyword_cluster_ids'] ?? [], "manifest row {$row} keyword_cluster_ids");
        }
        unset($record);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array{array<string,array{path:string,hash:string,source_hash:string,canonical_key:string,directory:?string,formal:bool}>,array<string,string>}
     */
    private function validateExclusions(array $records): array
    {
        $targets = [];
        $paths = [];
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
            if (
                ! in_array($record['language_code'], ['en', 'pt', 'zh', 'und'], true)
                || ! in_array($record['source_kind'], ['canonical_md', 'txt_mirror'], true)
                || ! is_array($record['warnings'])
                || ! array_is_list($record['warnings'])
            ) {
                throw new RuntimeException("governed_import.exclusion_row_{$row}.invalid");
            }
            foreach ($record['warnings'] as $warning) {
                if (! is_string($warning)) {
                    throw new RuntimeException("governed_import.exclusion_row_{$row}.invalid");
                }
            }
            $englishOrphan = $record['language_code'] === 'en'
                && $record['reason'] === 'txt_mirror_without_markdown_canonical'
                && $record['source_kind'] === 'txt_mirror';
            $nonEnglishSource = $record['language_code'] !== 'en'
                && $record['reason'] === 'non_english_initial_corpus';
            if (! $englishOrphan && ! $nonEnglishSource) {
                throw new RuntimeException("governed_import.exclusion_row_{$row}.reason_invalid");
            }
            $expectedId = $this->documentId(
                self::BRAND_KEY,
                self::SITE_KEY,
                (string) $record['canonical_document_key'],
                (string) $record['normalized_content_hash'],
            );
            if (! hash_equals($expectedId, (string) $record['document_id'])) {
                throw new RuntimeException("governed_import.exclusion_row_{$row}.document_id_mismatch");
            }
            $this->addIdentity($targets, $paths, $record, false, "exclusion row {$row}");
        }

        return [$targets, $paths];
    }

    /** @param list<array<string,mixed>> $records
     * @param  array<string,array{path:string,hash:string,source_hash:string,canonical_key:string,directory:?string,formal:bool}>  $targets
     * @param  array<string,string>  $paths
     */
    private function validateDuplicates(array $records, array $targets, array $paths): void
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

            $sourceIdentityId = $paths[$record['source_file_path']] ?? null;
            if ($sourceIdentityId !== null) {
                $sourceIdentity = $targets[$sourceIdentityId];
                if ($sourceIdentity['formal']) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.source_reuses_manifest");
                }
                if (
                    ! hash_equals($sourceIdentity['source_hash'], (string) $record['source_file_hash'])
                    || ! hash_equals($sourceIdentity['hash'], (string) $record['normalized_content_hash'])
                ) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.source_identity_mismatch");
                }
            }

            $hasTargetId = $this->nonEmptyString($record['duplicate_of_document_id'] ?? null);
            $hasTargetPath = $this->nonEmptyString($record['duplicate_of_source_file_path'] ?? null);
            if ($hasTargetId !== $hasTargetPath || ($record['kind'] !== 'txt_mirror_excluded' && ! $hasTargetId)) {
                throw new RuntimeException("governed_import.duplicate_row_{$row}.target_missing");
            }
            if ($hasTargetId) {
                $targetId = (string) $record['duplicate_of_document_id'];
                $targetPath = (string) $record['duplicate_of_source_file_path'];
                if ($record['source_file_path'] === $targetPath || $sourceIdentityId === $targetId) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.self_target");
                }
                $target = $targets[$targetId] ?? null;
                if ($target === null || $target['path'] !== $targetPath) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.target_invalid");
                }
                if ($record['kind'] === 'exact' && ! hash_equals($target['hash'], (string) $record['normalized_content_hash'])) {
                    throw new RuntimeException("governed_import.duplicate_row_{$row}.exact_hash_mismatch");
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

    /**
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,array{path:string,hash:string,source_hash:string,canonical_key:string,directory:?string,formal:bool}>  $targets
     * @param  array<string,string>  $paths
     */
    private function validateNearReviews(array $records, array $targets, array $paths): void
    {
        $pairs = [];
        foreach ($records as $index => $record) {
            $row = $index + 1;
            $required = ['kind', 'action', 'source_file_paths', 'reason', 'similarity'];
            $this->requireFields($record, $required, "near review row {$row}");
            if (array_diff(array_keys($record), $required) !== []) {
                throw new RuntimeException("governed_import.near_review_row_{$row}.field_invalid");
            }
            if (
                ($record['kind'] ?? null) !== 'near'
                || ($record['action'] ?? null) !== 'review'
                || ! in_array($record['reason'] ?? null, [
                    'near_duplicate_requires_review',
                    'strict_subset_canonical_lacks_content',
                ], true)
                || (! is_int($record['similarity'] ?? null) && ! is_float($record['similarity'] ?? null))
                || ! is_finite((float) $record['similarity'])
                || $record['similarity'] < 0.92
                || $record['similarity'] > 1
            ) {
                throw new RuntimeException("governed_import.near_review_row_{$row}.resolution_invalid");
            }
            $sourcePaths = $record['source_file_paths'] ?? null;
            if (! is_array($sourcePaths) || ! array_is_list($sourcePaths) || count($sourcePaths) !== 2) {
                throw new RuntimeException("governed_import.near_review_row_{$row}.paths_invalid");
            }
            foreach ($sourcePaths as $sourcePath) {
                if (! $this->nonEmptyString($sourcePath)) {
                    throw new RuntimeException("governed_import.near_review_row_{$row}.paths_invalid");
                }
            }
            $sortedPaths = $sourcePaths;
            sort($sortedPaths, SORT_STRING);
            if ($sourcePaths !== $sortedPaths || $sourcePaths[0] === $sourcePaths[1]) {
                throw new RuntimeException("governed_import.near_review_row_{$row}.paths_invalid");
            }
            $pairKey = implode("\0", $sourcePaths);
            if (isset($pairs[$pairKey])) {
                throw new RuntimeException("governed_import.near_review_row_{$row}.pair_not_unique");
            }
            $pairs[$pairKey] = true;

            $firstId = $paths[$sourcePaths[0]] ?? null;
            $secondId = $paths[$sourcePaths[1]] ?? null;
            $first = $firstId === null ? null : $targets[$firstId];
            $second = $secondId === null ? null : $targets[$secondId];
            if (
                $first === null
                || $second === null
                || ! $first['formal']
                || ! $second['formal']
                || $first['directory'] !== $second['directory']
                || $first['canonical_key'] === $second['canonical_key']
                || hash_equals($first['hash'], $second['hash'])
            ) {
                throw new RuntimeException("governed_import.near_review_row_{$row}.identity_scope_invalid");
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

    /**
     * @param  array<string,string>  $artifactBytes
     * @param  list<array<string,mixed>>  $manifest
     * @param  list<array<string,mixed>>  $duplicates
     * @param  list<array<string,mixed>>  $exclusions
     * @param  list<array<string,mixed>>  $nearReviews
     */
    private function validateSummary(
        string $root,
        array $artifactBytes,
        array $manifest,
        array $duplicates,
        array $exclusions,
        array $nearReviews,
        string $computedSourceSetHash,
    ): string {
        $path = $this->regularFile($root.'/run_summary.json', 'run_summary');
        try {
            $summary = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('governed_import.run_summary_invalid', 0, $exception);
        }
        if (! is_array($summary) || array_is_list($summary) || ($summary['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('governed_import.run_summary_schema_invalid');
        }
        if (($summary['brand_key'] ?? null) !== self::BRAND_KEY || ($summary['site_key'] ?? null) !== self::SITE_KEY) {
            throw new RuntimeException('governed_import.run_summary_ownership_invalid');
        }
        $sourceSetHash = $summary['source_set_hash'] ?? null;
        $this->requireHash($sourceSetHash, 'run_summary source_set_hash');
        if (! hash_equals($computedSourceSetHash, $sourceSetHash)) {
            throw new RuntimeException('governed_import.run_summary_source_set_hash_mismatch');
        }
        foreach (self::SUMMARY_VALIDATIONS as $validation) {
            if (($summary['validation'][$validation] ?? null) !== true) {
                throw new RuntimeException("governed_import.run_summary_validation_failed.{$validation}");
            }
        }

        $expectedCounts = [
            ...$this->sourceCounts($manifest, $duplicates, $exclusions, $nearReviews),
            'manifest_documents' => count($manifest),
            'active_documents' => count(array_filter($manifest, static fn (array $record): bool => $record['lifecycle_status'] === 'active')),
            'superseded_documents' => count(array_filter($manifest, static fn (array $record): bool => $record['lifecycle_status'] === 'superseded')),
            'duplicate_records' => count($duplicates),
            'near_review_records' => count($nearReviews),
            'excluded_sources' => count($exclusions),
        ];
        foreach ($expectedCounts as $name => $count) {
            if (($summary['counts'][$name] ?? null) !== $count) {
                throw new RuntimeException("governed_import.run_summary_count_mismatch.{$name}");
            }
        }
        $declaredHashes = $summary['artifact_hashes'] ?? null;
        if (! is_array($declaredHashes) || array_is_list($declaredHashes)) {
            throw new RuntimeException('governed_import.run_summary_artifact_hashes_invalid');
        }
        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            if (! array_key_exists($artifact, $declaredHashes)) {
                throw new RuntimeException("governed_import.run_summary_artifact_missing.{$artifact}");
            }
        }
        foreach ($declaredHashes as $artifact => $declaredHash) {
            if (! is_string($artifact) || basename($artifact) !== $artifact || str_contains($artifact, '..')) {
                throw new RuntimeException('governed_import.run_summary_artifact_name_invalid');
            }
            $this->requireHash($declaredHash, "run_summary artifact {$artifact}");
            $bytes = $artifactBytes[$artifact] ?? null;
            if ($bytes === null) {
                $artifactPath = $this->packageFile($root, $artifact, "run_summary artifact {$artifact}");
                $bytes = file_get_contents($artifactPath);
                if ($bytes === false) {
                    throw new RuntimeException("governed_import.run_summary_artifact_read_failed.{$artifact}");
                }
            }
            if (! hash_equals($declaredHash, hash('sha256', $bytes))) {
                throw new RuntimeException("governed_import.run_summary_hash_mismatch.{$artifact}");
            }
        }

        return $sourceSetHash;
    }

    /**
     * @param  list<array<string,mixed>>  $manifest
     * @param  list<array<string,mixed>>  $duplicates
     * @param  list<array<string,mixed>>  $exclusions
     */
    private function sourceSetHash(array $manifest, array $duplicates, array $exclusions): string
    {
        $mapping = [];
        foreach ([...$manifest, ...$exclusions, ...$duplicates] as $record) {
            $path = (string) $record['source_file_path'];
            $mapping[$path] ??= (string) $record['source_file_hash'];
        }
        ksort($mapping, SORT_STRING);

        return hash('sha256', json_encode(
            $mapping,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $manifest
     * @param  list<array<string,mixed>>  $duplicates
     * @param  list<array<string,mixed>>  $exclusions
     * @param  list<array<string,mixed>>  $nearReviews
     * @return array{canonical_source_files:int,txt_mirror_files:int,source_files_hashed:int}
     */
    private function sourceCounts(array $manifest, array $duplicates, array $exclusions, array $nearReviews): array
    {
        $paths = [];
        foreach ([...$manifest, ...$duplicates, ...$exclusions] as $record) {
            $path = $record['source_file_path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[$path] = true;
            }
        }
        foreach ($nearReviews as $record) {
            foreach (($record['source_file_paths'] ?? []) as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[$path] = true;
                }
            }
        }

        $canonical = 0;
        $mirrors = 0;
        foreach (array_keys($paths) as $path) {
            match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
                'md' => $canonical++,
                'txt' => $mirrors++,
                default => throw new RuntimeException('governed_import.run_summary_source_path_invalid'),
            };
        }

        return [
            'canonical_source_files' => $canonical,
            'txt_mirror_files' => $mirrors,
            'source_files_hashed' => count($paths),
        ];
    }

    /** @param list<array<string,mixed>> $manifest */
    private function preflightDatabase(array $manifest): void
    {
        $assets = array_column($manifest, 'asset_key');
        $existingAssets = KnowledgeBase::query()
            ->with(['brand:id,key', 'site:id,key,brand_id'])
            ->whereIn('asset_key', $assets)
            ->get()
            ->keyBy('asset_key');
        foreach ($manifest as $record) {
            $existing = $existingAssets->get($record['asset_key']);
            if ($existing === null) {
                continue;
            }
            if (
                $existing->brand?->key !== self::BRAND_KEY
                || $existing->site?->key !== self::SITE_KEY
                || $existing->canonical_document_key !== $record['canonical_document_key']
                || $existing->normalized_content_hash !== $record['normalized_content_hash']
                || $existing->content !== $record['_content']
            ) {
                throw new RuntimeException('governed_import.preflight_existing_asset_conflict');
            }
        }

        $brand = KnowledgeBrand::query()->where('key', self::BRAND_KEY)->first(['id']);
        if ($brand === null) {
            return;
        }
        $site = KnowledgeSite::query()
            ->where('brand_id', $brand->id)
            ->where('key', self::SITE_KEY)
            ->first(['id']);
        foreach ($manifest as $record) {
            if ($record['lifecycle_status'] !== 'active') {
                continue;
            }
            $hashConflict = KnowledgeBase::query()
                ->where('brand_id', $brand->id)
                ->where('lifecycle_status', 'active')
                ->whereNotIn('asset_key', $assets)
                ->where('language_code', $record['language_code'])
                ->where('normalized_content_hash', $record['normalized_content_hash'])
                ->exists();
            $canonicalConflict = $site !== null && KnowledgeBase::query()
                ->where('brand_id', $brand->id)
                ->where('site_id', $site->id)
                ->where('lifecycle_status', 'active')
                ->whereNotIn('asset_key', $assets)
                ->where('canonical_document_key', $record['canonical_document_key'])
                ->exists();
            if ($hashConflict || $canonicalConflict) {
                throw new RuntimeException('governed_import.preflight_active_document_conflict');
            }
        }
    }

    /**
     * @param  array<string,array{path:string,hash:string,source_hash:string,canonical_key:string,directory:?string,formal:bool}>  $targets
     * @param  array<string,string>  $paths
     * @param  array<string,mixed>  $record
     */
    private function addIdentity(array &$targets, array &$paths, array $record, bool $formal, string $label): void
    {
        $id = (string) $record['document_id'];
        $path = (string) $record['source_file_path'];
        if (isset($targets[$id]) || isset($paths[$path])) {
            throw new RuntimeException(str_replace(' ', '_', "governed_import.{$label}.identity_collision"));
        }
        $targets[$id] = [
            'path' => $path,
            'hash' => (string) $record['normalized_content_hash'],
            'source_hash' => (string) $record['source_file_hash'],
            'canonical_key' => (string) $record['canonical_document_key'],
            'directory' => $formal ? (string) $record['directory_path'] : null,
            'formal' => $formal,
        ];
        $paths[$path] = $id;
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

    /** @return array{array<string,mixed>,string} */
    private function parseGovernedDocument(string $content, int $row): array
    {
        if (! str_starts_with($content, "---\n")) {
            throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_required");
        }
        $end = strpos($content, "\n---\n\n", 4);
        if ($end === false) {
            throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_invalid");
        }
        $frontMatter = [];
        foreach (explode("\n", substr($content, 4, $end - 4)) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_invalid");
            }
            $field = substr($line, 0, $separator);
            $encoded = ltrim(substr($line, $separator + 1));
            if ($field === '' || array_key_exists($field, $frontMatter)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_invalid");
            }
            try {
                $value = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_json_invalid", 0, $exception);
            }
            if (is_array($value) && ! array_is_list($value)) {
                throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_value_invalid");
            }
            $frontMatter[$field] = $value;
        }
        if (array_diff(array_keys($frontMatter), self::FRONT_MATTER_FIELDS) !== []) {
            throw new RuntimeException("governed_import.manifest_row_{$row}.front_matter_field_invalid");
        }

        return [$frontMatter, substr($content, $end + 6)];
    }

    private function normalizeMarkdown(string $raw): string
    {
        if (preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('governed_import.cleaned_document_utf8_invalid');
        }
        $normalized = \Normalizer::normalize($raw, \Normalizer::FORM_C);
        if ($normalized === false) {
            throw new RuntimeException('governed_import.cleaned_document_unicode_normalization_failed');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $normalized);
        $lines = [];
        $previousBlank = false;
        foreach (explode("\n", $text) as $sourceLine) {
            $line = $this->rstripUnicode($sourceLine);
            if (preg_match('/^\s{0,3}#{1,6}\s*$/u', $line) === 1 || preg_match('/^\s*\\\\\s*$/u', $line) === 1) {
                if ($lines !== [] && end($lines) !== '') {
                    $lines[] = '';
                    $previousBlank = true;
                }

                continue;
            }
            $line = $this->normalizeInlineLinkWrappers($line);
            $blank = $line === '';
            if ($blank && $previousBlank) {
                continue;
            }
            $lines[] = $line;
            $previousBlank = $blank;
        }
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $markdown = implode("\n", $lines);

        return $markdown === '' ? '' : $markdown."\n";
    }

    private function normalizeInlineLinkWrappers(string $line): string
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new RuntimeException('governed_import.cleaned_document_utf8_invalid');
        }
        $rendered = '';
        $position = 0;
        $length = count($characters);
        while (true) {
            $start = $this->findCharacter($characters, '【', $position);
            if ($start === null) {
                return $rendered.implode('', array_slice($characters, $position));
            }
            $closeLabel = $this->findCharacter($characters, '】', $start + 1);
            if ($closeLabel === null) {
                return $rendered.implode('', array_slice($characters, $position));
            }
            $opener = $closeLabel + 1;
            while ($opener < $length && $this->isUnicodeWhitespace($characters[$opener])) {
                $opener++;
            }
            if ($opener === $length || ! in_array($characters[$opener], ['(', '（'], true)) {
                $rendered .= implode('', array_slice($characters, $position, $closeLabel + 1 - $position));
                $position = $closeLabel + 1;

                continue;
            }
            $closeDestination = $this->findWrapperClose($characters, $opener);
            if ($closeDestination === null) {
                return $rendered.implode('', array_slice($characters, $position));
            }
            $destination = $this->stripUnicode(implode('', array_slice(
                $characters,
                $opener + 1,
                $closeDestination - $opener - 1,
            )));
            if ($this->isHttpUrl($destination) && ! $this->containsUnicodeWhitespace($destination)) {
                $label = $this->stripUnicode(implode('', array_slice(
                    $characters,
                    $start + 1,
                    $closeLabel - $start - 1,
                )));
                $rendered .= implode('', array_slice($characters, $position, $start - $position));
                $rendered .= '['.$label.']('.$destination.')';
            } else {
                $rendered .= implode('', array_slice(
                    $characters,
                    $position,
                    $closeDestination + 1 - $position,
                ));
            }
            $position = $closeDestination + 1;
        }
    }

    /** @param list<string> $characters */
    private function findCharacter(array $characters, string $needle, int $offset): ?int
    {
        for ($index = $offset, $length = count($characters); $index < $length; $index++) {
            if ($characters[$index] === $needle) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string> $characters */
    private function findWrapperClose(array $characters, int $opener): ?int
    {
        [$opening, $closing] = $characters[$opener] === '(' ? ['(', ')'] : ['（', '）'];
        $depth = 0;
        for ($index = $opener, $length = count($characters); $index < $length; $index++) {
            if ($characters[$index] === $opening) {
                $depth++;
            } elseif ($characters[$index] === $closing) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function rstripUnicode(string $value): string
    {
        return preg_replace('/[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u', '', $value) ?? $value;
    }

    private function stripUnicode(string $value): string
    {
        return preg_replace('/^[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u', '', $value) ?? $value;
    }

    private function isUnicodeWhitespace(string $character): bool
    {
        return preg_match('/^[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]$/u', $character) === 1;
    }

    private function containsUnicodeWhitespace(string $value): bool
    {
        return preg_match('/[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', $value) === 1;
    }

    private function documentId(string $brand, string $site, string $canonicalKey, string $contentHash): string
    {
        return substr(hash('sha256', implode("\0", [$brand, $site, $canonicalKey, $contentHash])), 0, 32);
    }

    private function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
