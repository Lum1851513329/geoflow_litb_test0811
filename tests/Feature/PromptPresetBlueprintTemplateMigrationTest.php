<?php

namespace Tests\Feature;

use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptPresetBlueprintTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_template_and_default_brief_fields_are_nullable_and_cast_to_array(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.blueprint-template-migration',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
        ]);

        $this->assertNull($version->blueprint_template);
        $this->assertNull($version->default_brief_fields);

        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 2,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'default_brief_fields' => ['market_code' => 'US'],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
        ]);
        $version->refresh();

        $this->assertSame(['target_length' => 1800, 'sections' => []], $version->blueprint_template);
        $this->assertSame(['market_code' => 'US'], $version->default_brief_fields);
    }

    public function test_blueprint_template_cannot_be_changed_after_insert(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.blueprint-template-immutable',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $version->forceFill(['blueprint_template' => ['target_length' => 2000, 'sections' => []]])->save();
    }
}
