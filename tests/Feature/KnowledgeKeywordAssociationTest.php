<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeKeywordAssociationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KnowledgeKeywordAssociationTest extends TestCase
{
    use RefreshDatabase;

    public function test_associates_a_document_with_keyword_targets_owned_by_the_requested_library(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $cluster = $this->cluster($library, $node, 'wedding-dresses');
        $document = $this->document();

        app(KnowledgeKeywordAssociationService::class)->associate(
            $document,
            [$node->id],
            [$cluster->id],
            $library->id,
        );

        $this->assertDatabaseHas('knowledge_keyword_nodes', [
            'knowledge_base_id' => $document->id,
            'keyword_node_id' => $node->id,
        ]);
        $this->assertDatabaseHas('knowledge_keyword_clusters', [
            'knowledge_base_id' => $document->id,
            'keyword_cluster_id' => $cluster->id,
        ]);
        $this->assertSame($document->id, $node->knowledgeBases()->sole()->id);
        $this->assertSame($document->id, $cluster->knowledgeBases()->sole()->id);
    }

    public function test_rejects_foreign_targets_without_replacing_existing_associations(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $foreignLibrary = $this->library(84, 'Other brand keywords');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $foreignNode = $this->nodeWithKeyword($foreignLibrary, 'foreign-dresses');
        $foreignCluster = $this->cluster($foreignLibrary, $foreignNode, 'foreign-dresses');
        $document = $this->document();
        $service = app(KnowledgeKeywordAssociationService::class);

        $service->associate($document, [$node->id], [], $library->id);

        $this->assertDomainError(
            'knowledge_keyword_association.cluster_library_boundary',
            fn () => $service->associate($document, [$node->id], [$foreignCluster->id], $library->id),
        );

        $this->assertDatabaseCount('knowledge_keyword_nodes', 1);
        $this->assertDatabaseCount('knowledge_keyword_clusters', 0);
        $this->assertDatabaseHas('knowledge_keyword_nodes', [
            'knowledge_base_id' => $document->id,
            'keyword_node_id' => $node->id,
        ]);
    }

    public function test_rejects_a_foreign_node_before_creating_cluster_associations(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $foreignLibrary = $this->library(84, 'Other brand keywords');
        $foreignNode = $this->nodeWithKeyword($foreignLibrary, 'foreign-dresses');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $cluster = $this->cluster($library, $node, 'wedding-dresses');
        $document = $this->document();

        $this->assertDomainError(
            'knowledge_keyword_association.node_library_boundary',
            fn () => app(KnowledgeKeywordAssociationService::class)->associate(
                $document,
                [$foreignNode->id],
                [$cluster->id],
                $library->id,
            ),
        );

        $this->assertDatabaseCount('knowledge_keyword_nodes', 0);
        $this->assertDatabaseCount('knowledge_keyword_clusters', 0);
    }

    public function test_rejects_a_missing_keyword_library_before_writing_associations(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $document = $this->document();

        $this->assertDomainError(
            'knowledge_keyword_association.library_not_found',
            fn () => app(KnowledgeKeywordAssociationService::class)->associate($document, [$node->id], [], 999),
        );

        $this->assertDatabaseCount('knowledge_keyword_nodes', 0);
        $this->assertDatabaseCount('knowledge_keyword_clusters', 0);
    }

    public function test_rejects_a_node_without_keyword_ownership_without_changing_existing_associations(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $cluster = $this->cluster($library, $node, 'wedding-dresses');
        $unownedNode = $this->nodeWithoutKeywords('unowned-node');
        $document = $this->document();
        $service = app(KnowledgeKeywordAssociationService::class);

        $service->associate($document, [$node->id], [$cluster->id], $library->id);

        $this->assertDomainError(
            'knowledge_keyword_association.node_library_boundary',
            fn () => $service->associate($document, [$unownedNode->id], [], $library->id),
        );

        $this->assertOnlyAssociates($document, $node, $cluster);
    }

    public function test_rejects_a_node_shared_with_another_library_without_changing_existing_associations(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $foreignLibrary = $this->library(84, 'Other brand keywords');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $cluster = $this->cluster($library, $node, 'wedding-dresses');
        $sharedNode = $this->nodeWithKeyword($library, 'shared-dresses');
        $sharedNode->keywords()->attach($this->keyword($foreignLibrary, 'foreign-shared-dresses')->id);
        $document = $this->document();
        $service = app(KnowledgeKeywordAssociationService::class);

        $service->associate($document, [$node->id], [$cluster->id], $library->id);

        $this->assertDomainError(
            'knowledge_keyword_association.node_library_boundary',
            fn () => $service->associate($document, [$sharedNode->id], [], $library->id),
        );

        $this->assertOnlyAssociates($document, $node, $cluster);
    }

    public function test_empty_target_sets_clear_only_the_association_type_they_replace(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $node = $this->nodeWithKeyword($library, 'wedding-dresses');
        $cluster = $this->cluster($library, $node, 'wedding-dresses');
        $document = $this->document();
        $service = app(KnowledgeKeywordAssociationService::class);

        $service->associate($document, [$node->id], [$cluster->id], $library->id);
        $service->associate($document, [], [$cluster->id], $library->id);

        $this->assertDatabaseCount('knowledge_keyword_nodes', 0);
        $this->assertDatabaseHas('knowledge_keyword_clusters', [
            'knowledge_base_id' => $document->id,
            'keyword_cluster_id' => $cluster->id,
        ]);

        $service->associate($document, [$node->id], [], $library->id);

        $this->assertDatabaseHas('knowledge_keyword_nodes', [
            'knowledge_base_id' => $document->id,
            'keyword_node_id' => $node->id,
        ]);
        $this->assertDatabaseCount('knowledge_keyword_clusters', 0);
    }

    public function test_replaces_omitted_targets_and_treats_duplicate_ids_idempotently(): void
    {
        $library = $this->library(42, 'LightInTheBox governed keywords');
        $firstNode = $this->nodeWithKeyword($library, 'wedding-dresses');
        $secondNode = $this->nodeWithKeyword($library, 'evening-dresses');
        $firstCluster = $this->cluster($library, $firstNode, 'wedding-dresses');
        $secondCluster = $this->cluster($library, $secondNode, 'evening-dresses');
        $document = $this->document();
        $service = app(KnowledgeKeywordAssociationService::class);

        $service->associate(
            $document,
            [$firstNode->id, $firstNode->id, $secondNode->id],
            [$firstCluster->id, $firstCluster->id, $secondCluster->id],
            $library->id,
        );
        $this->assertDatabaseCount('knowledge_keyword_nodes', 2);
        $this->assertDatabaseCount('knowledge_keyword_clusters', 2);

        $service->associate($document, [$secondNode->id], [$secondCluster->id], $library->id);

        $this->assertDatabaseMissing('knowledge_keyword_nodes', [
            'knowledge_base_id' => $document->id,
            'keyword_node_id' => $firstNode->id,
        ]);
        $this->assertDatabaseMissing('knowledge_keyword_clusters', [
            'knowledge_base_id' => $document->id,
            'keyword_cluster_id' => $firstCluster->id,
        ]);
        $this->assertOnlyAssociates($document, $secondNode, $secondCluster);
    }

    private function library(int $id, string $name): KeywordLibrary
    {
        DB::table('keyword_libraries')->insert([
            'id' => $id,
            'name' => $name,
            'description' => '',
            'keyword_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return KeywordLibrary::query()->findOrFail($id);
    }

    private function nodeWithKeyword(KeywordLibrary $library, string $slug): KeywordNode
    {
        $node = $this->nodeWithoutKeywords($slug);
        $node->keywords()->attach($this->keyword($library, $slug)->id, ['is_primary' => true]);

        return $node;
    }

    private function nodeWithoutKeywords(string $slug): KeywordNode
    {
        $taxonomy = KeywordTaxonomy::query()->create([
            'name' => str()->headline($slug),
            'slug' => $slug.'-'.str()->random(8),
        ]);
        $node = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'name' => str()->headline($slug),
            'slug' => $slug,
            'path' => $slug,
            'depth' => 0,
        ]);

        return $node;
    }

    private function keyword(KeywordLibrary $library, string $slug): Keyword
    {
        return Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => str()->replace('-', ' ', $slug),
            'normalized_keyword' => str()->replace('-', ' ', $slug),
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'keyword_type' => 'category',
            'search_intent' => 'informational',
            'brand_relation' => 'non_brand',
            'status' => 'active',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
    }

    private function cluster(KeywordLibrary $library, KeywordNode $node, string $slug): KeywordCluster
    {
        $keyword = $node->keywords()->where('library_id', $library->id)->sole();

        return KeywordCluster::query()->create([
            'name' => str()->headline($slug),
            'slug' => $slug.'-cluster-'.str()->random(8),
            'taxonomy_node_id' => $node->id,
            'primary_keyword_id' => $keyword->id,
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'search_intent' => 'informational',
        ]);
    }

    private function document(): KnowledgeBase
    {
        return KnowledgeBase::query()->create([
            'name' => 'Governed knowledge document',
            'content' => 'LightInTheBox wedding dress guidance.',
        ]);
    }

    private function assertOnlyAssociates(KnowledgeBase $document, KeywordNode $node, KeywordCluster $cluster): void
    {
        $this->assertDatabaseCount('knowledge_keyword_nodes', 1);
        $this->assertDatabaseCount('knowledge_keyword_clusters', 1);
        $this->assertDatabaseHas('knowledge_keyword_nodes', [
            'knowledge_base_id' => $document->id,
            'keyword_node_id' => $node->id,
        ]);
        $this->assertDatabaseHas('knowledge_keyword_clusters', [
            'knowledge_base_id' => $document->id,
            'keyword_cluster_id' => $cluster->id,
        ]);
    }

    private function assertDomainError(string $message, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected domain error [{$message}].");
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
