<?php

namespace Tests\Feature;

use App\Domain\PromptComposition\PromptCompiler;
use App\Domain\PromptComposition\PromptContext;
use App\Domain\PromptComposition\PromptModuleResolver;
use App\Models\Prompt;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PromptCompositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptCompositionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_PROMPTS = [
        'GEO Marketing · Trust-Based Article Generation (English)' => 'legacy_geo_trust_en',
        'GEO Ranking-Style Article Generation (English)' => 'legacy_geo_ranking_en',
        'GEO营销学·信任型正文生成' => 'legacy_geo_trust_zh',
        'GEO榜单型正文生成' => 'legacy_geo_ranking_zh',
    ];

    private const SHARED_MODULE_KEYS = [
        'foundation_truth',
        'geo_citability',
        'seo_structure',
        'language_market',
        'output_format',
        'quality_checks',
    ];

    public function test_seeder_is_idempotent_and_preserves_legacy_prompts(): void
    {
        $before = Prompt::query()->orderBy('id')->get(['id', 'name', 'type', 'content', 'variables'])
            ->map(fn (Prompt $prompt): array => $prompt->getAttributes())
            ->all();

        $this->seed(PromptCompositionSeeder::class);
        $firstCounts = $this->compositionCounts();
        $this->seed(PromptCompositionSeeder::class);

        $after = Prompt::query()->orderBy('id')->get(['id', 'name', 'type', 'content', 'variables'])
            ->map(fn (Prompt $prompt): array => $prompt->getAttributes())
            ->all();

        $this->assertSame($before, $after);
        $this->assertSame($firstCounts, $this->compositionCounts());
        $this->assertSame(1, PromptModule::query()->where('module_key', 'legacy_content')->count());
        $this->assertSame(7, PromptModule::query()->where('is_system', true)->count());
        $this->assertSame(4, PromptPreset::query()->where('is_system', true)->count());
    }

    public function test_seeder_creates_required_system_modules_and_copies_each_legacy_scenario(): void
    {
        $this->seed(PromptCompositionSeeder::class);

        $this->assertSame(
            [...self::SHARED_MODULE_KEYS, 'legacy_content'],
            PromptModule::query()->where('is_system', true)->orderBy('id')->pluck('module_key')->all(),
        );
        $this->assertSame(
            self::SHARED_MODULE_KEYS,
            PromptModule::query()->whereIn('module_key', self::SHARED_MODULE_KEYS)
                ->where('is_mandatory', true)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->pluck('module_key')
                ->all(),
        );

        $legacyModule = PromptModule::query()->where('module_key', 'legacy_content')->sole();
        $this->assertSame(4, $legacyModule->versions()->count());

        foreach (self::LEGACY_PROMPTS as $promptName => $presetKey) {
            $prompt = Prompt::query()->where('name', $promptName)->where('type', 'content')->sole();
            $preset = PromptPreset::query()->where('preset_key', $presetKey)->sole();
            $presetVersion = $preset->currentVersion()->sole();
            $legacyBinding = $presetVersion->moduleVersions()
                ->whereHas('promptModuleVersion.module', fn ($query) => $query->where('module_key', 'legacy_content'))
                ->sole();
            $copiedVersion = $legacyBinding->promptModuleVersion;

            $this->assertSame(PromptPresetVersion::STATUS_PRODUCTION, $presetVersion->lifecycle_status);
            $this->assertStringContainsString(strtok($prompt->content, "\n"), $copiedVersion->content);
            $this->assertSame((int) $prompt->id, $copiedVersion->parameter_defaults['legacy_prompt_id']);
            $this->assertSame($prompt->name, $copiedVersion->parameter_defaults['legacy_prompt_name']);
            $this->assertSame(hash('sha256', $prompt->content), $copiedVersion->parameter_defaults['legacy_prompt_sha256']);
            $this->assertSame(hash('sha256', $copiedVersion->content), $copiedVersion->content_sha256);
            $this->assertTrue($legacyBinding->is_required);
            $this->assertSame(7, $presetVersion->moduleVersions()->count());
        }
    }

    public function test_seeded_legacy_presets_resolve_and_compile_for_all_four_scenarios(): void
    {
        $this->seed(PromptCompositionSeeder::class);
        $contexts = [
            'legacy_geo_trust_en' => ['language_code' => 'en', 'content_type' => 'guide'],
            'legacy_geo_ranking_en' => ['language_code' => 'en', 'content_type' => 'ranking'],
            'legacy_geo_trust_zh' => ['language_code' => 'zh-CN', 'content_type' => 'guide'],
            'legacy_geo_ranking_zh' => ['language_code' => 'zh-CN', 'content_type' => 'ranking'],
        ];

        foreach ($contexts as $presetKey => $selectionContext) {
            $presetVersion = PromptPreset::query()->where('preset_key', $presetKey)->sole()->currentVersion()->sole();
            $modules = app(PromptModuleResolver::class)->resolve($presetVersion, [
                ...$selectionContext,
                'market_code' => $selectionContext['language_code'] === 'en' ? 'US' : 'CN',
            ]);
            $compiled = app(PromptCompiler::class)->compile($modules, new PromptContext(
                strategy: ['primary_intent' => 'informational'],
                blueprint: ['working_title' => 'Seeded legacy scenario', 'target_length' => 1200, 'sections' => []],
                keywords: ['GEO'],
                evidence: [['content' => 'Reviewed evidence.']],
                products: [],
                language: $selectionContext['language_code'],
                market: $selectionContext['language_code'] === 'en' ? 'US' : 'CN',
                variables: ['topic' => 'Seeded legacy scenario'],
                modelTokenBudget: 80_000,
            ));

            $this->assertStringContainsString('MODULE legacy_content', $compiled->text);
            $this->assertStringNotContainsString('{{', $compiled->text);
            $this->assertStringNotContainsString('}}', $compiled->text);
        }
    }

    public function test_database_seeder_installs_composition_data_without_overwriting_installed_rows(): void
    {
        $custom = PromptModule::query()->create([
            'module_key' => 'installed_custom_module',
            'display_name' => 'Installed custom module',
            'module_type' => PromptModule::TYPE_CONTENT_TYPE,
            'description' => 'User-owned installed data.',
            'is_system' => false,
            'is_enabled' => false,
            'default_priority' => 777,
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $custom->refresh();
        $this->assertSame('Installed custom module', $custom->display_name);
        $this->assertSame('User-owned installed data.', $custom->description);
        $this->assertFalse($custom->is_system);
        $this->assertFalse($custom->is_enabled);
        $this->assertSame(777, $custom->default_priority);
        $this->assertSame(0, $custom->versions()->count());
        $this->assertSame(4, PromptPreset::query()->where('is_system', true)->count());
    }

    /** @return array{modules: int, module_versions: int, presets: int, preset_versions: int, bindings: int} */
    private function compositionCounts(): array
    {
        return [
            'modules' => PromptModule::query()->count(),
            'module_versions' => PromptModuleVersion::query()->count(),
            'presets' => PromptPreset::query()->count(),
            'preset_versions' => PromptPresetVersion::query()->count(),
            'bindings' => PromptPresetModuleVersion::query()->count(),
        ];
    }
}
