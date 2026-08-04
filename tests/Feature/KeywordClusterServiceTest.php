<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordClusterCandidateToken;
use App\Services\GeoFlow\Keywords\KeywordClusterService;
use App\Services\GeoFlow\Keywords\KeywordClusterSuggestionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Throwable;

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

    public function test_brand_category_keyword_supports_a_non_brand_category_primary_end_to_end(): void
    {
        [$node] = $this->nodes();
        // Create the branded keyword first and mark it taxonomy-primary to prove selection is semantic, not ID/pivot based.
        $brandSupporting = $this->keyword(
            'LightInTheBox summer dresses',
            $node,
            brandRelation: 'brand_category',
            searchIntent: 'commercial',
            keywordType: 'category',
            isTaxonomyPrimary: true,
        );
        $primary = $this->keyword(
            'summer dresses',
            $node,
            brandRelation: 'non_brand',
            searchIntent: 'commercial',
            keywordType: 'category',
        );

        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$brandSupporting, $primary])[0];

        $this->assertSame($primary->id, $suggestion['primary_keyword_id']);
        $this->assertSame('non_brand', $suggestion['brand_relation']);
        $this->assertSame([
            $primary->id => 'primary',
            $brandSupporting->id => 'brand_supporting',
        ], collect($suggestion['keywords'])->pluck('role', 'keyword_id')->all());

        $token = app(KeywordClusterCandidateToken::class)->issue($suggestion, 123);
        $cluster = app(KeywordClusterService::class)->confirm(
            app(KeywordClusterCandidateToken::class)->resolve($token, 123),
            $this->admin()->id,
        );

        $this->assertSame([
            $primary->id => 'primary',
            $brandSupporting->id => 'brand_supporting',
        ], $cluster->memberships()->orderBy('sort_order')->pluck('role', 'keyword_id')->all());
    }

    public function test_unrelated_brand_boundaries_are_not_folded_into_brand_supporting(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, brandRelation: 'non_brand', searchIntent: 'commercial');
        $plainBranded = $this->keyword('LightInTheBox summer dresses', $node, brandRelation: 'branded', searchIntent: 'commercial', keywordType: 'brand');
        $competitor = $this->keyword('LightInTheBox summer dresses', $node, brandRelation: 'competitor_comparison', searchIntent: 'commercial', keywordType: 'comparison');

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest([$primary, $plainBranded, $competitor]);

        $this->assertCount(3, $suggestions);
        foreach ($suggestions as $suggestion) {
            $this->assertCount(1, $suggestion['keywords']);
            $this->assertNotSame('brand_supporting', $suggestion['keywords'][0]['role']);
        }
    }

    public function test_confirm_rejects_a_forged_brand_supporting_role(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, brandRelation: 'non_brand', searchIntent: 'commercial');
        $secondary = $this->keyword('summer dress', $node, brandRelation: 'non_brand', searchIntent: 'commercial');
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary, $secondary])[0];
        $suggestion['keywords'][1]['role'] = 'brand_supporting';

        $this->assertDomainError(
            'keyword_cluster.brand_supporting_role_invalid',
            fn () => app(KeywordClusterService::class)->confirm($suggestion, $this->admin()->id),
        );
        $this->assertDatabaseCount('keyword_clusters', 0);
    }

    public function test_conservative_ies_stemming_preserves_cookie_and_movie_but_singularizes_party(): void
    {
        [$node] = $this->nodes();
        $cookie = $this->keyword('cookie', $node);
        $cookies = $this->keyword('cookies', $node);
        $movie = $this->keyword('movie', $node);
        $movies = $this->keyword('movies', $node);
        $party = $this->keyword('party', $node);
        $parties = $this->keyword('parties', $node);

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest([
            $cookie,
            $cookies,
            $movie,
            $movies,
            $party,
            $parties,
        ]);

        $this->assertCount(3, $suggestions);
        foreach ([[$cookie, $cookies], [$movie, $movies], [$party, $parties]] as [$singular, $plural]) {
            $suggestion = collect($suggestions)->first(
                static fn (array $candidate): bool => collect($candidate['keywords'])->contains('keyword_id', $singular->id),
            );
            $this->assertNotNull($suggestion);
            $this->assertEqualsCanonicalizing(
                [$singular->id, $plural->id],
                array_column($suggestion['keywords'], 'keyword_id'),
            );
        }
    }

    public function test_plural_only_cookie_and_movie_terms_remain_independent_suggestions(): void
    {
        [$node] = $this->nodes();
        $cookies = $this->keyword('cookies', $node);
        $movies = $this->keyword('movies', $node);

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest([$cookies, $movies]);

        $this->assertCount(2, $suggestions);
        $this->assertEqualsCanonicalizing(['cookies', 'movies'], array_column($suggestions, 'slug'));
        $this->assertSame([1, 1], array_map(static fn (array $suggestion): int => count($suggestion['keywords']), $suggestions));
    }

    public function test_known_ie_lexicon_prefers_cookie_and_movie_singulars_when_y_words_also_exist(): void
    {
        [$node] = $this->nodes();
        $cookie = $this->keyword('cookie', $node);
        $cookies = $this->keyword('cookies', $node);
        $cooky = $this->keyword('cooky', $node);
        $movie = $this->keyword('movie', $node);
        $movies = $this->keyword('movies', $node);
        $movy = $this->keyword('movy', $node);

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest([
            $cookie,
            $cookies,
            $cooky,
            $movie,
            $movies,
            $movy,
        ]);

        $this->assertCount(4, $suggestions);
        $this->assertEqualsCanonicalizing(
            ['cookie', 'cooky', 'movie', 'movy'],
            array_column($suggestions, 'slug'),
        );
        $cookieSuggestion = collect($suggestions)->firstWhere('slug', 'cookie');
        $movieSuggestion = collect($suggestions)->firstWhere('slug', 'movie');
        $this->assertEqualsCanonicalizing([$cookie->id, $cookies->id], array_column($cookieSuggestion['keywords'], 'keyword_id'));
        $this->assertEqualsCanonicalizing([$movie->id, $movies->id], array_column($movieSuggestion['keywords'], 'keyword_id'));
    }

    public function test_known_ie_plurals_do_not_merge_with_y_words_when_ie_singulars_are_absent(): void
    {
        [$node] = $this->nodes();
        $keywords = [
            $this->keyword('cookies', $node),
            $this->keyword('cooky', $node),
            $this->keyword('movies', $node),
            $this->keyword('movy', $node),
            $this->keyword('hoodies', $node),
            $this->keyword('hoody', $node),
            $this->keyword('selfies', $node),
            $this->keyword('selfy', $node),
        ];

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest($keywords);

        $this->assertCount(8, $suggestions);
        $this->assertEqualsCanonicalizing(
            ['cookies', 'cooky', 'movies', 'movy', 'hoodies', 'hoody', 'selfies', 'selfy'],
            array_column($suggestions, 'slug'),
        );
        foreach ($suggestions as $suggestion) {
            $this->assertCount(1, $suggestion['keywords']);
        }
    }

    public function test_ie_singular_lexicon_can_be_extended_without_guessing_from_y_candidates(): void
    {
        config()->set('keyword_intelligence.topic_clustering.ie_singular_lexicon', ['auntie']);
        [$node] = $this->nodes();
        $aunties = $this->keyword('aunties', $node);
        $aunty = $this->keyword('aunty', $node);

        $suggestions = app(KeywordClusterSuggestionService::class)->suggest([$aunties, $aunty]);

        $this->assertCount(2, $suggestions);
        $this->assertEqualsCanonicalizing(['aunties', 'aunty'], array_column($suggestions, 'slug'));
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
        $this->assertSame(hash('sha256', "{$primary->id}|en|US"), $cluster->primary_scope_key);
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

    public function test_confirm_translates_a_database_scope_claim_race_to_a_stable_domain_error(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary])[0];
        $admin = $this->admin();
        DB::statement(<<<'SQL'
            CREATE TRIGGER simulate_primary_scope_race
            BEFORE INSERT ON keyword_clusters
            WHEN NEW.slug <> 'racing-claim'
            BEGIN
                INSERT INTO keyword_clusters (
                    name, slug, primary_keyword_id, primary_scope_key, language_code,
                    market_code, market_scope, search_intent, status, created_at, updated_at
                ) VALUES (
                    'Racing claim', 'racing-claim', NEW.primary_keyword_id, NEW.primary_scope_key,
                    NEW.language_code, NEW.market_code, NEW.market_scope, NEW.search_intent,
                    'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                );
            END
            SQL);

        $message = null;
        try {
            app(KeywordClusterService::class)->confirm($suggestion, $admin->id);
        } catch (DomainException $exception) {
            $message = $exception->getMessage();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS simulate_primary_scope_race');
        }

        $this->assertSame('keyword_cluster.primary_active_conflict', $message);
        $this->assertDatabaseCount('keyword_clusters', 0);
    }

    public function test_database_uniquely_claims_active_primary_scope_but_allows_null_drafts(): void
    {
        $this->assertTrue(Schema::hasColumn('keyword_clusters', 'primary_scope_key'));
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $scopeKey = hash('sha256', "{$primary->id}|en|US");

        $first = KeywordCluster::query()->create($this->clusterAttributes($primary, 'first-active', [
            'primary_scope_key' => $scopeKey,
            'status' => 'active',
        ]));
        $this->assertSame($scopeKey, $first->primary_scope_key);
        $this->assertUniqueConstraint(fn () => KeywordCluster::query()->create(
            $this->clusterAttributes($primary, 'second-active', [
                'primary_scope_key' => $scopeKey,
                'status' => 'active',
            ]),
        ));

        $firstDraft = KeywordCluster::query()->create($this->clusterAttributes($primary, 'first-draft'));
        $secondDraft = KeywordCluster::query()->create($this->clusterAttributes($primary, 'second-draft'));
        $archived = KeywordCluster::query()->create($this->clusterAttributes($primary, 'archived', ['status' => 'archived']));
        $this->assertNull($firstDraft->primary_scope_key);
        $this->assertNull($secondDraft->primary_scope_key);
        $this->assertNull($archived->primary_scope_key);
    }

    public function test_scope_key_migration_fails_before_schema_changes_when_legacy_active_claims_conflict(): void
    {
        $migration = require database_path('migrations/2026_08_04_000004_add_keyword_cluster_primary_scope_key.php');
        $migration->down();
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        KeywordCluster::query()->create($this->clusterAttributes($primary, 'legacy-first', ['status' => 'active']));
        KeywordCluster::query()->create($this->clusterAttributes($primary, 'legacy-second', ['status' => 'active']));

        try {
            $migration->up();
            $this->fail('Expected conflicting legacy active claims to stop the migration.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame('keyword_clusters.primary_scope_backfill_conflict', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('keyword_clusters', 'primary_scope_key'));
        }
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

    public function test_confirm_rejects_a_member_with_a_different_conservative_stem(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $secondary = $this->keyword('summer dress', $node);
        $differentStem = $this->keyword('winter boots', $node);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary, $secondary])[0];
        $suggestion['keywords'][] = ['keyword_id' => $differentStem->id, 'role' => 'secondary'];

        $this->assertDomainError(
            'keyword_cluster.stem_boundary',
            fn () => app(KeywordClusterService::class)->confirm($suggestion, $this->admin()->id),
        );
        $this->assertDatabaseCount('keyword_clusters', 0);
    }

    public function test_confirm_rejects_a_forged_question_role(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $secondary = $this->keyword('summer dress', $node);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary, $secondary])[0];
        $suggestion['keywords'][1]['role'] = 'question';

        $this->assertDomainError(
            'keyword_cluster.question_role_invalid',
            fn () => app(KeywordClusterService::class)->confirm($suggestion, $this->admin()->id),
        );
        $this->assertDatabaseCount('keyword_clusters', 0);
    }

    public function test_confirm_rejects_a_forged_variant_role(): void
    {
        [$node] = $this->nodes();
        $primary = $this->keyword('summer dresses', $node, isTaxonomyPrimary: true);
        $secondary = $this->keyword('summer dress', $node);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$primary, $secondary])[0];
        $suggestion['keywords'][1]['role'] = 'variant';

        $this->assertDomainError(
            'keyword_cluster.variant_role_invalid',
            fn () => app(KeywordClusterService::class)->confirm($suggestion, $this->admin()->id),
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

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function clusterAttributes(Keyword $primary, string $slug, array $overrides = []): array
    {
        return [
            'name' => str()->headline($slug),
            'slug' => $slug,
            'primary_keyword_id' => $primary->id,
            'language_code' => 'en',
            'market_code' => 'US',
            'market_scope' => 'US',
            'search_intent' => 'informational',
            'status' => 'draft',
            ...$overrides,
        ];
    }

    private function assertUniqueConstraint(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a unique constraint violation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }
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
