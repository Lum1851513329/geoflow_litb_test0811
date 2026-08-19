<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KnowledgeBase;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class KnowledgeKeywordAssociationService
{
    /**
     * @param  array<int,mixed>  $nodeIds
     * @param  array<int,mixed>  $clusterIds
     */
    public function associate(KnowledgeBase $document, array $nodeIds, array $clusterIds, int $libraryId): void
    {
        if ($libraryId < 1) {
            throw new DomainException('knowledge_keyword_association.library_not_found');
        }

        DB::transaction(function () use ($document, $nodeIds, $clusterIds, $libraryId): void {
            $document = KnowledgeBase::query()->lockForUpdate()->find($document->getKey())
                ?? throw new DomainException('knowledge_keyword_association.document_not_found');
            KeywordLibrary::query()->lockForUpdate()->find($libraryId)
                ?? throw new DomainException('knowledge_keyword_association.library_not_found');
            $nodeIds = $this->normalizeIds($nodeIds, 'node');
            $clusterIds = $this->normalizeIds($clusterIds, 'cluster');

            $nodes = $this->lockedNodes($nodeIds);
            $clusters = $this->lockedClusters($clusterIds);

            $this->assertNodesBelongToLibrary($nodes, $libraryId);
            $this->assertClustersBelongToLibrary($clusters, $libraryId);

            $document->keywordNodes()->sync($nodeIds);
            $document->keywordClusters()->sync($clusterIds);
        });
    }

    /** @param array<int,mixed> $ids
     * @return array<int,int>
     */
    private function normalizeIds(array $ids, string $target): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                throw new DomainException("knowledge_keyword_association.{$target}_id_invalid");
            }
            $normalized[] = (int) $id;
        }

        return array_values(array_unique($normalized));
    }

    /** @param array<int,int> $nodeIds
     * @return Collection<int,KeywordNode>
     */
    private function lockedNodes(array $nodeIds): Collection
    {
        if ($nodeIds === []) {
            return collect();
        }

        $nodes = KeywordNode::query()->whereIn('id', $nodeIds)->orderBy('id')->lockForUpdate()->get();
        if ($nodes->count() !== count($nodeIds)) {
            throw new DomainException('knowledge_keyword_association.node_not_found');
        }

        return $nodes;
    }

    /** @param array<int,int> $clusterIds
     * @return Collection<int,KeywordCluster>
     */
    private function lockedClusters(array $clusterIds): Collection
    {
        if ($clusterIds === []) {
            return collect();
        }

        $clusters = KeywordCluster::query()->whereIn('id', $clusterIds)->orderBy('id')->lockForUpdate()->get();
        if ($clusters->count() !== count($clusterIds)) {
            throw new DomainException('knowledge_keyword_association.cluster_not_found');
        }

        return $clusters;
    }

    /** @param Collection<int,KeywordNode> $nodes */
    private function assertNodesBelongToLibrary(Collection $nodes, int $libraryId): void
    {
        foreach ($nodes as $node) {
            $libraryIds = DB::table('keyword_node_keyword')
                ->join('keywords', 'keywords.id', '=', 'keyword_node_keyword.keyword_id')
                ->where('keyword_node_keyword.node_id', $node->id)
                ->lockForUpdate()
                ->distinct()
                ->pluck('keywords.library_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($libraryIds !== [$libraryId]) {
                throw new DomainException('knowledge_keyword_association.node_library_boundary');
            }
        }
    }

    /** @param Collection<int,KeywordCluster> $clusters */
    private function assertClustersBelongToLibrary(Collection $clusters, int $libraryId): void
    {
        $keywordIds = $clusters->pluck('primary_keyword_id')->map(static fn ($id): int => (int) $id)->unique()->values();
        if ($keywordIds->isEmpty()) {
            return;
        }

        $keywords = Keyword::query()->whereIn('id', $keywordIds)->lockForUpdate()->get()->keyBy('id');
        if ($keywords->count() !== $keywordIds->count()) {
            throw new DomainException('knowledge_keyword_association.cluster_primary_keyword_not_found');
        }

        foreach ($clusters as $cluster) {
            $keyword = $keywords->get((int) $cluster->primary_keyword_id);
            if ((int) $keyword->library_id !== $libraryId) {
                throw new DomainException('knowledge_keyword_association.cluster_library_boundary');
            }
        }
    }
}
