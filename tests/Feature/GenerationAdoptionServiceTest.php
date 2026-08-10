<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunStep;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationAdoptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Author $author;

    private Category $category;

    private GenerationAdoptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::query()->create([
            'username' => 'generation-adopter',
            'password' => 'secret-123',
            'email' => 'generation-adopter@example.com',
            'display_name' => 'Generation Adopter',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->author = Author::query()->create([
            'name' => 'Generated Content Author',
            'email' => 'generated-author@example.com',
        ]);
        $this->category = Category::query()->create([
            'name' => 'Generated Content',
            'slug' => 'generated-content',
        ]);
        $this->service = $this->app->make(GenerationAdoptionService::class);
    }

    public function test_ready_run_adopts_once_as_a_pending_draft_and_records_its_source(): void
    {
        $run = $this->readyRun();

        $article = $this->service->adopt($run, $this->articleData(), $this->admin);

        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame($run->raw_output, $article->content);
        $this->assertSame('api_save', $article->latestRiskScan->trigger);
        $this->assertSame($this->admin->id, $article->latestRiskScan->admin_id);

        $freshRun = $run->fresh();
        $this->assertSame($article->id, $freshRun->article_id);
        $this->assertSame(ContentGenerationRun::STATUS_ADOPTED, $freshRun->status);
        $this->assertSame($run->raw_output, $freshRun->adopted_output);
        $this->assertSame($this->admin->id, $freshRun->adopted_by_admin_id);
        $this->assertSame($freshRun->id, $article->generationRuns()->sole()->id);
        $adoption = $freshRun->steps()->where('step_type', ContentGenerationRunStep::TYPE_ADOPTION)->sole();
        $this->assertSame(ContentGenerationRunStep::STATUS_COMPLETED, $adoption->status);
        $this->assertSame($this->admin->id, $adoption->input_snapshot['actor_admin_id']);
        $this->assertSame(hash('sha256', $run->raw_output), $adoption->input_snapshot['raw_output_sha256']);
        $this->assertSame(hash('sha256', $article->content), $adoption->input_snapshot['adopted_output_sha256']);
        $this->assertSame(hash('sha256', $freshRun->compiled_prompt), $adoption->input_snapshot['compiled_prompt_sha256']);
    }

    public function test_only_ready_for_review_runs_can_be_adopted(): void
    {
        $run = $this->readyRun(ContentGenerationRun::STATUS_FAILED);

        $this->expectException(DomainException::class);

        $this->service->adopt($run, $this->articleData(), $this->admin);
    }

    public function test_unreviewed_run_cannot_be_adopted(): void
    {
        $run = $this->readyRun(ContentGenerationRun::STATUS_COMPLETED);

        $this->expectException(DomainException::class);

        $this->service->adopt($run, $this->articleData(), $this->admin);
    }

    public function test_stale_run_cannot_create_an_article_after_another_process_changes_its_state(): void
    {
        $run = $this->readyRun();
        ContentGenerationRun::query()->whereKey($run->id)->update([
            'status' => ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
        ]);

        try {
            $this->service->adopt($run, $this->articleData(), $this->admin);
            $this->fail('Expected adoption to reject a run whose current state changed.');
        } catch (DomainException) {
            $this->assertSame(0, Article::query()->count());
        }
    }

    public function test_second_adoption_of_the_same_stale_run_returns_the_original_article_without_a_duplicate(): void
    {
        $run = $this->readyRun();
        $first = $this->service->adopt($run, $this->articleData(), $this->admin);
        $second = $this->service->adopt($run, $this->articleData(['title' => 'A conflicting retry title']), $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Article::query()->count());
        $this->assertSame(1, $run->fresh()->steps()->where('step_type', ContentGenerationRunStep::TYPE_ADOPTION)->count());
    }

    /** @param array<string, mixed> $overrides */
    private function articleData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Generated running shoe guide',
            'excerpt' => 'A practical guide to choosing running shoes.',
            'keywords' => 'running shoes, fit',
            'meta_description' => 'How to choose running shoes that fit.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ], $overrides);
    }

    private function readyRun(string $status = ContentGenerationRun::STATUS_READY_FOR_REVIEW): ContentGenerationRun
    {
        $brief = ContentBrief::query()->create([
            'topic' => 'Running shoe fit',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'First-time runners',
            'core_question' => 'How should running shoes fit?',
            'business_goal' => 'Help readers choose a fit',
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
            'preset_key' => 'adoption-'.str()->uuid(),
            'display_name' => 'Adoption preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Adoption model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'adoption-model',
            'max_tokens' => 4096,
        ]);

        return ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => 'Write a practical running shoe guide.',
            'raw_output' => '## Fit\nChoose shoes with a thumb width in front of the longest toe.',
            'status' => $status,
            'idempotency_key' => 'adoption-'.str()->uuid(),
        ]);
    }
}
