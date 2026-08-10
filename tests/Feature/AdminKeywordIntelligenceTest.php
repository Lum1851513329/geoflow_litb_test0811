<?php

namespace Tests\Feature;

use App\Jobs\ImportKeywordWorkbookJob;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordImportBatch;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordClusterCandidateToken;
use App\Services\GeoFlow\Keywords\KeywordClusterSuggestionService;
use App\Services\GeoFlow\Keywords\KeywordImportPreviewSession;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AdminKeywordIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_pages_require_admin_and_preserve_legacy_keyword_library_route(): void
    {
        foreach (['index', 'import', 'taxonomy', 'clusters'] as $name) {
            $this->get(route("admin.keyword-intelligence.{$name}"))->assertRedirect(route('admin.login'));
        }

        $admin = $this->admin();
        foreach (['index', 'import', 'taxonomy', 'clusters'] as $name) {
            $this->actingAs($admin, 'admin')->get(route("admin.keyword-intelligence.{$name}"))->assertOk();
        }
        $this->assertTrue(route('admin.keyword-libraries.index') !== '');
        $this->actingAs($admin, 'admin')->get(route('admin.keyword-intelligence.index'))
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee('name="_token"', false);
    }

    public function test_overview_filters_and_paginates_keywords(): void
    {
        $library = $this->library();
        foreach (range(1, 30) as $number) {
            Keyword::query()->create(['library_id' => $library->id, 'keyword' => "dress {$number}", 'normalized_keyword' => "dress {$number}", 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => $number === 30 ? 'question' : 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        }

        $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.index', ['type' => 'question']))
            ->assertOk()->assertSee('dress 30')->assertDontSee('dress 29')->assertViewHas('keywords', fn ($keywords) => $keywords->perPage() === 20);
    }

    public function test_preview_is_read_only_and_confirm_stores_private_generated_file_and_dispatches_job(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->admin();
        $library = $this->library();
        $fixture = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');

        $previewResponse = $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.preview'), [
            'workbook' => new UploadedFile($fixture, 'client-name.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'source_type' => 'google_search_console',
            'mapping' => [],
        ])->assertOk()->assertSee(__('admin.keyword_intelligence.import.preview_title'))->assertSee('mapping[', false);
        $this->assertDatabaseCount('keyword_import_batches', 0);
        Queue::assertNothingPushed();
        $previewToken = $previewResponse->viewData('previewToken');
        $this->assertNotEmpty($previewToken);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), [
            'preview_token' => $previewToken,
            'library_id' => $library->id,
            'source_type' => 'google_search_console',
            'mapping' => array_merge(array_fill_keys($previewResponse->viewData('mappingHeaders'), ''), $previewResponse->viewData('resolvedMapping')),
            'language_code' => 'en',
        ])->assertRedirect();

        $batch = KeywordImportBatch::query()->sole();
        $this->assertSame($previewResponse->viewData('resolvedMapping'), $batch->mapping_json);
        $this->assertStringStartsWith('keyword-imports/', $batch->stored_path);
        $this->assertStringNotContainsString('client-name', $batch->stored_path);
        Storage::disk('local')->assertExists($batch->stored_path);
        Queue::assertPushed(ImportKeywordWorkbookJob::class, fn ($job) => $job->batchId === $batch->id);
    }

    public function test_import_validation_status_and_completed_revert(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), [])
            ->assertSessionHasErrors(['preview_token', 'mapping', 'library_id', 'source_type']);

        $library = $this->library();
        $batch = KeywordImportBatch::query()->create(['library_id' => $library->id, 'original_filename' => 'x.xlsx', 'stored_path' => 'keyword-imports/x.xlsx', 'file_sha256' => str_repeat('a', 64), 'source_type' => 'gsc', 'status' => 'completed', 'mapping_json' => [], 'metadata_json' => []]);
        $this->actingAs($admin, 'admin')->getJson(route('admin.keyword-intelligence.import.status', $batch))->assertOk()->assertJsonPath('status', 'completed');
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.revert', $batch))->assertRedirect();
        $this->assertSame('reverted', $batch->fresh()->status);
    }

    public function test_duplicate_mapping_and_non_revertible_batch_return_admin_validation_errors(): void
    {
        $admin = $this->admin();
        $fixture = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');
        $this->actingAs($admin, 'admin')->from(route('admin.keyword-intelligence.import'))->post(route('admin.keyword-intelligence.import.preview'), [
            'workbook' => new UploadedFile($fixture, 'keywords.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'source_type' => 'gsc',
            'mapping' => ['Query' => 'keyword', 'Keyword' => 'keyword'],
        ])->assertRedirect(route('admin.keyword-intelligence.import'))->assertSessionHasErrors('mapping');

        $batch = KeywordImportBatch::query()->create(['library_id' => $this->library()->id, 'original_filename' => 'x.xlsx', 'stored_path' => 'keyword-imports/x.xlsx', 'file_sha256' => str_repeat('b', 64), 'source_type' => 'gsc', 'status' => 'processing', 'mapping_json' => [], 'metadata_json' => []]);
        $this->actingAs($admin, 'admin')->from(route('admin.keyword-intelligence.index'))->post(route('admin.keyword-intelligence.import.revert', $batch))
            ->assertRedirect(route('admin.keyword-intelligence.index'))->assertSessionHasErrors('batch');
    }

    public function test_confirm_rejects_an_invalid_or_expired_preview_token_without_storing_a_batch(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAs($this->admin(), 'admin')->post(route('admin.keyword-intelligence.import.confirm'), [
            'preview_token' => 'client-controlled-path.xlsx', 'library_id' => $this->library()->id, 'source_type' => 'gsc', 'mapping' => ['Query' => 'keyword'],
        ])->assertSessionHasErrors('preview_token');
        $this->assertDatabaseCount('keyword_import_batches', 0);
        Queue::assertNothingPushed();
    }

    public function test_taxonomy_node_create_move_and_keyword_assignment(): void
    {
        $admin = $this->admin();
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'description' => '', 'status' => 'active']);
        $root = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Fashion', 'slug' => 'fashion', 'path' => 'fashion', 'depth' => 0, 'sort_order' => 0]);
        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'party dress', 'normalized_keyword' => 'party dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'status' => 'active']);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.taxonomy.nodes.store'), ['taxonomy_id' => $taxonomy->id, 'parent_id' => $root->id, 'name' => 'Dresses'])->assertRedirect();
        $child = KeywordNode::query()->where('name', 'Dresses')->sole();
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.taxonomy.nodes.move'), ['node_id' => $child->id, 'parent_id' => null, 'sort_order' => 2])->assertRedirect();
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.taxonomy.nodes.assign', $child), ['keyword_ids' => [$keyword->id], 'primary_keyword_id' => $keyword->id])->assertRedirect();
        $this->assertDatabaseHas('keyword_node_keyword', ['node_id' => $child->id, 'keyword_id' => $keyword->id, 'is_primary' => 1]);
    }

    public function test_cluster_pages_show_score_breakdown_and_confirm_route_exists(): void
    {
        $admin = $this->admin();
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => 'dresses', 'depth' => 0, 'sort_order' => 0]);
        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'party dress', 'normalized_keyword' => 'party dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $cluster = KeywordCluster::query()->create(['name' => 'Party Dress', 'slug' => 'party-dress', 'taxonomy_node_id' => $node->id, 'primary_keyword_id' => $keyword->id, 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'search_intent' => 'commercial', 'status' => 'active']);
        $cluster->opportunityScores()->create(['score_version' => 'v1', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'total_score' => 52.5, 'components_json' => ['demand' => 20], 'penalties_json' => ['missing_data' => 2], 'inputs_json' => [], 'weights_json' => []]);

        $this->actingAs($admin, 'admin')->get(route('admin.keyword-intelligence.clusters.show', $cluster))->assertOk()->assertSee('demand')->assertSee('missing_data')->assertSee('v1')->assertSee('2026-07-01');
        $this->assertNotNull(route('admin.keyword-intelligence.clusters.confirm'));
        $this->assertNotNull(route('admin.keyword-intelligence.clusters.score', $cluster));
    }

    public function test_cluster_page_shows_server_generated_candidates_with_confirm_forms(): void
    {
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => 'dresses', 'depth' => 0, 'sort_order' => 0]);
        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'evening dresses', 'normalized_keyword' => 'evening dresses', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $node->keywords()->attach($keyword->id, ['is_primary' => true]);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.clusters'))
            ->assertOk()->assertSee('Evening Dresses')->assertSee(route('admin.keyword-intelligence.clusters.confirm'), false)->assertSee('candidate_token', false)->assertDontSee('suggestion[primary_keyword_id]', false);
    }

    public function test_cluster_page_hides_brand_supporting_group_when_its_non_brand_primary_is_already_active(): void
    {
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => 'dresses', 'depth' => 0, 'sort_order' => 0]);
        $library = $this->library();
        $primary = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'summer dresses', 'normalized_keyword' => 'summer dresses', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $brandSupporting = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'LightInTheBox summer dresses', 'normalized_keyword' => 'lightinthebox summer dresses', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'brand_category', 'status' => 'active']);
        $node->keywords()->attach($primary->id, ['is_primary' => true]);
        $node->keywords()->attach($brandSupporting->id, ['is_primary' => false]);
        KeywordCluster::query()->create(['name' => 'Existing Summer Dresses', 'slug' => 'existing-summer-dresses', 'taxonomy_node_id' => $node->id, 'primary_keyword_id' => $primary->id, 'primary_scope_key' => hash('sha256', "{$primary->id}|en|GLOBAL"), 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'search_intent' => 'commercial', 'status' => 'active', 'confirmed_at' => now()]);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.clusters'))->assertOk();

        $this->assertSame([], $response->viewData('suggestions'));
        $response->assertDontSee('LightInTheBox summer dresses');
    }

    public function test_taxonomy_keyword_picker_is_filtered_and_paginated_without_a_200_item_cap(): void
    {
        $library = $this->library();
        foreach (range(1, 205) as $index) {
            Keyword::query()->create(['library_id' => $library->id, 'keyword' => "picker {$index}", 'normalized_keyword' => "picker {$index}", 'language_code' => $index === 205 ? 'fr' : 'en', 'market_scope' => 'GLOBAL', 'status' => 'active']);
        }
        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.taxonomy', ['keyword_language' => 'fr', 'keyword_q' => '205']))->assertOk()->assertSee('picker 205')->assertDontSee('picker 204');
        $this->assertTrue($response->viewData('keywordOptions')->hasPages() === false);
    }

    public function test_import_progress_error_and_recovery_copy_are_visible(): void
    {
        $batch = KeywordImportBatch::query()->create(['library_id' => $this->library()->id, 'original_filename' => 'failed.xlsx', 'stored_path' => 'keyword-imports/failed.xlsx', 'file_sha256' => str_repeat('c', 64), 'source_type' => 'gsc', 'status' => 'failed', 'mapping_json' => [], 'metadata_json' => [], 'processed_count' => 4, 'total_count' => 10, 'error_summary' => 'Malformed row']);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.index'))->assertOk()->assertSee('4 / 10')->assertSee('Malformed row')->assertSee(__('admin.keyword_intelligence.import.failed_recovery'))->assertSee(route('admin.keyword-intelligence.import.status', $batch), false);
    }

    public function test_bound_model_write_records_complete_activity_target(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_username');
            $table->string('admin_role');
            $table->string('action');
            $table->string('request_method');
            $table->string('page')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });
        $batch = KeywordImportBatch::query()->create(['library_id' => $this->library()->id, 'original_filename' => 'done.xlsx', 'stored_path' => 'keyword-imports/done.xlsx', 'file_sha256' => str_repeat('d', 64), 'source_type' => 'gsc', 'status' => 'completed', 'mapping_json' => [], 'metadata_json' => []]);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.keyword-intelligence.import.revert', $batch))->assertRedirect();
        $log = AdminActivityLog::query()->latest('id')->firstOrFail();
        $this->assertSame('batch', $log->target_type);
        $this->assertSame($batch->id, $log->target_id);
    }

    public function test_admin_activity_log_redacts_keyword_tokens_but_keeps_nonsecret_context(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_username');
            $table->string('admin_role');
            $table->string('action');
            $table->string('request_method');
            $table->string('page')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        $this->actingAs($this->admin(), 'admin')->post(route('admin.keyword-intelligence.clusters.confirm'), [
            'candidate_token' => 'must-not-persist',
            'nested' => [
                'accessToken' => 'camel-must-not-persist',
                'providerSecret' => 'secret-must-not-persist',
                'signing-key' => 'key-must-not-persist',
                'keyword' => 'safe keyword',
            ],
            'action' => 'confirm_candidate',
        ])->assertSessionHasErrors('candidate_token');

        $details = json_decode((string) AdminActivityLog::query()->latest('id')->firstOrFail()->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('[redacted]', $details['candidate_token']);
        $this->assertSame('[redacted]', $details['nested']['accessToken']);
        $this->assertSame('[redacted]', $details['nested']['providerSecret']);
        $this->assertSame('[redacted]', $details['nested']['signing-key']);
        $this->assertSame('safe keyword', $details['nested']['keyword']);
        $this->assertSame('confirm_candidate', $details['action']);
        $this->assertStringNotContainsString('must-not-persist', json_encode($details, JSON_THROW_ON_ERROR));
    }

    public function test_keyword_intelligence_writes_require_csrf_and_accept_the_session_token(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app->detectEnvironment(fn (): string => 'production');
        $admin = $this->admin();
        $routes = [
            route('admin.keyword-intelligence.import.preview'),
            route('admin.keyword-intelligence.import.confirm'),
            route('admin.keyword-intelligence.taxonomy.nodes.store'),
            route('admin.keyword-intelligence.taxonomy.nodes.move'),
            route('admin.keyword-intelligence.taxonomy.nodes.assign', 999999),
            route('admin.keyword-intelligence.clusters.confirm'),
            route('admin.keyword-intelligence.clusters.score', 999999),
            route('admin.keyword-intelligence.import.revert', 999999),
        ];
        foreach ($routes as $route) {
            $this->actingAs($admin, 'admin')->post($route)->assertStatus(419);
        }

        $this->startSession();
        foreach ($routes as $route) {
            $response = $this->actingAs($admin, 'admin')->post($route, ['_token' => session()->token()]);
            $this->assertNotSame(419, $response->getStatusCode());
        }
    }

    public function test_secondary_keyword_already_in_a_cluster_remains_eligible_but_active_primary_conflicts_are_hidden(): void
    {
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Shoes', 'slug' => 'shoes', 'path' => 'shoes', 'depth' => 0, 'sort_order' => 0]);
        $library = $this->library();
        $existingPrimary = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'party dresses', 'normalized_keyword' => 'party dresses', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $reusableSecondary = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'party dress', 'normalized_keyword' => 'party dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $node->keywords()->attach($existingPrimary->id, ['is_primary' => true]);
        $node->keywords()->attach($reusableSecondary->id, ['is_primary' => true]);
        $cluster = KeywordCluster::query()->create(['name' => 'Existing Party Dresses', 'slug' => 'existing-party-dresses', 'taxonomy_node_id' => $node->id, 'primary_keyword_id' => $existingPrimary->id, 'primary_scope_key' => hash('sha256', "{$existingPrimary->id}|en|GLOBAL"), 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'search_intent' => 'commercial', 'status' => 'active', 'confirmed_at' => now()]);
        $cluster->memberships()->create(['keyword_id' => $reusableSecondary->id, 'role' => 'secondary', 'sort_order' => 1]);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.clusters'))->assertOk();
        $candidate = collect($response->viewData('suggestions'))->first(fn (array $item): bool => $item['data']['primary_keyword_id'] === $reusableSecondary->id);
        $this->assertNotNull($candidate);
        $this->assertSame([$existingPrimary->id, $reusableSecondary->id], collect($candidate['data']['keywords'])->pluck('keyword_id')->sort()->values()->all());
        $this->actingAs($this->admin(), 'admin')->post(route('admin.keyword-intelligence.clusters.confirm'), ['candidate_token' => $candidate['token']])->assertRedirect();
        $this->assertDatabaseHas('keyword_clusters', ['primary_keyword_id' => $reusableSecondary->id, 'status' => 'active']);
    }

    public function test_more_than_500_conflicting_primaries_do_not_starve_a_later_eligible_candidate(): void
    {
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Catalog', 'slug' => 'catalog', 'path' => 'catalog', 'depth' => 0, 'sort_order' => 0]);
        $library = $this->library();
        foreach (range(1, 501) as $index) {
            $keyword = Keyword::query()->create(['library_id' => $library->id, 'keyword' => "blocked term {$index}", 'normalized_keyword' => "blocked term {$index}", 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
            $node->keywords()->attach($keyword->id, ['is_primary' => true]);
            KeywordCluster::query()->create(['name' => "Existing {$index}", 'slug' => "existing-{$index}", 'taxonomy_node_id' => $node->id, 'primary_keyword_id' => $keyword->id, 'primary_scope_key' => hash('sha256', "{$keyword->id}|en|GLOBAL"), 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'search_intent' => 'commercial', 'status' => 'active', 'confirmed_at' => now()]);
        }
        $brandSupporting = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'LightInTheBox blocked term 501', 'normalized_keyword' => 'lightinthebox blocked term 501', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'brand_category', 'status' => 'active']);
        $node->keywords()->attach($brandSupporting->id, ['is_primary' => false]);
        $eligible = Keyword::query()->create(['library_id' => $library->id, 'keyword' => 'later eligible candidate', 'normalized_keyword' => 'later eligible candidate', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $node->keywords()->attach($eligible->id, ['is_primary' => true]);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.clusters'))->assertOk();
        $this->assertTrue(collect($response->viewData('suggestions'))->contains(fn (array $item): bool => $item['data']['primary_keyword_id'] === $eligible->id));
        $this->assertFalse(collect($response->viewData('suggestions'))->contains(fn (array $item): bool => $item['data']['primary_keyword_id'] === $brandSupporting->id));
    }

    public function test_preview_session_rejects_expired_scope_hash_and_path_tampering_and_deletes_expired_file(): void
    {
        Storage::fake('local');
        $session = app(KeywordImportPreviewSession::class);
        $validPath = 'keyword-import-previews/11111111-1111-4111-8111-111111111111.xlsx';
        Storage::disk('local')->put($validPath, 'original');
        $payload = ['path' => $validPath, 'sha256' => hash('sha256', 'original'), 'original_filename' => 'safe.xlsx', 'source_type' => 'gsc', 'admin_id' => 123, 'expires_at' => now()->addMinute()->timestamp];

        foreach ([
            [array_merge($payload, ['source_type' => 'other']), 'gsc'],
            [array_merge($payload, ['path' => 'keyword-import-previews/../../secret.xlsx']), 'gsc'],
            [array_merge($payload, ['sha256' => str_repeat('0', 64)]), 'gsc'],
        ] as [$tampered, $sourceType]) {
            try {
                $session->resolve(Crypt::encryptString(json_encode($tampered, JSON_THROW_ON_ERROR)), $sourceType, 123);
                $this->fail('Tampered preview token was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $expired = $payload;
        $expired['expires_at'] = now()->subSecond()->timestamp;
        try {
            $session->resolve(Crypt::encryptString(json_encode($expired, JSON_THROW_ON_ERROR)), 'gsc', 123);
            $this->fail('Expired preview token was accepted.');
        } catch (InvalidArgumentException) {
            Storage::disk('local')->assertMissing($validPath);
        }
    }

    public function test_scheduled_command_prunes_abandoned_preview_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('keyword-import-previews/22222222-2222-4222-8222-222222222222.xlsx', 'old');
        $this->travel(31)->minutes();

        $this->artisan('geoflow:prune-keyword-import-previews')->assertSuccessful()->expectsOutputToContain('Pruned keyword import previews: 1');
        Storage::disk('local')->assertMissing('keyword-import-previews/22222222-2222-4222-8222-222222222222.xlsx');
    }

    public function test_queue_dispatch_failure_marks_the_persisted_batch_failed_and_returns_a_form_error(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $fixture = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');
        $preview = app(KeywordImportPreviewSession::class)->begin(
            new UploadedFile($fixture, 'queue-failure.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'gsc',
            [],
            (int) $admin->id,
        );
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('queue unavailable'));

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), [
            'preview_token' => $preview['token'], 'library_id' => $this->library()->id, 'source_type' => 'gsc', 'mapping' => $preview['resolved_mapping'],
        ])->assertSessionHasErrors('preview_token');

        $batch = KeywordImportBatch::query()->sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(__('admin.keyword_intelligence.validation.dispatch_failed'), $batch->error_summary);
        Storage::disk('local')->assertExists($batch->stored_path);
        Storage::disk('local')->assertMissing(json_decode(Crypt::decryptString($preview['token']), true)['path']);
    }

    public function test_taxonomy_and_scoring_domain_failures_return_localized_form_errors(): void
    {
        $admin = $this->admin();
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => 'dresses', 'depth' => 0, 'sort_order' => 0]);
        $this->actingAs($admin, 'admin')->from(route('admin.keyword-intelligence.taxonomy'))->post(route('admin.keyword-intelligence.taxonomy.nodes.store'), ['taxonomy_id' => $taxonomy->id, 'name' => 'Duplicate', 'slug' => 'dresses'])
            ->assertRedirect(route('admin.keyword-intelligence.taxonomy'))->assertSessionHasErrors('name');

        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'score dress', 'normalized_keyword' => 'score dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $cluster = KeywordCluster::query()->create(['name' => 'Score Dress', 'slug' => 'score-dress', 'taxonomy_node_id' => KeywordNode::query()->value('id'), 'primary_keyword_id' => $keyword->id, 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'search_intent' => 'commercial', 'status' => 'active']);
        config()->set('keyword_intelligence.opportunity_scoring.weights.non_brand', []);
        $this->actingAs($admin, 'admin')->from(route('admin.keyword-intelligence.clusters.show', $cluster))->post(route('admin.keyword-intelligence.clusters.score', $cluster), ['period_start' => '2026-07-01', 'period_end' => '2026-07-31'])
            ->assertRedirect(route('admin.keyword-intelligence.clusters.show', $cluster))->assertSessionHasErrors('period_start');
    }

    public function test_invalid_cluster_confirmation_returns_a_localized_form_error(): void
    {
        $this->actingAs($this->admin(), 'admin')->from(route('admin.keyword-intelligence.clusters'))->post(route('admin.keyword-intelligence.clusters.confirm'), [
            'candidate_token' => 'client-crafted-candidate',
        ])->assertRedirect(route('admin.keyword-intelligence.clusters'))->assertSessionHasErrors('candidate_token');
    }

    public function test_admin_can_confirm_a_server_validated_cluster_and_recompute_its_score(): void
    {
        $admin = $this->admin();
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => 'dresses', 'depth' => 0, 'sort_order' => 0]);
        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'party dress', 'normalized_keyword' => 'party dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $node->keywords()->attach($keyword->id, ['is_primary' => true]);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$keyword])[0];

        $token = app(KeywordClusterCandidateToken::class)->issue($suggestion, (int) $admin->id);
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.clusters.confirm'), ['candidate_token' => $token])->assertRedirect();
        $cluster = KeywordCluster::query()->sole();
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.clusters.score', $cluster), ['period_start' => '2026-07-01', 'period_end' => '2026-07-31'])->assertRedirect();
        $this->assertDatabaseHas('keyword_opportunity_scores', ['cluster_id' => $cluster->id, 'period_start' => '2026-07-01 00:00:00', 'period_end' => '2026-07-31 00:00:00']);
    }

    public function test_preview_and_cluster_tokens_are_bound_to_the_issuing_admin(): void
    {
        Storage::fake('local');
        $owner = $this->admin();
        $other = Admin::query()->create(['username' => 'other_keyword_admin', 'password' => 'secret', 'email' => 'other@example.com', 'display_name' => 'Other Admin', 'role' => 'admin', 'status' => 'active']);
        $fixture = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');
        $preview = app(KeywordImportPreviewSession::class)->begin(
            new UploadedFile($fixture, 'scoped.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'gsc',
            [],
            (int) $owner->id,
        );

        $payload = ['preview_token' => $preview['token'], 'library_id' => $this->library()->id, 'source_type' => 'gsc', 'mapping' => $preview['resolved_mapping']];
        $this->actingAs($other, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), $payload)->assertSessionHasErrors('preview_token');
        $this->assertDatabaseCount('keyword_import_batches', 0);
        $this->actingAs($owner, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), $payload)->assertRedirect(route('admin.keyword-intelligence.index'));

        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Scoped Products', 'slug' => 'scoped-products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Scoped Dresses', 'slug' => 'scoped-dresses', 'path' => 'scoped-dresses', 'depth' => 0, 'sort_order' => 0]);
        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'scoped party dress', 'normalized_keyword' => 'scoped party dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $node->keywords()->attach($keyword->id, ['is_primary' => true]);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$keyword])[0];
        $candidateToken = app(KeywordClusterCandidateToken::class)->issue($suggestion, (int) $owner->id);

        $this->actingAs($other, 'admin')->post(route('admin.keyword-intelligence.clusters.confirm'), ['candidate_token' => $candidateToken])->assertSessionHasErrors('candidate_token');
        $this->assertDatabaseCount('keyword_clusters', 0);
        $this->actingAs($owner, 'admin')->post(route('admin.keyword-intelligence.clusters.confirm'), ['candidate_token' => $candidateToken])->assertRedirect();
        $this->assertDatabaseCount('keyword_clusters', 1);
    }

    public function test_overview_renders_distinct_copy_for_every_import_state(): void
    {
        $library = $this->library();
        foreach (['uploaded', 'processing', 'failed', 'completed', 'reverting', 'reverted'] as $index => $status) {
            KeywordImportBatch::query()->create(['library_id' => $library->id, 'original_filename' => "{$status}.xlsx", 'stored_path' => "keyword-imports/{$status}.xlsx", 'file_sha256' => str_repeat(dechex($index + 1), 64), 'source_type' => 'gsc', 'status' => $status, 'mapping_json' => [], 'metadata_json' => []]);
        }

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.keyword-intelligence.index'))->assertOk();
        foreach (['uploaded', 'processing', 'failed', 'completed', 'reverting', 'reverted'] as $status) {
            $response->assertSee(__('admin.keyword_intelligence.status.'.$status));
        }
    }

    private function admin(): Admin
    {
        return Admin::query()->firstOrCreate(['username' => 'keyword_admin'], ['password' => 'secret', 'email' => 'keyword@example.com', 'display_name' => 'Keyword Admin', 'role' => 'admin', 'status' => 'active']);
    }

    private function library(): KeywordLibrary
    {
        return KeywordLibrary::query()->firstOrCreate(['name' => 'LITB'], ['description' => '', 'keyword_count' => 0]);
    }
}
