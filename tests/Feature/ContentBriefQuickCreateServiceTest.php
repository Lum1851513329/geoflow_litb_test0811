<?php

namespace Tests\Feature;

use App\Domain\ContentStrategy\ContentBriefQuickCreateService;
use App\Models\Admin;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentBriefQuickCreateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_fully_confirmed_brief_strategy_and_blueprint(): void
    {
        $actor = Admin::query()->create([
            'username' => 'quick-create-tester',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.quick-create',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'blueprint_template' => [
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'answer',
                    'heading' => 'Direct answer',
                    'purpose' => "Answer the brief's core question.",
                    'questions_to_answer' => ['{{core_question}}'],
                    'minimum_words' => 200,
                    'cta_allowed' => false,
                    'required_fact_ids' => [],
                ]],
                'required_entities' => [],
                'show_references_section' => false,
            ],
            'default_brief_fields' => [
                'market_code' => 'US',
                'language_code' => 'en',
                'audience' => 'Test audience',
                'business_goal' => 'Test goal',
                'content_type' => 'buying_guide',
                'cta_strength' => 'soft',
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $task = Task::query()->create(['name' => 'quick-create-source-task', 'status' => 'active']);

        $service = $this->app->make(ContentBriefQuickCreateService::class);
        $brief = $service->create([
            'topic' => 'How to Choose Running Shoes',
            'core_question' => 'What should a first-time buyer check before ordering running shoes online?',
            'task_id' => $task->id,
        ], $preset, $actor);

        $this->assertSame(ContentBrief::STATUS_STRATEGY_READY, $brief->status);
        $this->assertSame('How to Choose Running Shoes', $brief->topic);
        $this->assertSame($task->id, $brief->task_id, 'task_id must survive create() — it is excluded from $fillable and requires forceCreate() (or explicit assignment) to persist.');
        $this->assertDatabaseHas('content_briefs', ['id' => $brief->id, 'task_id' => $task->id]);

        $strategy = $brief->strategyVersions()->firstOrFail();
        $this->assertSame(ContentStrategyVersion::STATUS_CONFIRMED, $strategy->lifecycle_status);
        $this->assertSame('Test audience', $strategy->strategy['audience']);

        $blueprint = $brief->blueprintVersions()->firstOrFail();
        $this->assertSame(ContentBlueprintVersion::STATUS_CONFIRMED, $blueprint->lifecycle_status);
        $this->assertSame('How to Choose Running Shoes', $blueprint->blueprint['working_title']);
        $this->assertSame(
            'What should a first-time buyer check before ordering running shoes online?',
            $blueprint->blueprint['sections'][0]['questions_to_answer'][0],
            'The {{core_question}} placeholder in the template must be substituted with the real core_question.',
        );
        $this->assertStringNotContainsString('{{', json_encode($blueprint->blueprint), 'No unresolved template placeholders may remain in the instantiated blueprint.');
    }

    public function test_rejects_a_preset_without_a_blueprint_template(): void
    {
        $actor = Admin::query()->create([
            'username' => 'quick-create-tester-2',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.no-template',
            'display_name' => 'No template preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $service = $this->app->make(ContentBriefQuickCreateService::class);

        $this->expectException(\DomainException::class);
        $service->create(['topic' => 'Anything'], $preset, $actor);
    }
}
