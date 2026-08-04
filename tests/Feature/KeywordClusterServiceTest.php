<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordClusterService;
use App\Services\GeoFlow\Keywords\KeywordClusterSuggestionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordClusterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_first_suggestions_are_transient_and_assign_all_supported_roles(): void
    {
        [$node, $otherNode] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $secondary = $this->keyword('summer dress', $node);
        $question = $this->keyword('summer dress', $node, keywordType: 'question');
        $variant = $this->keyword('summer dress', $node, canonicalKeywordId: $primary->id);

        $differentTaxonomy = $this->keyword('summer dress', $otherNode);
        $differentLanguage = $this->keyword('summer dress', $node, language: 'fr');
        $differentMarket = $this->keyword('summer dress', $node, market: 'GB');
        $differentBrand = $this->keyword('summer dress', $node, brandRelation: 'branded');
        $incompatibleIntent = $this->keyword('summer dress', $node, searchIntent: 'navigational');
        $similarButDistinctStem = $this->keyword('summer dressing', $node);

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest([
            $primary,
            $secondary,
            $question,
            $variant,
            $differentTaxonomy,
            $differentLanguage,
            $differentMarket,
            $differentBrand,
            $incompatibleIntent,
            $similarButDistinctStem,
        ]);

        $suggestion = collect($suggestions)->first(
            static fn (array $item): bool => $item['primary_keyword_id'] === $primary->id,
        );
        $this->assertNotNull($suggestion);
        $this->assertSame([
            $primary->id => 'primary',
            $secondary->id => 'secondary',
            $question->id => 'question',
            $variant->id => 'variant',
        ], collect($suggestion['keywords'])->pluck('role', 'keyword_id')->all());
        $this->assertSame($node->id, $suggestion['taxonomy_node_id']);
        $this->assertSame('en', $suggestion['language_code']);
        $this->assertSame('US', $suggestion['market_scope']);
        $this->assertSame('non_brand', $suggestion['brand_relation']);
        $this->assertDatabaseCount('keyword_clusters', 0);
        $this->assertDatabaseCount('keyword_cluster_keywords', 0);
    }

    public function test_confirm_creates_an_active_audited_cluster_and_memberships_atomically(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $secondary = $this->keyword('summer dress', $node);
        $question = $this->keyword('summer dress', $node, keywordType: 'question');
        $variant = $this->keyword('summer dress', $node, canonicalKeywordId: $primary->id);
        $admin = $this->admin();
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([
            $primary,
            $secondary,
            $question,
            $variant,
        ])[0];

        $cluster = app(KeywordClusterService::class)->confirm($suggestion, $admin->id);

        $this->assertSame('active', $cluster->status);
        $this->assertSame($admin->id, $cluster->confirmed_by);
        $this->assertNotNull($cluster->confirmed_at);
        $this->assertSame($primary->id, $cluster->primary_keyword_id);
        $this->assertSame([
            $primary->id => 'primary',
            $secondary->id => 'secondary',
            $question->id => 'question',
            $variant->id => 'variant',
        ], $cluster->memberships()->orderBy('sort_order')->pluck('role', 'keyword_id')->all());
    }

    public function test_confirm_enforces_active_primary_uniqueness_and_protects_human_confirmation(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary])[0];
        $service = app(KeywordClusterService::class);
        $admin = $this->admin();
        $cluster = $service->confirm($suggestion, $admin->id);

        $overwrite = $suggestion;
        $overwrite['name'] = 'Automatic overwrite attempt';
        $this->assertDomainError(
            'keyword_cluster.confirmed_cluster_protected',
            fn () => $service->confirm($overwrite, $admin->id),
        );
        $this->assertSame($suggestion['name'], $cluster->fresh()->name);

        $duplicatePrimary = $suggestion;
        $duplicatePrimary['name'] = 'A different cluster';
        $duplicatePrimary['slug'] = 'a-different-cluster';
        $this->assertDomainError(
            'keyword_cluster.primary_active_conflict',
            fn () => $service->confirm($duplicatePrimary, $admin->id),
        );
        $this->assertDatabaseCount('keyword_clusters', 1);
    }

    public function test_confirm_rejects_taxonomy_and_incompatible_intent_boundary_violations(): void
    {
        [$node, $otherNode] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary])[0];
        $admin = $this->admin();
        $service = app(KeywordClusterService::class);

        $crossTaxonomy = $this->keyword('summer dress', $otherNode);
        $crossTaxonomySuggestion = $suggestion;
        $crossTaxonomySuggestion['keywords'][] = ['keyword_id' => $crossTaxonomy->id, 'role' => 'secondary'];
        $this->assertDomainError(
            'keyword_cluster.taxonomy_boundary',
            fn () => $service->confirm($crossTaxonomySuggestion, $admin->id),
        );

        $incompatibleIntent = $this->keyword('summer dress', $node, searchIntent: 'navigational');
        $incompatibleSuggestion = $suggestion;
        $incompatibleSuggestion['keywords'][] = ['keyword_id' => $incompatibleIntent->id, 'role' => 'secondary'];
        $this->assertDomainError(
            'keyword_cluster.intent_boundary',
            fn () => $service->confirm($incompatibleSuggestion, $admin->id),
        );
        $this->assertDatabaseCount('keyword_clusters', 0);
    }

    /** @return array{KeywordNode,KeywordNode} */
    private function nodes(): array
    {
        $taxonomy = KeywordTaxonomy::query()->create([
            'name' => 'Apparel',
            'slug' => 'apparel-'.str()->random(8),
        ]);

        return [
            KeywordNode::query()->create([
                'taxonomy_id' => $taxonomy->id,
                'name' => 'Dresses',
                'slug' => 'dresses',
                'path' => 'dresses',
                'depth' => 0,
            ]),
            KeywordNode::query()->create([
                'taxonomy_id' => $taxonomy->id,
                'name' => 'Shoes',
                'slug' => 'shoes',
                'path' => 'shoes',
                'depth' => 0,
            ]),
        ];
    }

    private function keyword(
        string $value,
        KeywordNode $node,
        string $language = 'en',
        string $market = 'US',
        string $brandRelation = 'non_brand',
        string $searchIntent = 'informational',
        string $keywordType = 'category',
        ?int $canonicalKeywordId = null,
        bool $isTaxonomyPrimary = false,
    ): Keyword {
        $library = KeywordLibrary::query()->create([
            'name' => 'Cluster library '.str()->random(12),
            'description' => '',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => $value,
            'normalized_keyword' => $value,
            'language_code' => $language,
            'market_code' => $market,
            'market_scope' => $market,
            'keyword_type' => $keywordType,
            'search_intent' => $searchIntent,
            'brand_relation' => $brandRelation,
            'canonical_keyword_id' => $canonicalKeywordId,
            'status' => 'active',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $keyword->taxonomyNodes()->attach($node->id, [
            'is_primary' => $isTaxonomyPrimary,
            'confidence' => 1,
            'source' => 'manual',
        ]);

        return $keyword;
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'cluster-admin-'.str()->random(8),
            'email' => 'cluster-admin@example.test',
            'password' => 'password',
            'display_name' => 'Cluster Admin',
            'role' => 'super_admin',
            'status' => 'active',
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
