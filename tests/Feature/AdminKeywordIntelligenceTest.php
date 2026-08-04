<?php

namespace Tests\Feature;

use App\Jobs\ImportKeywordWorkbookJob;
use App\Models\Admin;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordImportBatch;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordClusterSuggestionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.preview'), [
            'workbook' => new UploadedFile($fixture, 'client-name.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'source_type' => 'google_search_console',
            'mapping' => [],
        ])->assertOk()->assertSee(__('admin.keyword_intelligence.import.preview_title'));
        $this->assertDatabaseCount('keyword_import_batches', 0);
        Queue::assertNothingPushed();

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), [
            'workbook' => new UploadedFile($fixture, 'client-name.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'library_id' => $library->id,
            'source_type' => 'google_search_console',
            'mapping' => [],
            'language_code' => 'en',
        ])->assertRedirect();

        $batch = KeywordImportBatch::query()->sole();
        $this->assertStringStartsWith('keyword-imports/', $batch->stored_path);
        $this->assertStringNotContainsString('client-name', $batch->stored_path);
        Storage::disk('local')->assertExists($batch->stored_path);
        Queue::assertPushed(ImportKeywordWorkbookJob::class, fn ($job) => $job->batchId === $batch->id);
    }

    public function test_import_validation_status_and_completed_revert(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.import.confirm'), [])
            ->assertSessionHasErrors(['workbook', 'library_id', 'source_type']);

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

    public function test_invalid_cluster_confirmation_returns_a_localized_form_error(): void
    {
        $this->actingAs($this->admin(), 'admin')->from(route('admin.keyword-intelligence.clusters'))->post(route('admin.keyword-intelligence.clusters.confirm'), [
            'suggestion' => ['name' => 'Incomplete'],
        ])->assertRedirect(route('admin.keyword-intelligence.clusters'))->assertSessionHasErrors('suggestion');
    }

    public function test_admin_can_confirm_a_server_validated_cluster_and_recompute_its_score(): void
    {
        $admin = $this->admin();
        $taxonomy = KeywordTaxonomy::query()->create(['name' => 'Products', 'slug' => 'products', 'status' => 'active']);
        $node = KeywordNode::query()->create(['taxonomy_id' => $taxonomy->id, 'name' => 'Dresses', 'slug' => 'dresses', 'path' => 'dresses', 'depth' => 0, 'sort_order' => 0]);
        $keyword = Keyword::query()->create(['library_id' => $this->library()->id, 'keyword' => 'party dress', 'normalized_keyword' => 'party dress', 'language_code' => 'en', 'market_scope' => 'GLOBAL', 'keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand', 'status' => 'active']);
        $node->keywords()->attach($keyword->id, ['is_primary' => true]);
        $suggestion = app(KeywordClusterSuggestionService::class)->suggest([$keyword])[0];

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.clusters.confirm'), ['suggestion' => $suggestion])->assertRedirect();
        $cluster = KeywordCluster::query()->sole();
        $this->actingAs($admin, 'admin')->post(route('admin.keyword-intelligence.clusters.score', $cluster), ['period_start' => '2026-07-01', 'period_end' => '2026-07-31'])->assertRedirect();
        $this->assertDatabaseHas('keyword_opportunity_scores', ['cluster_id' => $cluster->id, 'period_start' => '2026-07-01 00:00:00', 'period_end' => '2026-07-31 00:00:00']);
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
