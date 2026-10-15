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

    /**
     * C2: only runs that actually became Articles may count against the Task's
     * `article_limit`. Without this a quality-gated Task never reaches its cap
     * (`is_loop=1` generates without bound) or never reaches "done" (`is_loop=0`).
     */
    public function test_a_successful_adoption_increments_the_tasks_generation_counters(): void
    {
        $context = $this->taskDrivenRun(['need_review' => 1, 'publish_interval' => 900]);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $task = $context['task']->fresh();
        $this->assertSame(1, (int) $task->created_count);
        $this->assertSame(1, (int) $task->loop_count);
    }

    /**
     * C2: `next_publish_at` is seeded on adoption using the legacy path's exact
     * `max(60, publish_interval ?? 3600)` rule, so the first article respects the
     * Task's pacing instead of being released immediately.
     */
    public function test_adoption_seeds_next_publish_at_when_it_is_null_or_already_due(): void
    {
        $context = $this->taskDrivenRun(['need_review' => 1, 'publish_interval' => 900]);
        $this->assertNull($context['task']->next_publish_at);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $task = $context['task']->fresh();
        $this->assertNotNull($task->next_publish_at);
        $this->assertTrue($task->next_publish_at->greaterThan(now()->addSeconds(840)));
        $this->assertTrue($task->next_publish_at->lessThanOrEqualTo(now()->addSeconds(900)));
    }

    /**
     * C2: a future `next_publish_at` is the Task's own pacing decision and must not
     * be pushed back by an adoption.
     */
    public function test_adoption_leaves_a_future_next_publish_at_untouched(): void
    {
        $future = now()->addHours(5);
        $context = $this->taskDrivenRun(['need_review' => 1, 'publish_interval' => 900, 'next_publish_at' => $future]);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $this->assertSame(
            $future->toDateTimeString(),
            $context['task']->fresh()->next_publish_at->toDateTimeString()
        );
    }

    /**
     * C3: `GenerationAdoptionService::adopt()` always creates draft/pending (it is
     * shared with the manual HTTP flow and must not change). A `need_review=0` Task
     * means zero-touch automation, so the command has to approve the article the same
     * way the legacy engine does — otherwise `publishDueDraftArticle()`, which only
     * selects approved/auto_approved, never picks it up and the Task stalls forever.
     */
    public function test_a_need_review_zero_task_gets_its_adopted_article_approved(): void
    {
        $context = $this->taskDrivenRun(['need_review' => 0]);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $article = Article::query()->findOrFail($context['run']->fresh()->article_id);
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
    }

    /**
     * C3 (control): `need_review=1` must keep the existing pending behaviour.
     */
    public function test_a_need_review_one_task_leaves_its_adopted_article_pending(): void
    {
        $context = $this->taskDrivenRun(['need_review' => 1]);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $article = Article::query()->findOrFail($context['run']->fresh()->article_id);
        $this->assertSame('pending', $article->review_status);
    }

    /**
     * I7: `ArticleGeoFlowService` throws `ApiException` (a RuntimeException, unrelated
     * to DomainException) for validation failures. Catching only DomainException let a
     * single bad run abort the whole batch, which then re-failed identically every
     * minute on the scheduler.
     */
    public function test_one_failing_run_does_not_abort_the_rest_of_the_batch(): void
    {
        Log::spy();

        // An H1 longer than 255 characters makes `ArticleGeoFlowService` throw an
        // `ApiException` — a RuntimeException subtype with no DomainException in its
        // ancestry. This run is created first, so it is reached first in the
        // id-ordered batch; the healthy run after it must still be adopted.
        $overlongTitle = str_repeat('A very long headline segment ', 12);
        $this->assertGreaterThan(255, mb_strlen($overlongTitle));
        $broken = $this->taskDrivenRun(['need_review' => 1], '# '.$overlongTitle."\n\nBody text.");
        $healthy = $this->taskDrivenRun(['need_review' => 1]);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $broken['run']->fresh()->status);
        $this->assertSame(ContentGenerationRun::STATUS_ADOPTED, $healthy['run']->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Task-driven run could not be auto-adopted'
                && (int) $context['content_generation_run_id'] === (int) $broken['run']->id);
    }

    /**
     * I2: `show_references_section` is read from the Preset version's
     * `blueprint_template` (never shown to the model), not from the per-brief
     * blueprint that `PromptCompiler` dumps verbatim into the prompt.
     */
    public function test_renders_a_references_section_when_the_preset_enables_it(): void
    {
        $context = $this->taskDrivenRun(
            ['need_review' => 1],
            null,
            ['show_references_section' => true],
            ['https://example.com/a', 'https://example.com/b'],
        );

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $article = Article::query()->findOrFail($context['run']->fresh()->article_id);
        $this->assertStringContainsString('## References', $article->content);
        $this->assertStringContainsString('https://example.com/a', $article->content);
        $this->assertStringContainsString('https://example.com/b', $article->content);
    }

    /**
     * I2 (plan's Testing section): a Preset with `show_references_section=false`
     * never renders a references section even when reference_urls were supplied.
     */
    public function test_never_renders_a_references_section_when_the_preset_disables_it(): void
    {
        $context = $this->taskDrivenRun(
            ['need_review' => 1],
            null,
            ['show_references_section' => false],
            ['https://example.com/supplied-but-unused'],
        );

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $article = Article::query()->findOrFail($context['run']->fresh()->article_id);
        $this->assertStringNotContainsString('## References', $article->content);
        $this->assertStringNotContainsString('https://example.com/supplied-but-unused', $article->content);
    }

    /**
     * I2 (plan's Testing section): per `ReferenceEvidenceFetcher`'s "retained for
     * citation even on fetch failure" design, the references list renders from the
     * brief's own `reference_urls` — so a URL that failed to fetch still gets cited.
     */
    public function test_a_reference_url_that_failed_to_fetch_still_appears_in_the_references_list(): void
    {
        // No evidence rows exist for this run at all, i.e. nothing was fetched
        // successfully — the URL must still be listed.
        $context = $this->taskDrivenRun(
            ['need_review' => 1],
            null,
            ['show_references_section' => true],
            ['https://unreachable.invalid/dead-link'],
        );

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $article = Article::query()->findOrFail($context['run']->fresh()->article_id);
        $this->assertStringContainsString('https://unreachable.invalid/dead-link', $article->content);
    }

    /**
     * @param  array<string, mixed>  $taskAttributes
     * @param  array<string, mixed>  $blueprintTemplateOverrides
     * @param  list<string>  $referenceUrls
     * @return array{task: Task, brief: ContentBrief, run: ContentGenerationRun}
     */
    private function taskDrivenRun(
        array $taskAttributes = [],
        ?string $rawOutput = null,
        array $blueprintTemplateOverrides = [],
        array $referenceUrls = [],
    ): array {
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $author = Author::query()->create(['name' => 'Test Author '.uniqid()]);
        $category = Category::query()->create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid()]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $task = Task::query()->create(array_merge([
            'name' => 'Test task', 'title_library_id' => $library->id, 'status' => 'active',
            'generation_engine' => 'quality_gated', 'schedule_enabled' => 1,
            'author_id' => $author->id, 'category_mode' => 'fixed', 'fixed_category_id' => $category->id,
        ], $taskAttributes));

        $brief = ContentBrief::forceCreate([
            'topic' => 'Running shoes', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x', 'category_id' => $category->id,
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id, 'reference_urls' => $referenceUrls,
        ]);

        $run = $this->createReadyForReviewRun($brief, $rawOutput, $blueprintTemplateOverrides);

        return ['task' => $task, 'brief' => $brief, 'run' => $run];
    }

    /** @param array<string, mixed> $blueprintTemplateOverrides */
    private function createReadyForReviewRun(ContentBrief $brief, ?string $rawOutput = null, array $blueprintTemplateOverrides = []): ContentGenerationRun
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.adopt-task-driven-runs-'.uniqid(), 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true,
        ]);
        $presetVersion = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => array_merge(['target_length' => 100, 'sections' => []], $blueprintTemplateOverrides),
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
