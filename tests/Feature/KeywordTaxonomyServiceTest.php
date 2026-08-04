<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordTaxonomyService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordTaxonomyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_node_builds_a_deterministic_unique_materialized_path(): void
    {
        $taxonomy = $this->taxonomy();
        $service = app(KeywordTaxonomyService::class);

        $root = $service->createNode($taxonomy, ['name' => 'Women Dresses', 'slug' => 'Women Dresses', 'sort_order' => 3]);
        $child = $service->createNode($taxonomy, ['name' => 'Evening', 'parent_id' => $root->id]);

        $this->assertSame('women-dresses', $root->fresh()->slug);
        $this->assertSame('women-dresses', $root->fresh()->path);
        $this->assertSame(0, $root->fresh()->depth);
        $this->assertSame('evening', $child->fresh()->slug);
        $this->assertSame('women-dresses/evening', $child->fresh()->path);
        $this->assertSame(1, $child->fresh()->depth);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('keyword_taxonomy.slug_taken');
        $service->createNode($taxonomy, ['name' => 'A different label', 'slug' => 'women dresses']);
    }

    public function test_move_updates_every_descendant_path_and_depth_atomically(): void
    {
        $taxonomy = $this->taxonomy();
        $service = app(KeywordTaxonomyService::class);
        $root = $service->createNode($taxonomy, ['name' => 'Root']);
        $branch = $service->createNode($taxonomy, ['name' => 'Branch', 'parent_id' => $root->id]);
        $leaf = $service->createNode($taxonomy, ['name' => 'Leaf', 'parent_id' => $branch->id]);
        $destination = $service->createNode($taxonomy, ['name' => 'Destination']);

        $service->moveNode($branch, $destination, 9);

        $this->assertDatabaseHas('keyword_nodes', [
            'id' => $branch->id,
            'parent_id' => $destination->id,
            'path' => 'destination/branch',
            'depth' => 1,
            'sort_order' => 9,
        ]);
        $this->assertDatabaseHas('keyword_nodes', [
            'id' => $leaf->id,
            'parent_id' => $branch->id,
            'path' => 'destination/branch/leaf',
            'depth' => 2,
        ]);
    }

    public function test_rejects_cross_taxonomy_self_and_descendant_parents(): void
    {
        $taxonomy = $this->taxonomy();
        $otherTaxonomy = $this->taxonomy('Other');
        $service = app(KeywordTaxonomyService::class);
        $root = $service->createNode($taxonomy, ['name' => 'Root']);
        $child = $service->createNode($taxonomy, ['name' => 'Child', 'parent_id' => $root->id]);
        $otherRoot = $service->createNode($otherTaxonomy, ['name' => 'Other Root']);

        $this->assertDomainError('keyword_taxonomy.parent_cross_taxonomy', fn () => $service->moveNode($root, $otherRoot, 0));
        $this->assertDomainError('keyword_taxonomy.parent_self', fn () => $service->moveNode($root, $root, 0));
        $this->assertDomainError('keyword_taxonomy.parent_descendant', fn () => $service->moveNode($root, $child, 0));
    }

    public function test_rejects_nodes_beyond_the_configured_maximum_depth(): void
    {
        config()->set('geoflow.keyword_taxonomy_max_depth', 1);
        $taxonomy = $this->taxonomy();
        $service = app(KeywordTaxonomyService::class);
        $root = $service->createNode($taxonomy, ['name' => 'Root']);
        $child = $service->createNode($taxonomy, ['name' => 'Child', 'parent_id' => $root->id]);

        $this->assertDomainError('keyword_taxonomy.max_depth_exceeded', fn () => $service->createNode($taxonomy, [
            'name' => 'Too Deep',
            'parent_id' => $child->id,
        ]));
    }

    public function test_assign_keywords_replaces_membership_and_marks_only_the_requested_primary_keyword(): void
    {
        $taxonomy = $this->taxonomy();
        $service = app(KeywordTaxonomyService::class);
        $node = $service->createNode($taxonomy, ['name' => 'Root']);
        $first = $this->keyword('first');
        $second = $this->keyword('second');
        $removed = $this->keyword('removed');
        $node->keywords()->attach($removed->id, ['is_primary' => true, 'source' => 'manual']);

        $service->assignKeywords($node, [$first->id, $second->id], $second->id);

        $this->assertDatabaseMissing('keyword_node_keyword', ['node_id' => $node->id, 'keyword_id' => $removed->id]);
        $this->assertDatabaseHas('keyword_node_keyword', ['node_id' => $node->id, 'keyword_id' => $first->id, 'is_primary' => false]);
        $this->assertDatabaseHas('keyword_node_keyword', ['node_id' => $node->id, 'keyword_id' => $second->id, 'is_primary' => true]);
    }

    public function test_rejects_deletion_when_node_has_children_or_cluster_references(): void
    {
        $taxonomy = $this->taxonomy();
        $service = app(KeywordTaxonomyService::class);
        $parent = $service->createNode($taxonomy, ['name' => 'Parent']);
        $service->createNode($taxonomy, ['name' => 'Child', 'parent_id' => $parent->id]);

        $this->assertDomainError('keyword_taxonomy.delete_has_children', fn () => $service->deleteNode($parent));

        $referenced = $service->createNode($taxonomy, ['name' => 'Referenced']);
        KeywordCluster::query()->create([
            'name' => 'Cluster',
            'slug' => 'cluster',
            'taxonomy_node_id' => $referenced->id,
            'primary_keyword_id' => $this->keyword('cluster')->id,
            'language_code' => 'en',
            'search_intent' => 'informational',
        ]);

        $this->assertDomainError('keyword_taxonomy.delete_has_clusters', fn () => $service->deleteNode($referenced));
    }

    public function test_reports_stable_domain_errors_for_missing_taxonomies_nodes_and_parents(): void
    {
        $service = app(KeywordTaxonomyService::class);
        $missingTaxonomy = new KeywordTaxonomy;
        $missingTaxonomy->setAttribute('id', 999999);
        $this->assertDomainError('keyword_taxonomy.taxonomy_not_found', fn () => $service->createNode($missingTaxonomy, ['name' => 'Missing']));

        $missingNode = new KeywordNode;
        $missingNode->setAttribute('id', 999999);
        $this->assertDomainError('keyword_taxonomy.node_not_found', fn () => $service->assignKeywords($missingNode, []));

        $taxonomy = $this->taxonomy();
        $node = $service->createNode($taxonomy, ['name' => 'Node']);
        $this->assertDomainError('keyword_taxonomy.parent_not_found', fn () => $service->createNode($taxonomy, [
            'name' => 'Missing Parent',
            'parent_id' => 999999,
        ]));

        $vanishedParent = $service->createNode($taxonomy, ['name' => 'Vanished Parent']);
        $vanishedParent->delete();
        $this->assertDomainError('keyword_taxonomy.parent_not_found', fn () => $service->moveNode($node, $vanishedParent, 0));
    }

    public function test_move_escapes_like_metacharacters_when_selecting_a_legacy_subtree(): void
    {
        $taxonomy = $this->taxonomy();
        $service = app(KeywordTaxonomyService::class);
        $branch = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'name' => 'Legacy branch',
            'slug' => 'legacy-branch',
            'path' => 'legacy%_\\root',
            'depth' => 0,
        ]);
        $child = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'parent_id' => $branch->id,
            'name' => 'Child',
            'slug' => 'child',
            'path' => 'legacy%_\\root/child',
            'depth' => 1,
        ]);
        $unrelated = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'name' => 'Unrelated legacy record',
            'slug' => 'unrelated-legacy-record',
            'path' => 'legacyZA\\root/unrelated',
            'depth' => 1,
        ]);
        $destination = $service->createNode($taxonomy, ['name' => 'Destination']);

        $service->moveNode($branch, $destination, 0);

        $this->assertSame('destination/legacy-branch/child', $child->fresh()->path);
        $this->assertSame('legacyZA\\root/unrelated', $unrelated->fresh()->path);
    }

    private function taxonomy(string $name = 'Apparel'): KeywordTaxonomy
    {
        return KeywordTaxonomy::query()->create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
        ]);
    }

    private function keyword(string $name): Keyword
    {
        $library = KeywordLibrary::query()->firstOrCreate(
            ['name' => 'Keyword taxonomy tests'],
            ['description' => '', 'keyword_count' => 0],
        );

        return Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => $name,
            'normalized_keyword' => $name,
            'used_count' => 0,
            'usage_count' => 0,
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
