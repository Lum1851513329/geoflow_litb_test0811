<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
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

    public function test_storing_a_new_version_persists_updated_blueprint_template_and_prefills_the_form_from_the_prior_version(): void
    {
        $admin = Admin::query()->create([
            'username' => 'blueprint-template-version-admin',
            'password' => 'secret-123',
            'email' => 'blueprint-template-version-admin@example.test',
            'display_name' => 'Blueprint Template Version Admin',
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

        $preset = PromptPreset::query()->create([
            'preset_key' => 'test-blueprint-template-version',
            'display_name' => 'Test preset for version create',
            'owned_by_admin_id' => $admin->id,
        ]);
        $priorBlueprintTemplate = [
            'target_length' => 1200,
            'sections' => [[
                'key' => 'intro',
                'heading' => 'Intro',
                'purpose' => 'Introduce the topic',
                'questions_to_answer' => ['What is this?'],
                'minimum_words' => 80,
                'cta_allowed' => false,
                'required_fact_ids' => [],
            ]],
        ];
        $priorBriefFields = ['market_code' => 'CA', 'language_code' => 'fr'];
        $priorVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'blueprint_template' => $priorBlueprintTemplate,
            'default_brief_fields' => $priorBriefFields,
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $admin->id,
        ]);
        $preset->forceFill(['current_version_id' => $priorVersion->id])->save();

        // The version-create form must prefill blueprint_template/default_brief_fields
        // from the prior version, exactly like it already does for matching_rules,
        // generation_policy, and conflict_policy.
        $this->actingAs($admin, 'admin')->get(route('admin.prompt-presets.edit', $preset))
            ->assertOk()
            ->assertSee('"target_length": 1200')
            ->assertSee('"market_code": "CA"');

        $newBlueprintTemplate = [
            'target_length' => 2400,
            'sections' => [[
                'key' => 'summary',
                'heading' => 'Summary',
                'purpose' => 'Summarize the findings',
                'questions_to_answer' => ['What changed?'],
                'minimum_words' => 150,
                'cta_allowed' => true,
                'required_fact_ids' => [],
            ]],
        ];
        $newBriefFields = ['market_code' => 'US', 'language_code' => 'en'];

        $response = $this->actingAs($admin, 'admin')->post(route('admin.prompt-presets.versions.store', $preset), [
            'preset_key' => $preset->preset_key,
            'display_name' => $preset->display_name,
            'matching_rules' => '{}',
            'generation_policy' => '{}',
            'blueprint_template' => json_encode($newBlueprintTemplate),
            'default_brief_fields' => json_encode($newBriefFields),
            'module_versions' => [
                ['prompt_module_version_id' => $moduleVersion->id, 'sort_order' => 1],
            ],
        ]);

        $response->assertRedirect();
        $newVersion = $preset->versions()->orderByDesc('version')->firstOrFail();
        $this->assertSame(2, $newVersion->version);
        $this->assertSame(2400, $newVersion->blueprint_template['target_length']);
        $this->assertSame('US', $newVersion->default_brief_fields['market_code']);

        // The prior version is immutable and must be unaffected by the new one.
        $priorVersion->refresh();
        $this->assertSame(1200, $priorVersion->blueprint_template['target_length']);
        $this->assertSame('CA', $priorVersion->default_brief_fields['market_code']);
    }
}
