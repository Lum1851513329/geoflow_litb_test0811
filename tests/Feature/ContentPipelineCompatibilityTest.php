<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\ContentGenerationCoordinator;
use App\Jobs\GenerateStrategyContent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\Prompt;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentPipelineCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_strategy_flags_are_disabled_by_default(): void
    {
        $this->assertFalse(config('geoflow.content_strategy_enabled'));
        $this->assertFalse(config('geoflow.modular_prompts_enabled'));
        $this->assertFalse(config('geoflow.generation_quality_gate_enabled'));
    }

    public function test_legacy_worker_uses_existing_renderer_when_modular_prompts_are_disabled(): void
    {
        config()->set('geoflow.modular_prompts_enabled', false);

        $article = $this->runLegacyTask();

        $this->assertNotNull($article);
        $this->assertSame('Legacy article', $article->title);
        $this->assertDatabaseCount('content_generation_runs', 0);
    }

    public function test_disabled_content_strategy_route_returns_not_found(): void
    {
        config()->set('geoflow.content_strategy_enabled', false);
        Route::middleware('geoflow.feature:content_strategy_enabled')
            ->get('/__tests/content-strategy', static fn () => response()->noContent());

        $this->get('/__tests/content-strategy')->assertNotFound();
    }

    public function test_legacy_prompt_crud_remains_available_when_modular_prompts_are_disabled(): void
    {
        config()->set('geoflow.modular_prompts_enabled', false);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => 'Legacy custom prompt',
                'content' => 'Write the legacy article body.',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $prompt = Prompt::query()->where('name', 'Legacy custom prompt')->sole();
        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-prompts.update', $prompt), [
                'name' => 'Updated legacy custom prompt',
                'content' => 'Keep the established renderer contract.',
            ])
            ->assertRedirect(route('admin.ai-prompts'));
        $this->assertDatabaseHas('prompts', [
            'id' => $prompt->id,
            'name' => 'Updated legacy custom prompt',
            'content' => 'Keep the established renderer contract.',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.delete', $prompt))
            ->assertRedirect(route('admin.ai-prompts'));
        $this->assertDatabaseMissing('prompts', ['id' => $prompt->id]);
    }

    public function test_disabled_new_pipeline_job_leaves_a_queued_run_untouched(): void
    {
        config()->set('geoflow.content_strategy_enabled', false);
        config()->set('geoflow.modular_prompts_enabled', false);
        config()->set('geoflow.generation_quality_gate_enabled', false);
        $run = $this->queuedStrategyRun();

        (new GenerateStrategyContent($run->id))->handle(app(ContentGenerationCoordinator::class));

        $this->assertSame(ContentGenerationRun::STATUS_QUEUED, $run->fresh()->status);
    }

    private function runLegacyTask(): Article
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'legacy-chat-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Legacy article\n\nEstablished worker content."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);
        Category::query()->create(['name' => 'Legacy category', 'slug' => 'legacy-category']);
        $model = AiModel::query()->create([
            'name' => 'Legacy worker model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('legacy-api-key'),
            'model_id' => 'legacy-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Legacy title library']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'Legacy article', 'keyword' => 'legacy']);
        $task = Task::query()->create([
            'name' => 'Legacy worker task',
            'title_library_id' => $library->id,
            'ai_model_id' => $model->id,
            'draft_limit' => 10,
            'article_limit' => 10,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        return Article::query()->findOrFail((int) $result['article_id']);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'legacy-prompt-admin',
            'password' => 'secret-123',
            'email' => 'legacy-prompt-admin@example.com',
            'display_name' => 'Legacy Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function queuedStrategyRun(): ContentGenerationRun
    {
        $brief = ContentBrief::query()->create([
            'topic' => 'Queued strategy content',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'Readers',
            'core_question' => 'What is the safe default?',
            'business_goal' => 'Protect the legacy pipeline',
            'content_type' => 'guide',
            'status' => ContentBrief::STATUS_STRATEGY_READY,
        ]);
        $strategy = ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'compatibility-'.str()->uuid(),
            'display_name' => 'Compatibility preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Compatibility model',
            'version' => 'test',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'compatibility-model',
        ]);

        return ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => 'Write safe compatibility content.',
            'status' => ContentGenerationRun::STATUS_QUEUED,
            'idempotency_key' => 'compatibility-'.str()->uuid(),
        ]);
    }
}
