<?php

namespace App\Domain\ContentGeneration;

use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunEvidence;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\GeoFlow\BrandKnowledgeEvidenceSelector;
use App\Services\GeoFlow\BrandKnowledgeQueryBuilder;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerationEvidenceSnapshotter
{
    /**
     * @param  list<int>  $knowledgeChunkIds
     * @param  list<array{fact_id: string, title?: string|null, content: string, source_url?: string|null}>  $explicitEvidence
     * @param  list<string>  $requiredFactIds
     */
    public function snapshot(
        ContentGenerationRun $run,
        array $knowledgeChunkIds,
        array $explicitEvidence,
        array $requiredFactIds,
        Admin $actor,
        array $excludedIds = [],
        bool $retrieve = false,
    ): void {
        DB::transaction(fn () => $this->persist($run, $knowledgeChunkIds, $explicitEvidence, $requiredFactIds, $actor, $excludedIds, $retrieve), 3);
    }

    private function persist(ContentGenerationRun $run, array $knowledgeChunkIds, array $explicitEvidence, array $requiredFactIds, Admin $actor, array $excludedIds, bool $retrieve): void
    {
        $run = ContentGenerationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->knowledge_retrieval_snapshot !== null || $run->evidence()->exists()
            || ! in_array($run->status, ['pending', 'queued'], true)) {
            throw ValidationException::withMessages(['evidence' => 'Evidence snapshots are write-once and must precede generation.']);
        }
        $brief = ContentBrief::whereKey($run->content_brief_id)->lockForUpdate()->firstOrFail();
        $selection = null;
        if ($brief->knowledge_mode === 'brand_auto') {
            if ($explicitEvidence !== []) {
                throw ValidationException::withMessages(['evidence' => 'Brand mode only accepts scoped knowledge chunks, not free-text evidence.']);
            }
            $resolver = app(GenerationKnowledgeScopeResolver::class);
            $scope = $resolver->resolve($brief->getAttributes(), $brief->language_code);
            $selector = app(BrandKnowledgeEvidenceSelector::class);
            $selection = $selector->selectForSnapshot($scope, $retrieve ? app(BrandKnowledgeQueryBuilder::class)->forBrief($brief) : '', $knowledgeChunkIds, $excludedIds);
            $knowledgeChunkIds = $selection['chunk_ids'];
            if ($knowledgeChunkIds === []) {
                throw ValidationException::withMessages(['knowledge_chunk_ids' => '该品牌内证据不足']);
            }
            $run->forceFill(['knowledge_retrieval_snapshot' => $selection])->save();
        }
        $keys = [];
        foreach ($knowledgeChunkIds as $id) {
            $keys[] = 'knowledge-chunk:'.(int) $id;
        }
        foreach ($explicitEvidence as $item) {
            $keys[] = trim((string) ($item['fact_id'] ?? ''));
        }
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'evidence' => __('admin.content_strategy.validation.evidence_duplicate'),
            ]);
        }

        $baseIds = KnowledgeChunk::whereIn('id', $knowledgeChunkIds)->pluck('knowledge_base_id');
        KnowledgeBase::whereIn('id', $baseIds)->orderBy('id')->lockForUpdate()->get();
        $chunks = KnowledgeChunk::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $knowledgeChunkIds))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($chunks->count() !== count(array_unique($knowledgeChunkIds))) {
            throw ValidationException::withMessages([
                'knowledge_chunk_ids' => __('admin.content_strategy.validation.evidence_unavailable'),
            ]);
        }

        // The preliminary parent IDs can change before chunk locks are acquired.
        // Only the now-locked chunks define the authoritative parents to validate/copy.
        $actualBaseIds = $chunks->pluck('knowledge_base_id')->unique();
        $bases = KnowledgeBase::whereIn('id', $actualBaseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($bases->count() !== $actualBaseIds->count()) {
            throw ValidationException::withMessages(['knowledge_chunk_ids' => __('admin.content_strategy.validation.evidence_unavailable')]);
        }
        $knownScopes = $bases->filter(fn ($base) => $base->brand_id !== null || $base->site_id !== null)
            ->map(fn ($base) => $base->brand_id.':'.$base->site_id)->unique();
        if ($knownScopes->count() > 1 || ($knownScopes->isNotEmpty() && $bases->contains(fn ($base) => $base->brand_id === null || $base->site_id === null))) {
            throw ValidationException::withMessages(['knowledge_chunk_ids' => 'Explicit knowledge must share one brand and source site.']);
        }

        foreach ($chunks as $chunk) {
            $chunk->setRelation('knowledgeBase', $bases->get($chunk->knowledge_base_id));
            if ((string) $chunk->knowledgeBase?->review_status !== 'reviewed') {
                throw ValidationException::withMessages([
                    'knowledge_chunk_ids' => __('admin.content_strategy.validation.evidence_unreviewed'),
                ]);
            }
            $key = 'knowledge-chunk:'.(int) $chunk->id;
            $selected = collect($selection['evidence'] ?? [])->firstWhere('chunk_id', $chunk->id) ?? [];
            $content = $selected['content'] ?? (string) $chunk->content;
            $this->create($run, $key, (int) $chunk->id, array_merge($selected, [
                'fact_id' => $key,
                'source_type' => 'knowledge_chunk',
                'title' => (string) ($chunk->chunk_title ?: $chunk->knowledgeBase?->name),
                'content' => $content,
                'content_sha256' => hash('sha256', $content),
                'source_url' => $chunk->knowledgeBase?->source_url,
                'knowledge_base_id' => (int) $chunk->knowledge_base_id,
                'knowledge_base_review_status' => 'reviewed',
                'reviewed_by_admin_id' => null,
                'snapshotted_at' => now()->toISOString(),
            ]), in_array($key, $requiredFactIds, true));
        }

        foreach ($explicitEvidence as $item) {
            $key = trim((string) $item['fact_id']);
            $content = trim((string) $item['content']);
            $this->create($run, $key, null, [
                'fact_id' => $key,
                'source_type' => 'explicit',
                'title' => trim((string) ($item['title'] ?? '')),
                'content' => $content,
                'content_sha256' => hash('sha256', $content),
                'source_url' => trim((string) ($item['source_url'] ?? '')),
                'reviewed_by_admin_id' => (int) $actor->id,
                'reviewed_at' => now()->toISOString(),
            ], in_array($key, $requiredFactIds, true));
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function create(
        ContentGenerationRun $run,
        string $key,
        ?int $knowledgeChunkId,
        array $snapshot,
        bool $required,
    ): void {
        ContentGenerationRunEvidence::query()->create([
            'content_generation_run_id' => $run->id,
            'knowledge_chunk_id' => $knowledgeChunkId,
            'evidence_key' => $key,
            'evidence_snapshot' => $snapshot,
            'is_required' => $required,
        ]);
    }
}
