<?php

namespace App\Services\GeoFlow;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class BrandKnowledgeEvidenceSelector
{
    public const MAX_CANDIDATES = 300;

    public const MAX_CHUNKS = 12;

    public const MAX_CHARACTERS = 24000;

    public const MAX_REQUEST_IDS = 50;

    public function __construct(private GenerationKnowledgeScopeResolver $resolver) {}

    public function select(KnowledgeScope $scope, string $query, array $includeIds = [], array $excludeIds = []): array
    {
        return $this->selectCandidates($scope, $query, $includeIds, $excludeIds);
    }

    /** The caller must persist the returned evidence in the same transaction. */
    public function selectForSnapshot(KnowledgeScope $scope, string $query, array $includeIds = [], array $excludeIds = []): array
    {
        $selection = $this->select($scope, $query, $includeIds, $excludeIds);
        $ids = array_values(array_unique(array_merge($selection['chunk_ids'], $this->ids($includeIds), $this->ids($excludeIds))));

        // Candidate reads may see late commits under READ COMMITTED. Lock only the
        // bounded selected/requested set, then discard all pre-lock row contents.
        $baseIds = KnowledgeChunk::whereIn('id', $ids)->pluck('knowledge_base_id');
        KnowledgeBase::whereIn('id', $baseIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $chunks = KnowledgeChunk::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'knowledge_base_id']);
        if ($chunks->count() !== count($ids)) {
            $this->invalid('Selected evidence changed or disappeared; retrieve again.');
        }
        // A chunk can be reparented before its own lock. Its now-locked parent ID,
        // not the preliminary read or an eager-loaded relation, is authoritative.
        KnowledgeBase::whereIn('id', $chunks->pluck('knowledge_base_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        $this->validateSelection($scope, $selection['chunk_ids']);
        $rebuilt = $this->selectCandidates($scope, $query, $includeIds, $excludeIds, $selection['chunk_ids']);
        $rebuilt['warnings'] = array_values(array_unique(array_merge($selection['warnings'], $rebuilt['warnings'])));

        return $rebuilt;
    }

    private function selectCandidates(KnowledgeScope $scope, string $query, array $includeIds, array $excludeIds, ?array $candidateIds = null): array
    {
        $includeIds = $this->ids($includeIds);
        $excludeIds = $this->ids($excludeIds);
        if (count($includeIds) + count($excludeIds) > self::MAX_REQUEST_IDS) {
            $this->invalid('At most 50 requested IDs are allowed.');
        }
        $this->validateSelection($scope, $includeIds);
        $this->eligibleIds($scope, $excludeIds);
        $terms = array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($query)) ?: [], fn ($term) => mb_strlen($term) > 1 && ! in_array($term, ['the', 'and', 'for', 'with', 'from', 'how', 'what', 'your', 'are', 'can', 'this', 'that', 'does', 'into', 'about'], true))));
        $terms = array_slice($terms, 0, 32);
        $warnings = ['Conservative lexical retrieval: vector-only matches are disabled until model-specific calibration.'];
        $automatic = $this->chunks($scope)->whereNotIn('id', array_merge($includeIds, $excludeIds));
        if ($candidateIds !== null) {
            $automatic->whereIn('id', $candidateIds);
        }
        if ($terms === []) {
            $automatic->whereRaw('1 = 0');
        } else {
            $automatic->where(function (Builder $q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhereRaw('LOWER(content) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(chunk_title) LIKE ?', ['%'.$term.'%']);
                }
            });
            // Rank across the complete scoped query before applying the load budget.
            $rankParts = [];
            $rankBindings = [];
            foreach ($terms as $term) {
                $rankParts[] = '(CASE WHEN LOWER(content) LIKE ? OR LOWER(chunk_title) LIKE ? THEN 1 ELSE 0 END)';
                array_push($rankBindings, '%'.$term.'%', '%'.$term.'%');
            }
            $automatic->orderByRaw('('.implode(' + ', $rankParts).') DESC', $rankBindings);
        }
        $rows = $automatic->orderBy('id')->limit(self::MAX_CANDIDATES + 1)->get();
        if ($rows->count() > self::MAX_CANDIDATES) {
            $warnings[] = 'Candidate budget reached; refine the query or directory for fuller coverage.';
            $rows = $rows->take(self::MAX_CANDIDATES);
        }
        $rows = $this->chunks($scope)->whereIn('id', array_diff($includeIds, $excludeIds))->get()->concat($rows);
        $candidates = [];
        foreach ($rows as $chunk) {
            $content = trim($chunk->content);
            $manual = in_array($chunk->id, $includeIds, true);
            $bodyTerms = preg_split('/[^\pL\pN]+/u', mb_strtolower($content)) ?: [];
            $titleTerms = preg_split('/[^\pL\pN]+/u', mb_strtolower($chunk->chunk_title ?? '')) ?: [];
            $body = count(array_intersect($terms, $bodyTerms)) / max(1, count($terms));
            $title = count(array_intersect($terms, $titleTerms)) / max(1, count($terms));
            $matchingTerms = count(array_intersect($terms, array_unique(array_merge($bodyTerms, $titleTerms))));
            if ($content === '' || (! $manual && $matchingTerms < min(2, count($terms)))) {
                continue;
            }
            if (mb_strlen($content) > self::MAX_CHARACTERS) {
                $warnings[] = 'Chunk '.$chunk->id.' exceeds the evidence budget and requires re-chunking.';

                continue;
            }
            $base = $chunk->knowledgeBase;
            $metadata = $base->only(['brand_id', 'site_id', 'primary_directory_id', 'language_code', 'canonical_document_key', 'version_label', 'effective_date', 'review_due_at', 'retrieved_at', 'source_url', 'source_name', 'source_type', 'source_file_path', 'source_file_hash', 'authority_level', 'review_status', 'lifecycle_status', 'generation_eligible']);
            $metadata['knowledge_base_id'] = $base->id;
            $metadata['knowledge_base_name'] = $base->name;
            $candidates[] = array_merge($metadata, [
                'chunk_id' => $chunk->id, 'chunk_index' => $chunk->chunk_index, 'chunk_title' => $chunk->chunk_title,
                'content' => $content, 'content_hash' => hash('sha256', preg_replace('/\s+/u', ' ', $content)),
                'score' => $body * 0.8 + $title * 0.2, 'keyword_score' => $body, 'title_score' => $title,
                'vector_score' => null, 'metadata_score' => 0, 'selection_method' => $manual ? 'manual' : 'automatic',
                'metadata' => $metadata, 'provenance' => $metadata,
            ]);
        }
        usort($candidates, static fn ($a, $b) => (($b['selection_method'] === 'manual') <=> ($a['selection_method'] === 'manual'))
            ?: ($b['score'] <=> $a['score'])
            ?: (($b['authority_level'] === 'first_party') <=> ($a['authority_level'] === 'first_party'))
            ?: strcmp((string) ($b['effective_date'] ?? ''), (string) ($a['effective_date'] ?? ''))
            ?: ($a['chunk_id'] <=> $b['chunk_id']));
        $selected = [];
        $hashes = [];
        $characters = 0;
        foreach ($candidates as $candidate) {
            $length = mb_strlen($candidate['content']);
            if (isset($hashes[$candidate['content_hash']])) {
                continue;
            }
            if (count($selected) >= self::MAX_CHUNKS || $characters + $length > self::MAX_CHARACTERS) {
                $warnings[] = 'Evidence budget reached; some complete chunks were not selected.';

                continue;
            }
            $hashes[$candidate['content_hash']] = true;
            $characters += $length;
            $candidate['citation_id'] = 'K'.(count($selected) + 1);
            $selected[] = $candidate;
        }
        if ($selected === []) {
            $warnings[] = 'Insufficient evidence within the selected brand scope.';
        }

        return ['chunk_ids' => array_column($selected, 'chunk_id'), 'evidence' => $selected, 'query' => $query,
            'scope' => ['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $scope->brandId, 'knowledge_site_id' => $scope->siteId, 'knowledge_directory_id' => $scope->directoryId, 'language_code' => $scope->languageCode],
            'warnings' => array_values(array_unique($warnings))];
    }

    public function validateSelection(KnowledgeScope $scope, array $ids): void
    {
        $ids = $this->ids($ids);
        if (count($ids) > self::MAX_CHUNKS) {
            $this->invalid('At most 12 evidence chunks are allowed.');
        }
        $rows = $this->eligibleIds($scope, $ids);
        $characters = 0;
        foreach ($rows as $row) {
            if (trim($row->content) === '') {
                $this->invalid('Empty evidence is not eligible.');
            }
            $characters += mb_strlen(trim($row->content));
        }
        if ($characters > self::MAX_CHARACTERS) {
            $this->invalid('Evidence exceeds 24000 characters; oversized chunks require re-chunking.');
        }
    }

    private function chunks(KnowledgeScope $scope): Builder
    {
        return KnowledgeChunk::query()->whereIn('knowledge_base_id', $this->resolver->eligibleQuery($scope)->select('id'))->with('knowledgeBase');
    }

    private function eligibleIds(KnowledgeScope $scope, array $ids)
    {
        $rows = $this->chunks($scope)->whereIn('id', $ids)->get();
        if ($rows->count() !== count($ids)) {
            $this->invalid('Evidence IDs must exist and remain eligible within this brand, source site, directory and language.');
        }

        return $rows;
    }

    private function ids(array $ids): array
    {
        if (count($ids) > self::MAX_REQUEST_IDS) {
            $this->invalid('At most 50 requested IDs are allowed.');
        }
        $normalized = array_map(fn ($id) => $this->resolver->positiveId($id, 'knowledge_chunk_ids'), $ids);
        if (count(array_unique($normalized)) !== count($normalized)) {
            $this->invalid('Duplicate evidence IDs are not allowed.');
        }

        return array_values($normalized);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['knowledge_chunk_ids' => $message]);
    }
}
