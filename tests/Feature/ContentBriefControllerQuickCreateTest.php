<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentBriefControllerQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config()->set([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);
    }

    public function test_submitting_a_preset_id_creates_a_fully_confirmed_brief_and_skips_to_show(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brief-quick-create-tester',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.controller-quick-create',
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
                    'key' => 'answer', 'heading' => 'Direct answer', 'purpose' => 'Answer it.',
                    'questions_to_answer' => ['{{core_question}}'], 'minimum_words' => 100,
                    'cta_allowed' => false, 'required_fact_ids' => [],
                ]],
                'required_entities' => [], 'show_references_section' => false,
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.content-briefs.store'), [
            'prompt_preset_id' => $preset->id,
            'topic' => 'How to Choose Running Shoes',
            'core_question' => 'What should a buyer check first?',
        ]);

        $brief = ContentBrief::query()->where('topic', 'How to Choose Running Shoes')->firstOrFail();
        $response->assertRedirect(route('admin.content-briefs.show', $brief));
        $this->assertSame(ContentBrief::STATUS_STRATEGY_READY, $brief->status);
        $this->assertTrue($brief->strategyVersions()->exists());
        $this->assertTrue($brief->blueprintVersions()->exists());
    }
}
