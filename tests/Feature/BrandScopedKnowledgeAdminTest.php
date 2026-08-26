<?php

namespace Tests\Feature;

use App\Domain\ContentStrategy\ContentBriefQuickCreateService;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\ContentGenerationRun;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BrandScopedKnowledgeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        config(['geoflow.content_strategy_enabled' => true, 'geoflow.modular_prompts_enabled' => true, 'geoflow.generation_quality_gate_enabled' => true]);
        $actor = Admin::where('username', 'geoflow-system')->sole();
        $this->actingAs($actor, 'admin');
        $brand = KnowledgeBrand::create(['key' => 'brand', 'name' => 'Brand']);
        $site = KnowledgeSite::create(['brand_id' => $brand->id, 'key' => 'shop', 'canonical_host' => 'shop.test', 'role' => 'commerce-primary']);
        $directory = KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'care', 'path' => 'care', 'name' => 'Care']);
        $scope = ['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $brand->id, 'knowledge_site_id' => $site->id, 'knowledge_directory_id' => $directory->id];
        $base = KnowledgeBase::create(['name' => 'Care', 'brand_id' => $brand->id, 'site_id' => $site->id, 'primary_directory_id' => $directory->id, 'language_code' => 'en', 'review_status' => 'reviewed', 'lifecycle_status' => 'active', 'generation_eligible' => true]);
        $chunk = KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'content' => 'Linen dress care uses cold water.']);
        $preset = PromptPreset::create(['preset_key' => 'test', 'display_name' => 'Test', 'is_enabled' => true]);
        $version = PromptPresetVersion::forceCreate(['prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [], 'blueprint_template' => ['sections' => []], 'lifecycle_status' => 'production']);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $brief = app(ContentBriefQuickCreateService::class)->create(array_merge(['topic' => 'Linen dress care'], $scope), $preset, $actor);

        return compact('actor', 'brand', 'site', 'directory', 'scope', 'base', 'chunk', 'preset', 'version', 'brief');
    }

    public function test_create_forms_default_to_brand_auto_and_edit_preserves_legacy(): void
    {
        $this->fixture();
        Category::create(['name' => 'Care', 'slug' => 'care']);
        foreach (['admin.tasks.create', 'admin.content-briefs.create'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('value="brand_auto" selected', false)->assertSee('name="knowledge_site_id"', false);
        }
        $task = Task::create(['name' => 'Legacy', 'status' => 'paused']);
        $this->get(route('admin.tasks.edit', $task->id))->assertOk()->assertSee('value="legacy_manual" selected', false);
    }

    public function test_quick_create_http_preserves_scope_and_explicit_language(): void
    {
        extract($this->fixture());
        $this->post(route('admin.content-briefs.store'), array_merge($scope, ['topic' => 'French care', 'core_question' => 'Care?', 'prompt_preset_id' => $preset->id, 'language_code' => 'fr']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('content_briefs', ['topic' => 'French care', 'knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $brand->id, 'knowledge_site_id' => $site->id, 'language_code' => 'fr']);
    }

    public function test_brief_rejects_foreign_site_before_creation(): void
    {
        extract($this->fixture());
        $other = KnowledgeBrand::create(['key' => 'other', 'name' => 'Other']);
        $scope['knowledge_brand_id'] = $other->id;
        $this->post(route('admin.content-briefs.store'), array_merge($scope, ['topic' => 'Unsafe', 'core_question' => 'Care?', 'prompt_preset_id' => $preset->id]))->assertSessionHasErrors('knowledge_site_id');
        $this->assertDatabaseMissing('content_briefs', ['topic' => 'Unsafe']);
    }

    public function test_task_post_persists_scope_and_edit_loads_all_fields(): void
    {
        extract($this->fixture());
        Category::create(['name' => 'Care', 'slug' => 'care']);
        $library = TitleLibrary::create(['name' => 'Titles']);
        $model = AiModel::create(['name' => 'Test', 'model_id' => 'test', 'api_key' => 'test', 'api_url' => 'https://example.invalid', 'status' => 'active']);
        $this->post(route('admin.tasks.store'), array_merge($scope, ['task_name' => 'Scoped task', 'title_library_id' => $library->id, 'ai_model_id' => $model->id, 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id, 'status' => 'paused']))->assertSessionHasNoErrors();
        $task = Task::where('name', 'Scoped task')->sole();
        $this->assertSame($brand->id, $task->knowledge_brand_id);
        $this->get(route('admin.tasks.edit', $task->id))->assertOk()->assertViewHas('taskForm', fn ($form) => $form['knowledge_mode'] === 'brand_auto' && (int) $form['knowledge_directory_id'] === $directory->id);
    }

    public function test_preview_is_read_only_scoped_and_honors_exclusions(): void
    {
        extract($this->fixture());
        $unreviewed = KnowledgeBase::create(['name' => 'Unreviewed', 'brand_id' => $brand->id, 'site_id' => $site->id, 'primary_directory_id' => $directory->id, 'language_code' => 'en', 'review_status' => 'unreviewed', 'lifecycle_status' => 'active', 'generation_eligible' => true]);
        KnowledgeChunk::create(['knowledge_base_id' => $unreviewed->id, 'chunk_index' => 0, 'content' => 'Linen dress care SECRET']);
        $this->get(route('admin.content-briefs.show', $brief))->assertOk()->assertSee('Linen dress care uses cold water.')->assertDontSee('SECRET');
        $this->postJson(route('admin.content-briefs.knowledge-preview', $brief), [])->assertOk()->assertJsonPath('chunk_ids.0', $chunk->id)->assertJsonCount(1, 'evidence');
        $this->postJson(route('admin.content-briefs.knowledge-preview', $brief), ['excluded_knowledge_chunk_ids' => [$chunk->id]])->assertOk()->assertJsonCount(0, 'evidence');
        $this->assertSame(0, ContentGenerationRun::count());
    }

    public function test_generation_without_preview_selects_automatically_and_exclusions_reach_reservation(): void
    {
        extract($this->fixture());
        $model = AiModel::create(['name' => 'Test', 'model_id' => 'test', 'api_key' => 'test', 'api_url' => 'https://example.invalid', 'status' => 'active', 'model_type' => 'chat']);
        $request = ['strategy_version_id' => $brief->strategyVersions()->sole()->id, 'blueprint_version_id' => $brief->blueprintVersions()->sole()->id, 'preset_version_id' => $version->id, 'ai_model_id' => $model->id];
        $this->post(route('admin.content-briefs.generations.store', $brief), array_merge($request, ['excluded_knowledge_chunk_ids' => [$chunk->id]]))->assertSessionHasErrors('knowledge_chunk_ids');
        $this->assertSame(0, ContentGenerationRun::count());
        $this->post(route('admin.content-briefs.generations.store', $brief), $request)->assertSessionHasNoErrors();
        $this->assertSame([$chunk->id], ContentGenerationRun::sole()->evidence()->pluck('knowledge_chunk_id')->all());
    }

    public function test_preview_respects_old_manual_selection_after_validation_redirect(): void
    {
        extract($this->fixture());
        $manual = KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 1, 'content' => 'Special manual evidence without matching words.']);
        $this->withSession(['_old_input' => ['knowledge_chunk_ids' => [$manual->id], 'excluded_knowledge_chunk_ids' => [$chunk->id]]])
            ->get(route('admin.content-briefs.show', $brief))->assertOk()->assertSee('Special manual evidence without matching words.')->assertDontSee('Linen dress care uses cold water.');
    }

    public function test_manual_brief_normalizes_unique_site_and_legacy_payload_still_works(): void
    {
        extract($this->fixture());
        $data = ['topic' => 'Manual brand brief', 'core_question' => 'Care?', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'Buyers', 'business_goal' => 'Inform', 'content_type' => 'guide'];
        $this->post(route('admin.content-briefs.store'), array_merge($data, ['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $brand->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('content_briefs', ['topic' => 'Manual brand brief', 'knowledge_mode' => 'brand_auto', 'knowledge_site_id' => $site->id]);
        $data['topic'] = 'Legacy brief';
        $this->post(route('admin.content-briefs.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('content_briefs', ['topic' => 'Legacy brief', 'knowledge_mode' => 'legacy_manual', 'knowledge_brand_id' => null]);
    }

    public function test_preview_rejects_foreign_manual_chunks_and_never_links_unsafe_sources(): void
    {
        extract($this->fixture());
        $other = KnowledgeBrand::create(['key' => 'other', 'name' => 'Other']);
        $otherSite = KnowledgeSite::create(['brand_id' => $other->id, 'key' => 'shop', 'canonical_host' => 'other.test', 'role' => 'commerce-primary']);
        $otherDirectory = KnowledgeDirectory::create(['brand_id' => $other->id, 'key' => 'care', 'path' => 'care', 'name' => 'Care']);
        $foreign = KnowledgeBase::create(['name' => 'Foreign', 'brand_id' => $other->id, 'site_id' => $otherSite->id, 'primary_directory_id' => $otherDirectory->id, 'language_code' => 'en', 'review_status' => 'reviewed', 'lifecycle_status' => 'active', 'generation_eligible' => true]);
        $foreignChunk = KnowledgeChunk::create(['knowledge_base_id' => $foreign->id, 'chunk_index' => 0, 'content' => 'Linen dress care FOREIGN']);
        $this->postJson(route('admin.content-briefs.knowledge-preview', $brief), ['knowledge_chunk_ids' => [$foreignChunk->id]])->assertUnprocessable()->assertJsonValidationErrors('knowledge_chunk_ids');
        $this->postJson(route('admin.content-briefs.knowledge-preview', $brief), [])->assertOk()->assertJsonCount(1, 'evidence')->assertJsonPath('evidence.0.source_url', null);
        $base->update(['source_url' => 'javascript:alert(1)', 'source_name' => '<script>unsafe</script>']);
        $this->get(route('admin.content-briefs.show', $brief))->assertOk()->assertDontSee('FOREIGN')->assertDontSee('href="javascript:', false)->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false);
    }

    public function test_manual_choices_offer_only_eligible_scoped_chunks_even_without_query_matches(): void
    {
        extract($this->fixture());
        KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 1, 'chunk_title' => 'Shipping reference choice', 'content' => 'Delivery takes seven days.']);
        $unreviewed = KnowledgeBase::create(['name' => 'Unreviewed', 'brand_id' => $brand->id, 'site_id' => $site->id, 'primary_directory_id' => $directory->id, 'language_code' => 'en', 'review_status' => 'unreviewed', 'lifecycle_status' => 'active', 'generation_eligible' => true]);
        KnowledgeChunk::create(['knowledge_base_id' => $unreviewed->id, 'chunk_index' => 0, 'chunk_title' => 'Unsafe manual choice', 'content' => 'Delivery takes one day.']);
        $this->get(route('admin.content-briefs.show', $brief))->assertOk()->assertSee('Shipping reference choice')->assertDontSee('Unsafe manual choice');
    }

    public function test_malformed_old_selection_renders_validation_feedback_instead_of_crashing(): void
    {
        extract($this->fixture());
        $this->withSession(['_old_input' => ['knowledge_chunk_ids' => 'bad', 'excluded_knowledge_chunk_ids' => 'bad']])
            ->get(route('admin.content-briefs.show', $brief))->assertOk();
    }
}
