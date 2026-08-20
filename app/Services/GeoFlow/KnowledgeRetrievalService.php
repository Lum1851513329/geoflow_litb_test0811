<?php

namespace App\Services\GeoFlow;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 知识库混合召回与证据上下文拼装。
 *
 * 召回不只依赖向量分数：同时考虑关键词、标题/章节、来源元数据、审核状态和资料时效。
 */
class KnowledgeRetrievalService
{
    private const FULL_SCAN_CHUNK_LIMIT = 500;

    private const MAX_PREFILTER_ROWS = 300;

    private const MAX_PREFILTER_TERMS = 12;

    public function __construct(private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService) {}

    public function retrieveContext(KnowledgeScope $scope, int $knowledgeBaseId, string $query, int $limit = 5, int $maxChars = 3200): string
    {
        return $this->composeEvidenceContext(
            $this->retrieveEvidence($scope, $knowledgeBaseId, $query, max($limit * 4, 16)),
            $limit,
            $maxChars
        );
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     */
    public function retrieveContextFromMany(KnowledgeScope $scope, array $knowledgeBaseIds, string $query, int $limit = 5, int $maxChars = 3200): string
    {
        return $this->composeEvidenceContext(
            $this->retrieveEvidenceFromMany($scope, $knowledgeBaseIds, $query, max($limit * 4, 16)),
            $limit,
            $maxChars
        );
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @return list<array<string,mixed>>
     */
    public function retrieveEvidenceFromMany(KnowledgeScope $scope, array $knowledgeBaseIds, string $query, int $candidateLimit = 16): array
    {
        $knowledgeBaseIds = collect($knowledgeBaseIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(5)
            ->values()
            ->all();

        if ($knowledgeBaseIds === []) {
            return [];
        }

        if (count($knowledgeBaseIds) === 1) {
            return $this->retrieveEvidence($scope, $knowledgeBaseIds[0], $query, $candidateLimit);
        }

        $perBaseLimit = max(6, (int) ceil(max(1, $candidateLimit) / count($knowledgeBaseIds)) + 4);
        $merged = [];

        foreach ($knowledgeBaseIds as $order => $knowledgeBaseId) {
            foreach ($this->retrieveEvidence($scope, $knowledgeBaseId, $query, $perBaseLimit) as $candidate) {
                $candidate['knowledge_base_rank'] = $order;
                $merged[] = $candidate;
            }
        }

        if ($merged === []) {
            return [];
        }

        $merged = $this->resolveEvidenceConflicts($merged);
        usort($merged, fn (array $left, array $right): int => $this->compareRankedEvidence($left, $right));

        return $this->withEvidenceReferences(array_slice($merged, 0, max(1, $candidateLimit)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function retrieveEvidence(KnowledgeScope $scope, int $knowledgeBaseId, string $query, int $candidateLimit = 16): array
    {
        /** @var KnowledgeBase|null $knowledgeBase */
        $knowledgeBase = $this->scopedKnowledgeBaseQuery($scope)
            ->whereKey($knowledgeBaseId)
            ->with('primaryDirectory:id,key,path')
            ->first($this->knowledgeBaseSelectColumns());

        if (! $knowledgeBase) {
            return [];
        }

        $queryTerms = $this->termFrequencies($query);
        $pgvectorScores = trim($query) !== ''
            ? $this->fetchPgvectorScores($scope, $knowledgeBaseId, $query, max($candidateLimit, 16))
            : [];

        $rows = $this->loadCandidateRows($scope, $knowledgeBaseId, $queryTerms, $pgvectorScores, $candidateLimit);
        if ($rows === []) {
            return [];
        }

        $hasRealEmbeddingRows = $pgvectorScores === [] && $this->knowledgeBaseHasRealEmbeddingRows($scope, $knowledgeBaseId);
        $queryVector = [];
        $useRealEmbeddingScore = false;
        if ($pgvectorScores === [] && $hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->buildFallbackVector($query, 256);
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $metadata = $this->mergeMetadata($this->baseMetadata($knowledgeBase), $this->decodeMetadata((string) ($row->metadata_json ?? '')));
            if ($this->shouldExcludeByGovernance($metadata)) {
                continue;
            }

            $chunkIndex = (int) ($row->chunk_index ?? 0);
            $title = trim((string) ($row->chunk_title ?? ''));
            $sectionPath = trim((string) ($row->section_path ?? ''));
            $searchText = implode("\n", array_filter([
                $title,
                $sectionPath,
                (string) ($metadata['source_name'] ?? ''),
                (string) ($metadata['business_line'] ?? ''),
                $content,
            ]));

            $lexicalScore = $this->lexicalScore($queryTerms, $this->termFrequencies($searchText));
            $titleScore = $this->lexicalScore($queryTerms, $this->termFrequencies($title."\n".$sectionPath));
            $metadataScore = $this->metadataScore($metadata);
            $vectorScore = $pgvectorScores[$chunkIndex] ?? $this->localVectorScore($row, $queryVector, $useRealEmbeddingScore);
            $score = ($vectorScore * 0.45) + ($lexicalScore * 0.35) + ($titleScore * 0.12) + ($metadataScore * 0.08);

            $scored[] = [
                'chunk_index' => $chunkIndex,
                'content' => $content,
                'chunk_title' => $title,
                'section_path' => $sectionPath,
                'metadata' => $metadata,
                'score' => $score,
                'vector_score' => $vectorScore,
                'keyword_score' => $lexicalScore,
                'title_score' => $titleScore,
                'metadata_score' => $metadataScore,
            ];
        }

        $scored = $this->resolveEvidenceConflicts($scored);
        usort($scored, fn (array $left, array $right): int => $this->compareRankedEvidence($left, $right));

        return $this->withEvidenceReferences(array_slice($scored, 0, max(1, $candidateLimit)));
    }

    /**
     * @param  list<array<string,mixed>>  $evidence
     */
    private function composeEvidenceContext(array $evidence, int $limit, int $maxChars): string
    {
        if ($evidence === []) {
            return '';
        }

        $parts = [];
        $charCount = 0;
        foreach ($evidence as $candidate) {
            if (count($parts) >= max(1, $limit)) {
                break;
            }

            $content = trim((string) ($candidate['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }

            $citation = trim((string) ($candidate['citation_id'] ?? ''));
            if ($citation === '') {
                $citation = 'K'.(count($parts) + 1);
            }
            $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
            $provenance = is_array($candidate['provenance'] ?? null) ? $candidate['provenance'] : [];
            $lines = ['【证据 '.$citation.'】'];

            $knowledgeBaseName = trim((string) ($metadata['knowledge_base_name'] ?? ''));
            if ($knowledgeBaseName !== '') {
                $lines[] = '知识库：'.$knowledgeBaseName;
            }

            $title = trim((string) ($candidate['chunk_title'] ?? ''));
            if ($title !== '') {
                $lines[] = '标题：'.$title;
            }

            $sectionPath = trim((string) ($candidate['section_path'] ?? ''));
            if ($sectionPath !== '') {
                $lines[] = '章节：'.$sectionPath;
            }

            $sourceName = trim((string) ($provenance['source_name'] ?? $metadata['source_name'] ?? $metadata['knowledge_base_name'] ?? ''));
            if ($sourceName !== '') {
                $lines[] = '来源：'.$sourceName;
            }

            $sourceUrl = trim((string) ($provenance['source_url'] ?? $metadata['source_url'] ?? ''));
            if ($sourceUrl !== '') {
                $lines[] = '链接：'.$sourceUrl;
            }

            $effectiveDate = trim((string) ($metadata['effective_date'] ?? ''));
            if ($effectiveDate !== '') {
                $lines[] = '时间：'.$effectiveDate;
            }

            $businessLine = trim((string) ($metadata['business_line'] ?? ''));
            if ($businessLine !== '') {
                $lines[] = '业务线：'.$businessLine;
            }

            $riskLevel = trim((string) ($metadata['risk_level'] ?? ''));
            $reviewStatus = trim((string) ($metadata['review_status'] ?? ''));
            if ($riskLevel !== '' || $reviewStatus !== '') {
                $lines[] = '治理：'.trim(($riskLevel !== '' ? '风险='.$riskLevel : '').($reviewStatus !== '' ? ' 审核='.$reviewStatus : ''));
            }

            $conflictMergedCount = (int) ($candidate['conflict_merged_count'] ?? 0);
            if ($conflictMergedCount > 0) {
                $lines[] = '冲突处理：已优先采用较新或已审核资料，忽略 '.$conflictMergedCount.' 条同主题旧资料。';
            }

            $lines[] = '内容：';
            $lines[] = $content;
            $parts[] = implode("\n", $lines);
            $charCount = $nextLength;
        }

        if ($parts === []) {
            return '';
        }

        return "【知识库证据】\n".implode("\n\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseMetadata(KnowledgeBase $knowledgeBase): array
    {
        return array_filter([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'knowledge_base_name' => (string) $knowledgeBase->name,
            'knowledge_base_description' => trim((string) ($knowledgeBase->description ?? '')),
            'source_name' => trim((string) ($knowledgeBase->source_name ?? '')),
            'source_url' => trim((string) ($knowledgeBase->source_url ?? '')),
            'source_type' => trim((string) ($knowledgeBase->source_type ?? 'document')),
            'business_line' => trim((string) ($knowledgeBase->business_line ?? '')),
            'effective_date' => $knowledgeBase->effective_date?->toDateString(),
            'risk_level' => trim((string) ($knowledgeBase->risk_level ?? 'medium')),
            'review_status' => trim((string) ($knowledgeBase->review_status ?? 'unreviewed')),
            'brand_id' => (int) $knowledgeBase->brand_id,
            'site_id' => (int) $knowledgeBase->site_id,
            'primary_directory_id' => (int) $knowledgeBase->primary_directory_id,
            'directory_key' => trim((string) ($knowledgeBase->primaryDirectory?->key ?? '')),
            'directory_path' => trim((string) ($knowledgeBase->primaryDirectory?->path ?? '')),
            'canonical_document_key' => trim((string) ($knowledgeBase->canonical_document_key ?? '')),
            'language_code' => trim((string) ($knowledgeBase->language_code ?? '')),
            'authority_level' => trim((string) ($knowledgeBase->authority_level ?? '')),
            'retrieved_at' => $knowledgeBase->retrieved_at?->toAtomString(),
            'review_due_at' => $knowledgeBase->review_due_at?->toDateString(),
            'version_label' => trim((string) ($knowledgeBase->version_label ?? '')),
            'lifecycle_status' => trim((string) ($knowledgeBase->lifecycle_status ?? '')),
            'generation_eligible' => (bool) $knowledgeBase->generation_eligible,
            'citation_required' => (bool) $knowledgeBase->citation_required,
            'source_file_path' => trim((string) ($knowledgeBase->source_file_path ?? '')),
            'source_file_hash' => trim((string) ($knowledgeBase->source_file_hash ?? '')),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    private function knowledgeBaseSelectColumns(): array
    {
        $columns = ['id', 'name', 'description'];
        foreach ([
            'source_name',
            'source_url',
            'source_type',
            'business_line',
            'effective_date',
            'risk_level',
            'review_status',
            'brand_id',
            'site_id',
            'primary_directory_id',
            'canonical_document_key',
            'language_code',
            'authority_level',
            'retrieved_at',
            'review_due_at',
            'version_label',
            'lifecycle_status',
            'generation_eligible',
            'citation_required',
            'source_file_path',
            'source_file_hash',
        ] as $column) {
            if (Schema::hasColumn('knowledge_bases', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMetadata(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    private function mergeMetadata(array $base, array $chunk): array
    {
        $chunkSpecific = array_intersect_key(
            array_filter($chunk, static fn ($value): bool => $value !== null && $value !== ''),
            array_flip(['block_indexes', 'source_block_indexes']),
        );

        return array_replace($chunkSpecific, $base);
    }

    /**
     * @return Builder<KnowledgeBase>
     */
    private function scopedKnowledgeBaseQuery(KnowledgeScope $scope): Builder
    {
        return $this->applyKnowledgeScope(KnowledgeBase::query(), $scope);
    }

    /**
     * @param  Builder<KnowledgeBase>  $query
     * @return Builder<KnowledgeBase>
     */
    private function applyKnowledgeScope(Builder $query, KnowledgeScope $scope): Builder
    {
        return $query
            ->where('brand_id', $scope->brandId)
            ->where('site_id', $scope->siteId)
            ->where('language_code', $scope->languageCode)
            ->where('lifecycle_status', 'active')
            ->where('generation_eligible', true)
            ->when(
                $scope->directoryId !== null,
                fn (Builder $query): Builder => $query->where('primary_directory_id', $scope->directoryId),
            )
            ->when(
                $scope->keywordClusterId !== null,
                fn (Builder $query): Builder => $query->whereHas(
                    'keywordClusters',
                    fn (Builder $clusterQuery): Builder => $clusterQuery->whereKey($scope->keywordClusterId),
                ),
            );
    }

    /**
     * @return Builder<KnowledgeChunk>
     */
    private function scopedChunkQuery(KnowledgeScope $scope, int $knowledgeBaseId): Builder
    {
        return KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereHas(
                'knowledgeBase',
                fn (Builder $query): Builder => $this->applyKnowledgeScope($query, $scope),
            );
    }

    /**
     * 大知识库先做候选集预筛，避免每次召回都把单个知识库全部切片加载到 PHP。
     *
     * @param  array<string,int>  $queryTerms
     * @param  array<int,float>  $pgvectorScores
     * @return list<KnowledgeChunk>
     */
    private function loadCandidateRows(KnowledgeScope $scope, int $knowledgeBaseId, array $queryTerms, array $pgvectorScores, int $candidateLimit): array
    {
        $chunkCount = $this->scopedChunkQuery($scope, $knowledgeBaseId)->count();

        if ($chunkCount <= self::FULL_SCAN_CHUNK_LIMIT) {
            return $this->scopedChunkQuery($scope, $knowledgeBaseId)
                ->orderBy('chunk_index')
                ->get($this->knowledgeChunkSelectColumns())
                ->all();
        }

        $rowsByIndex = [];
        foreach ($this->fetchRowsByChunkIndexes($scope, $knowledgeBaseId, array_keys($pgvectorScores)) as $row) {
            $rowsByIndex[(int) ($row->chunk_index ?? 0)] = $row;
        }

        foreach ($this->fetchKeywordCandidateRows($scope, $knowledgeBaseId, $queryTerms, $candidateLimit) as $row) {
            $rowsByIndex[(int) ($row->chunk_index ?? 0)] = $row;
        }

        ksort($rowsByIndex);

        return array_values($rowsByIndex);
    }

    /**
     * @return list<string>
     */
    private function knowledgeChunkSelectColumns(): array
    {
        return [
            'chunk_index',
            'content',
            'chunk_title',
            'section_path',
            'metadata_json',
            'embedding_json',
            'embedding_model_id',
            'embedding_dimensions',
        ];
    }

    /**
     * @param  list<int>  $chunkIndexes
     * @return list<KnowledgeChunk>
     */
    private function fetchRowsByChunkIndexes(KnowledgeScope $scope, int $knowledgeBaseId, array $chunkIndexes): array
    {
        $chunkIndexes = array_values(array_unique(array_map('intval', $chunkIndexes)));
        if ($chunkIndexes === []) {
            return [];
        }

        return $this->scopedChunkQuery($scope, $knowledgeBaseId)
            ->whereIn('chunk_index', $chunkIndexes)
            ->orderBy('chunk_index')
            ->get($this->knowledgeChunkSelectColumns())
            ->all();
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @return list<KnowledgeChunk>
     */
    private function fetchKeywordCandidateRows(KnowledgeScope $scope, int $knowledgeBaseId, array $queryTerms, int $candidateLimit): array
    {
        $terms = $this->candidateQueryTerms($queryTerms);
        if ($terms === []) {
            return [];
        }

        return $this->scopedChunkQuery($scope, $knowledgeBaseId)
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.mb_strtolower($term, 'UTF-8').'%';
                    $query->orWhere(function ($termQuery) use ($like): void {
                        $termQuery
                            ->whereRaw("LOWER(COALESCE(content, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(chunk_title, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(section_path, '')) LIKE ?", [$like]);
                    });
                }
            })
            ->orderBy('chunk_index')
            ->limit($this->prefilterRowLimit($candidateLimit))
            ->get($this->knowledgeChunkSelectColumns())
            ->all();
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @return list<string>
     */
    private function candidateQueryTerms(array $queryTerms): array
    {
        $terms = array_filter(
            array_keys($queryTerms),
            static fn (string $term): bool => mb_strlen($term, 'UTF-8') > 1
        );

        usort($terms, static function (string $left, string $right): int {
            $lengthDiff = mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8');

            return $lengthDiff !== 0 ? $lengthDiff : strcmp($left, $right);
        });

        return array_slice(array_values($terms), 0, self::MAX_PREFILTER_TERMS);
    }

    private function prefilterRowLimit(int $candidateLimit): int
    {
        return min(self::MAX_PREFILTER_ROWS, max(80, max(1, $candidateLimit) * 12));
    }

    private function knowledgeBaseHasRealEmbeddingRows(KnowledgeScope $scope, int $knowledgeBaseId): bool
    {
        return $this->scopedChunkQuery($scope, $knowledgeBaseId)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_model_id', '>', 0)
            ->where('embedding_dimensions', '>', 0)
            ->exists();
    }

    /**
     * 同主题证据只保留最可信版本，避免新旧资料同时进入提示词造成生成冲突。
     *
     * @param  list<array<string,mixed>>  $scored
     * @return list<array<string,mixed>>
     */
    private function resolveEvidenceConflicts(array $scored): array
    {
        $groups = [];
        foreach ($scored as $candidate) {
            $groups[$this->conflictTopicKey($candidate)][] = $candidate;
        }

        $resolved = [];
        foreach ($groups as $group) {
            if (count($group) === 1) {
                $candidate = $group[0];
                $candidate['conflict_merged_count'] = 0;
                $resolved[] = $candidate;

                continue;
            }

            if (! $this->hasVersionConflictSignal($group)) {
                foreach ($group as $candidate) {
                    $candidate['conflict_merged_count'] = 0;
                    $resolved[] = $candidate;
                }

                continue;
            }

            $winner = $group[0];
            foreach (array_slice($group, 1) as $candidate) {
                if ($this->compareRankedEvidence($candidate, $winner) < 0) {
                    $winner = $candidate;
                }
            }

            $winner['conflict_merged_count'] = count($group) - 1;
            $resolved[] = $winner;
        }

        return $resolved;
    }

    /**
     * 只有不同文档记录共享规范文档身份时，才把它们当作版本归并。
     *
     * @param  list<array<string,mixed>>  $group
     */
    private function hasVersionConflictSignal(array $group): bool
    {
        $knowledgeBaseIds = array_map(static function (array $candidate): int {
            $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];

            return (int) ($metadata['knowledge_base_id'] ?? 0);
        }, $group);

        return count(array_unique(array_filter($knowledgeBaseIds))) > 1;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function conflictTopicKey(array $candidate): string
    {
        $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
        $canonicalDocumentKey = trim((string) ($metadata['canonical_document_key'] ?? ''));
        if ($canonicalDocumentKey === '') {
            return 'evidence:'.(int) ($metadata['knowledge_base_id'] ?? 0).':'.(int) ($candidate['chunk_index'] ?? 0);
        }

        $title = $this->normalizeTopicKey((string) ($candidate['chunk_title'] ?? ''));
        $sectionPath = $this->normalizeTopicKey((string) ($candidate['section_path'] ?? ''));
        $identity = implode('|', [
            (int) ($metadata['brand_id'] ?? 0),
            (int) ($metadata['site_id'] ?? 0),
            (string) ($metadata['language_code'] ?? ''),
            $canonicalDocumentKey,
            $sectionPath,
            $title,
        ]);

        return 'version:'.sha1($identity);
    }

    private function normalizeTopicKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s\p{P}\p{S}]+/u', '', $value);

        return is_string($value) ? $value : '';
    }

    private function riskLevelRank(string $riskLevel): int
    {
        return match (strtolower(trim($riskLevel))) {
            'low' => 3,
            'high' => 1,
            default => 2,
        };
    }

    private function effectiveDateTimestamp(string $effectiveDate): int
    {
        $effectiveDate = trim($effectiveDate);
        if ($effectiveDate === '') {
            return 0;
        }

        try {
            return Carbon::parse($effectiveDate)->timestamp;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function shouldExcludeByGovernance(array $metadata): bool
    {
        $riskLevel = strtolower(trim((string) ($metadata['risk_level'] ?? '')));
        $reviewStatus = strtolower(trim((string) ($metadata['review_status'] ?? '')));

        return $riskLevel === 'high' && ! in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function metadataScore(array $metadata): float
    {
        $score = 0.0;
        $reviewStatus = strtolower(trim((string) ($metadata['review_status'] ?? '')));
        if (in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true)) {
            $score += 0.42;
        }

        if (trim((string) ($metadata['source_name'] ?? '')) !== '' || trim((string) ($metadata['source_url'] ?? '')) !== '') {
            $score += 0.18;
        }

        if (trim((string) ($metadata['business_line'] ?? '')) !== '') {
            $score += 0.12;
        }

        $effectiveDate = trim((string) ($metadata['effective_date'] ?? ''));
        if ($effectiveDate !== '') {
            try {
                $ageDays = max(0, Carbon::parse($effectiveDate)->diffInDays(now()));
                $score += $ageDays <= 365 ? 0.2 : ($ageDays <= 1095 ? 0.1 : 0.02);
            } catch (Throwable) {
                $score += 0.02;
            }
        }

        $riskLevel = strtolower(trim((string) ($metadata['risk_level'] ?? '')));
        if ($riskLevel === 'low') {
            $score += 0.08;
        } elseif ($riskLevel === 'high') {
            $score -= 0.12;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function compareRankedEvidence(array $left, array $right): int
    {
        $governanceDiff = $this->evidenceGovernanceRank($right) <=> $this->evidenceGovernanceRank($left);
        if ($governanceDiff !== 0) {
            return $governanceDiff;
        }

        $freshnessDiff = $this->evidenceFreshnessTimestamp($right) <=> $this->evidenceFreshnessTimestamp($left);
        if ($freshnessDiff !== 0) {
            return $freshnessDiff;
        }

        $scoreDiff = ((float) ($right['score'] ?? 0.0)) <=> ((float) ($left['score'] ?? 0.0));
        if ($scoreDiff !== 0) {
            return $scoreDiff;
        }

        $knowledgeBaseRankDiff = ((int) ($left['knowledge_base_rank'] ?? 0)) <=> ((int) ($right['knowledge_base_rank'] ?? 0));

        return $knowledgeBaseRankDiff !== 0
            ? $knowledgeBaseRankDiff
            : ((int) ($left['chunk_index'] ?? 0) <=> (int) ($right['chunk_index'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function evidenceGovernanceRank(array $candidate): int
    {
        $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
        $authorityLevel = strtolower(trim((string) ($metadata['authority_level'] ?? '')));
        $directoryKey = strtolower(trim((string) ($metadata['directory_key'] ?? '')));
        $directoryPath = strtolower(trim((string) ($metadata['directory_path'] ?? '')));
        $reviewStatus = strtolower(trim((string) ($metadata['review_status'] ?? '')));

        $rank = match ($authorityLevel) {
            'first_party' => 40,
            'regulatory_filing' => 30,
            'mixed_research' => 10,
            'third_party' => 5,
            default => 0,
        };

        if (in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true)) {
            $rank += 20;
        }

        if ($authorityLevel === 'first_party'
            && ($directoryKey === 'policy-compliance' || str_starts_with($directoryPath, 'policy-compliance'))) {
            $rank += 40;
        }

        return $rank + $this->riskLevelRank((string) ($metadata['risk_level'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function evidenceFreshnessTimestamp(array $candidate): int
    {
        $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];

        return max(
            $this->effectiveDateTimestamp((string) ($metadata['effective_date'] ?? '')),
            $this->effectiveDateTimestamp((string) ($metadata['retrieved_at'] ?? '')),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $evidence
     * @return list<array<string,mixed>>
     */
    private function withEvidenceReferences(array $evidence): array
    {
        foreach ($evidence as $index => &$candidate) {
            $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
            $candidate['citation_id'] = 'K'.($index + 1);
            $candidate['provenance'] = array_filter([
                'knowledge_base_id' => $metadata['knowledge_base_id'] ?? null,
                'canonical_document_key' => $metadata['canonical_document_key'] ?? null,
                'source_name' => $metadata['source_name'] ?? null,
                'source_url' => $metadata['source_url'] ?? null,
                'source_type' => $metadata['source_type'] ?? null,
                'source_file_path' => $metadata['source_file_path'] ?? null,
                'source_file_hash' => $metadata['source_file_hash'] ?? null,
                'effective_date' => $metadata['effective_date'] ?? null,
                'retrieved_at' => $metadata['retrieved_at'] ?? null,
                'authority_level' => $metadata['authority_level'] ?? null,
                'citation_required' => $metadata['citation_required'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');
        }
        unset($candidate);

        return $evidence;
    }

    private function localVectorScore(object $row, array $queryVector, bool $useRealEmbeddingScore): float
    {
        $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
        if ($vector === []) {
            return 0.0;
        }

        $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
        if ($useRealEmbeddingScore !== $chunkUsesRealEmbedding) {
            return 0.0;
        }

        return $this->dotProduct($queryVector, $vector);
    }

    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * @return array<int,float>
     */
    private function fetchPgvectorScores(KnowledgeScope $scope, int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        try {
            $rows = $this->scopedChunkQuery($scope, $knowledgeBaseId)
                ->whereNotNull('embedding_vector')
                ->select(['chunk_index'])
                ->selectRaw('(embedding_vector <=> CAST(? AS vector)) AS vector_distance', [$vectorLiteral])
                ->orderByRaw('embedding_vector <=> CAST(? AS vector)', [$vectorLiteral])
                ->orderBy('chunk_index')
                ->limit(max(1, $candidateLimit))
                ->get();
        } catch (Throwable) {
            return [];
        }

        $scores = [];
        foreach ($rows as $row) {
            $distance = (float) ($row->vector_distance ?? 1.0);
            $scores[(int) ($row->chunk_index ?? 0)] = max(0.0, min(1.0, 1.0 - $distance));
        }

        return $scores;
    }

    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return [];
        }

        preg_match_all('/[a-z0-9_]+|\p{Han}+/u', $text, $matches);
        $frequencies = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^\p{Han}+$/u', $token) === 1) {
                foreach ($this->cjkTokens($token) as $cjkToken) {
                    $frequencies[$cjkToken] = (int) ($frequencies[$cjkToken] ?? 0) + 1;
                }

                continue;
            }

            if (mb_strlen($token, 'UTF-8') > 1) {
                $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
            }
        }

        return $frequencies;
    }

    /**
     * @return list<string>
     */
    private function cjkTokens(string $text): array
    {
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= 1) {
            return [];
        }

        $tokens = [];
        if ($length <= 8) {
            $tokens[] = $text;
        }
        foreach ([2, 3, 4] as $size) {
            if ($length < $size) {
                continue;
            }
            for ($offset = 0; $offset <= $length - $size; $offset++) {
                $tokens[] = mb_substr($text, $offset, $size, 'UTF-8');
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $documentTerms
     */
    private function lexicalScore(array $queryTerms, array $documentTerms): float
    {
        if ($queryTerms === [] || $documentTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($documentTerms[$term])) {
                $matched += min($count, (int) $documentTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }
}
