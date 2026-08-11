<?php

namespace App\Domain\ContentGeneration;

use App\Models\Admin;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunEvidence;
use App\Models\KnowledgeChunk;
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
    ): void {
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

        $chunks = KnowledgeChunk::query()
            ->with('knowledgeBase')
            ->whereIn('id', array_values(array_unique(array_map('intval', $knowledgeChunkIds))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($chunks->count() !== count(array_unique($knowledgeChunkIds))) {
            throw ValidationException::withMessages([
                'knowledge_chunk_ids' => __('admin.content_strategy.validation.evidence_unavailable'),
            ]);
        }

        foreach ($chunks as $chunk) {
            if ((string) $chunk->knowledgeBase?->review_status !== 'reviewed') {
                throw ValidationException::withMessages([
                    'knowledge_chunk_ids' => __('admin.content_strategy.validation.evidence_unreviewed'),
                ]);
            }
            $key = 'knowledge-chunk:'.(int) $chunk->id;
            $this->create($run, $key, (int) $chunk->id, [
                'fact_id' => $key,
                'source_type' => 'knowledge_chunk',
                'title' => (string) ($chunk->chunk_title ?: $chunk->knowledgeBase?->name),
                'content' => (string) $chunk->content,
                'content_sha256' => hash('sha256', (string) $chunk->content),
                'knowledge_base_id' => (int) $chunk->knowledge_base_id,
                'knowledge_base_review_status' => 'reviewed',
                'reviewed_by_admin_id' => null,
                'snapshotted_at' => now()->toISOString(),
            ], in_array($key, $requiredFactIds, true));
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
