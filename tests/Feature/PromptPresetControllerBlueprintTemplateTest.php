<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptPresetControllerBlueprintTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These admin routes are feature-gated before authentication, and CSRF
        // verification is not otherwise disabled for POST feature tests here —
        // mirrors the setUp() pattern in AdminPromptModulesTest.
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config()->set('geoflow.modular_prompts_enabled', true);
    }

    public function test_storing_a_preset_persists_blueprint_template_and_default_brief_fields(): void
    {
        $admin = Admin::query()->create([
            'username' => 'blueprint-template-admin',
            'password' => 'secret-123',
            'email' => 'blueprint-template-admin@example.test',
            'display_name' => 'Blueprint Template Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $module = PromptModule::query()->create([
            'module_key' => 'test.module.'.uniqid(),
            'display_name' => 'Test module',
            'module_type' => PromptModule::TYPE_FOUNDATION,
            'is_system' => false,
            'is_mandatory' => false,
            'default_priority' => 0,
            'is_enabled' => true,
        ]);
        $moduleVersion = PromptModuleVersion::query()->forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'Test content.',
            'variable_contract' => [],
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);
        $module->update(['current_version_id' => $moduleVersion->id]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.prompt-presets.store'), [
            'preset_key' => 'test-blueprint-template-form',
            'display_name' => 'Test preset',
            'matching_rules' => '{}',
            'generation_policy' => '{}',
            'blueprint_template' => json_encode([
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'intro',
                    'heading' => 'Overview',
                    'purpose' => 'Summarize the answer',
                    'questions_to_answer' => ['What is this about?'],
                    'minimum_words' => 100,
                    'cta_allowed' => false,
                    'required_fact_ids' => [],
                ]],
            ]),
            'default_brief_fields' => json_encode(['market_code' => 'US', 'language_code' => 'en']),
            'module_versions' => [
                ['prompt_module_version_id' => $moduleVersion->id, 'sort_order' => 1],
            ],
        ]);

        $response->assertRedirect();
        $preset = PromptPreset::query()->where('preset_key', 'test-blueprint-template-form')->firstOrFail();
        $version = $preset->currentVersion;
        $this->assertSame(1800, $version->blueprint_template['target_length']);
        $this->assertSame('US', $version->default_brief_fields['market_code']);
    }
}
