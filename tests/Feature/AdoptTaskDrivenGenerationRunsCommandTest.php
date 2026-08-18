<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class AdoptTaskDrivenGenerationRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_adopts_a_ready_for_review_run_and_extracts_the_ai_title(): void
    {
        // The `geoflow-system` Admin is permanently seeded by
        // `2026_08_15_000400_seed_geoflow_system_admin.php`, which RefreshDatabase re-runs
        // for every test — so it already exists here and must not be created again.
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $author = Author::query()->create(['name' => 'Test Author']);
        $category = Category::query()->create(['name' => 'Test Category', 'slug' => 'test-category']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $task = Task::query()->create([
            'name' => 'Test task', 'title_library_id' => $library->id, 'status' => 'active',
            'generation_engine' => 'quality_gated', 'schedule_enabled' => 1,
            'author_id' => $author->id, 'category_mode' => 'fixed', 'fixed_category_id' => $category->id,
        ]);
        // `ContentBrief.task_id` is deliberately excluded from the model's `$fillable`
        // (only `ContentBriefQuickCreateService` is meant to set it, via `forceCreate()`)
        // — `->query()->create([..., 'task_id' => ...])` would silently drop it.
        $brief = ContentBrief::forceCreate([
            'topic' => 'Running shoes', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x', 'category_id' => $category->id,
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id,
        ]);
        $run = $this->createReadyForReviewRun($brief);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentGenerationRun::STATUS_ADOPTED, $run->status);
        $article = Article::query()->findOrFail($run->article_id);
        $this->assertSame('How to Choose Running Shoes', $article->title);
        $this->assertStringNotContainsString('# How to Choose Running Shoes', $article->content);
        $this->assertSame((int) $task->id, $article->task_id);
        $this->assertSame((int) $author->id, $article->author_id);
        $this->assertSame((int) $category->id, $article->category_id);
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
    }

    public function test_does_not_adopt_runs_belonging_to_a_paused_task(): void
    {
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $task = Task::query()->create([
            'name' => 'Test task', 'title_library_id' => $library->id, 'status' => 'paused',
            'generation_engine' => 'quality_gated', 'schedule_enabled' => 1,
        ]);
        $brief = ContentBrief::forceCreate([
            'topic' => 'Running shoes', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id,
        ]);
        $run = $this->createReadyForReviewRun($brief);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->status);
        $this->assertNull($run->article_id);
    }

    public function test_falls_back_to_a_default_author_and_category_when_none_are_configured(): void
    {
        // No Author/Category rows exist anywhere, and the Task has no author/category
        // configured either. `ArticleGeoFlowService::normalizeCreateInput()` treats both
        // `author_id` and `category_id` as required references — the command must fall
        // back the same way `WorkerExecutionService::pickAuthor()`/`pickCategory()` do
        // rather than passing null and letting adoption blow up with an uncaught exception.
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $category = Category::query()->create(['name' => 'Only Category', 'slug' => 'only-category']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $task = Task::query()->create([
            'name' => 'Test task', 'title_library_id' => $library->id, 'status' => 'active',
            'generation_engine' => 'quality_gated', 'schedule_enabled' => 1,
        ]);
        $brief = ContentBrief::forceCreate([
            'topic' => 'Running shoes', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id,
        ]);
        $run = $this->createReadyForReviewRun($brief);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentGenerationRun::STATUS_ADOPTED, $run->status);
        $article = Article::query()->findOrFail($run->article_id);
        $this->assertSame('GEOFlow', Author::query()->findOrFail($article->author_id)->name);
        $this->assertSame((int) $category->id, $article->category_id);
    }

    public function test_does_not_adopt_a_run_whose_output_has_no_leading_h1_title(): void
    {
        // The spec forbids silently falling back to another title (e.g. `brief->topic`)
        // when the AI's markdown doesn't open with a genuine H1 — such a run must be
        // left for manual review instead of being adopted under a guessed title.
        Log::spy();

        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $author = Author::query()->create(['name' => 'Test Author']);
        $category = Category::query()->create(['name' => 'Test Category', 'slug' => 'test-category-no-h1']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $task = Task::query()->create([
            'name' => 'Test task', 'title_library_id' => $library->id, 'status' => 'active',
            'generation_engine' => 'quality_gated', 'schedule_enabled' => 1,
            'author_id' => $author->id, 'category_mode' => 'fixed', 'fixed_category_id' => $category->id,
        ]);
        $brief = ContentBrief::forceCreate([
            'topic' => 'Running shoes', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x', 'category_id' => $category->id,
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id,
        ]);
        $run = $this->createReadyForReviewRun($brief, "## Some Section\n\nBody text that never opens with an H1.");

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->status);
        $this->assertNull($run->article_id);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Task-driven run reached ready_for_review without a leading H1 title; leaving for manual review',
                ['content_generation_run_id' => $run->id]
            );
        Log::shouldNotHaveReceived('error');
    }

    private function createReadyForReviewRun(ContentBrief $brief, ?string $rawOutput = null): ContentGenerationRun
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.adopt-task-driven-runs', 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true,
        ]);
        $presetVersion = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 100, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Test model', 'status' => 'active', 'model_type' => 'chat',
            'api_url' => 'https://example.invalid', 'api_key' => 'test', 'model_id' => 'test-model',
        ]);
        $strategy = ContentStrategyVersion::query()->forceCreate([
            'content_brief_id' => $brief->id, 'version' => 1, 'strategy' => [],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::query()->forceCreate([
            'content_brief_id' => $brief->id, 'content_strategy_version_id' => $strategy->id, 'version' => 1,
            'blueprint' => ['working_title' => 'Running shoes', 'target_length' => 100, 'sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);

        return ContentGenerationRun::query()->forceCreate([
            'content_brief_id' => $brief->id, 'strategy_version_id' => $strategy->id, 'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id, 'ai_model_id' => $aiModel->id,
            'compiled_prompt' => 'test compiled prompt',
            'raw_output' => $rawOutput ?? "# How to Choose Running Shoes\n\nBody text about running shoes.",
            'status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW,
            'idempotency_key' => 'test-'.uniqid('', true),
        ]);
    }
}
