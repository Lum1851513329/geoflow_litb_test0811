<?php

namespace Tests\Unit;

use App\Domain\PromptComposition\PromptModuleResolver;
use App\Domain\PromptComposition\PromptModuleService;
use App\Domain\PromptComposition\PromptPresetService;
use App\Domain\PromptComposition\ResolvedPromptModule;
use App\Exceptions\ImmutablePromptVersionException;
use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromptModuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private PromptPresetVersion $presetVersion;

    /** @var array<string, PromptModuleVersion> */
    private array $versions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::query()->create([
            'username' => 'prompt-preset-admin',
            'password' => 'password',
            'email' => 'prompt-preset-admin@example.test',
            'display_name' => 'Prompt Preset Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->versions = [
            'foundation_truth' => $this->moduleVersion('foundation_truth', PromptModule::TYPE_FOUNDATION, 300),
            'geo_citability' => $this->moduleVersion('geo_citability', PromptModule::TYPE_GEO, 200),
            'buying_guide' => $this->moduleVersion('buying_guide', PromptModule::TYPE_CONTENT_TYPE, 100),
        ];

        $preset = PromptPreset::query()->create([
            'preset_key' => 'buying-guide',
            'display_name' => 'Buying guide',
            'owned_by_admin_id' => $this->admin->id,
        ]);
        $this->presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => 'buying_guide'],
            'generation_policy' => ['mode' => 'auto'],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $this->admin->id,
        ]);

        $this->bind($this->presetVersion, $this->versions['foundation_truth'], 1, true);
        $this->bind($this->presetVersion, $this->versions['geo_citability'], 2, true);
        $this->bind($this->presetVersion, $this->versions['buying_guide'], 3, false);
        $this->presetVersion->forceFill(['lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION])->save();
    }

    public function test_auto_mode_resolves_exact_production_versions_in_stable_order(): void
    {
        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context());

        $this->assertSame(
            ['foundation_truth', 'geo_citability', 'buying_guide'],
            array_map(fn (ResolvedPromptModule $item): string => $item->moduleKey, $resolved),
        );
        $this->assertSame($this->versions['foundation_truth']->prompt_module_id, $resolved[0]->moduleId);
        $this->assertSame($this->versions['foundation_truth']->id, $resolved[0]->versionId);
        $this->assertSame(PromptModule::TYPE_FOUNDATION, $resolved[0]->moduleType);
        $this->assertSame(300, $resolved[0]->priority);
        $this->assertTrue($resolved[0]->required);
        $this->assertSame('foundation_truth version 1', $resolved[0]->content);
        $this->assertSame(['required' => ['audience']], $resolved[0]->variableContract);
    }

    public function test_required_binding_cannot_be_overridden_away(): void
    {
        $replacement = $this->secondVersion($this->versions['foundation_truth'], PromptModuleVersion::STATUS_TEST);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context(), [
            'foundation_truth' => $replacement->id,
            'buying_guide' => null,
        ]);

        $this->assertSame($this->versions['foundation_truth']->id, $resolved[0]->versionId);
        $this->assertSame(['foundation_truth', 'geo_citability'], array_map(
            fn (ResolvedPromptModule $item): string => $item->moduleKey,
            $resolved,
        ));
    }

    public function test_archived_versions_never_auto_resolve(): void
    {
        $this->versions['buying_guide']->update([
            'lifecycle_status' => PromptModuleVersion::STATUS_ARCHIVED,
        ]);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context());

        $this->assertSame(['foundation_truth', 'geo_citability'], array_map(
            fn (ResolvedPromptModule $item): string => $item->moduleKey,
            $resolved,
        ));
    }

    public function test_expert_override_requires_an_eligible_version_from_the_bound_module(): void
    {
        $foreignVersion = $this->moduleVersion('foreign', PromptModule::TYPE_SEO, 50);

        $this->expectException(\InvalidArgumentException::class);

        app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context(), [
            'buying_guide' => $foreignVersion->id,
        ]);
    }

    public function test_expert_override_uses_an_eligible_exact_version_from_the_same_module(): void
    {
        $replacement = $this->secondVersion($this->versions['buying_guide'], PromptModuleVersion::STATUS_TEST);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context(), [
            'buying_guide' => $replacement->id,
        ]);

        $this->assertSame($replacement->id, $resolved[2]->versionId);
        $this->assertSame('buying_guide version 2', $resolved[2]->content);
    }

    public function test_optional_binding_conditions_skip_modules_without_matching_runtime_context(): void
    {
        $this->presetVersion->forceFill(['lifecycle_status' => PromptPresetVersion::STATUS_DRAFT])->save();
        $conditional = $this->moduleVersion('links_cta', PromptModule::TYPE_SEO, 50);
        $this->bind($this->presetVersion, $conditional, 4, false, [
            'conditions' => [
                ['field' => 'store_links', 'operator' => 'not_empty'],
            ],
        ]);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, [
            'content_type' => 'buying_guide',
            'language_code' => 'en',
            'market_code' => 'US',
            'store_links' => [],
        ]);

        $this->assertNotContains('links_cta', array_map(
            fn (ResolvedPromptModule $item): string => $item->moduleKey,
            $resolved,
        ));
    }

    public function test_optional_binding_conditions_include_modules_when_runtime_context_matches(): void
    {
        $this->presetVersion->forceFill(['lifecycle_status' => PromptPresetVersion::STATUS_DRAFT])->save();
        $conditional = $this->moduleVersion('links_cta', PromptModule::TYPE_SEO, 50);
        $this->bind($this->presetVersion, $conditional, 4, false, [
            'conditions' => [
                ['field' => 'store_links', 'operator' => 'not_empty'],
            ],
        ]);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, [
            'content_type' => 'buying_guide',
            'language_code' => 'en',
            'market_code' => 'US',
            'store_links' => ['https://example.test/product'],
        ]);

        $this->assertContains('links_cta', array_map(
            fn (ResolvedPromptModule $item): string => $item->moduleKey,
            $resolved,
        ));
    }

    public function test_duplicate_alias_modules_resolve_to_one_canonical_module(): void
    {
        $this->presetVersion->forceFill(['lifecycle_status' => PromptPresetVersion::STATUS_DRAFT])->save();
        $alias = $this->moduleVersion('seo_structure', PromptModule::TYPE_SEO, 100);
        $canonical = $this->moduleVersion('litb.seo.structure', PromptModule::TYPE_SEO, 200);
        $this->bind($this->presetVersion, $alias, 4, false);
        $this->bind($this->presetVersion, $canonical, 5, false);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context());
        $keys = array_map(fn (ResolvedPromptModule $item): string => $item->moduleKey, $resolved);

        $this->assertSame(1, count(array_filter($keys, fn (string $key): bool => in_array($key, ['seo_structure', 'litb.seo.structure'], true))));
        $this->assertContains('litb.seo.structure', $keys);
    }

    public function test_priority_ties_use_binding_position_then_binding_id(): void
    {
        $this->versions['foundation_truth']->module->update(['default_priority' => 100]);
        $this->versions['geo_citability']->module->update(['default_priority' => 100]);
        $this->versions['buying_guide']->module->update(['default_priority' => 100]);

        $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, $this->context());

        $this->assertSame(['foundation_truth', 'geo_citability', 'buying_guide'], array_map(
            fn (ResolvedPromptModule $item): string => $item->moduleKey,
            $resolved,
        ));
    }

    public function test_draft_preset_creation_can_bind_an_exact_draft_module_version(): void
    {
        $draftVersion = $this->secondVersion($this->versions['buying_guide'], PromptModuleVersion::STATUS_DRAFT);

        $preset = app(PromptPresetService::class)->createPreset(
            [
                'preset_key' => 'draft-buying-guide',
                'display_name' => 'Draft buying guide',
            ],
            [
                'matching_rules' => ['content_type' => 'buying_guide'],
                'generation_policy' => ['mode' => 'recommended'],
            ],
            [[
                'prompt_module_version_id' => $draftVersion->id,
                'sort_order' => 1,
                'is_required' => false,
            ]],
            $this->admin,
        );

        $created = $preset->versions()->sole();

        $this->assertSame(PromptPresetVersion::STATUS_DRAFT, $created->lifecycle_status);
        $this->assertSame($draftVersion->id, $created->moduleVersions()->sole()->prompt_module_version_id);
    }

    public function test_publishing_rejects_a_version_archived_after_draft_binding(): void
    {
        $draftPreset = app(PromptPresetService::class)->createPreset(
            [
                'preset_key' => 'archived-binding-buying-guide',
                'display_name' => 'Archived binding buying guide',
            ],
            [
                'matching_rules' => ['content_type' => 'buying_guide'],
                'generation_policy' => ['mode' => 'auto'],
            ],
            [[
                'prompt_module_version_id' => $this->versions['buying_guide']->id,
                'sort_order' => 1,
                'is_required' => true,
            ]],
            $this->admin,
        );
        $this->versions['buying_guide']->update([
            'lifecycle_status' => PromptModuleVersion::STATUS_ARCHIVED,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(PromptPresetService::class)->publish(
            $draftPreset->versions()->sole(),
            $this->admin,
            'Must not activate archived modules',
        );
    }

    public function test_publishing_locks_bound_module_parents_before_versions_and_blocks_later_archiving(): void
    {
        $draftPreset = app(PromptPresetService::class)->createPreset(
            [
                'preset_key' => 'publishable-buying-guide',
                'display_name' => 'Publishable buying guide',
            ],
            [
                'matching_rules' => ['content_type' => 'buying_guide'],
                'generation_policy' => ['mode' => 'auto'],
            ],
            [[
                'prompt_module_version_id' => $this->versions['buying_guide']->id,
                'sort_order' => 1,
                'is_required' => true,
            ]],
            $this->admin,
        );
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $published = app(PromptPresetService::class)->publish(
            $draftPreset->versions()->sole(),
            $this->admin,
            'Approved for use',
        );

        $this->assertSame(PromptPresetVersion::STATUS_PRODUCTION, $published->lifecycle_status);
        $this->assertModuleQueryPrecedesVersionQuery($queries);
        $this->expectException(ImmutablePromptVersionException::class);

        app(PromptModuleService::class)->archive(
            $this->versions['buying_guide'],
            $this->admin,
            'Referenced by the production preset',
        );
    }

    public function test_preset_version_can_be_explicitly_deprecated_then_archived(): void
    {
        $preset = $this->presetVersion->preset;
        $preset->forceFill(['current_version_id' => $this->presetVersion->id])->save();
        $service = app(PromptPresetService::class);

        $deprecated = $service->deprecate($this->presetVersion, $this->admin, 'Retire active preset');

        $this->assertSame(PromptPresetVersion::STATUS_DEPRECATED, $deprecated->lifecycle_status);
        $this->assertNull($preset->fresh()->current_version_id);

        $archived = $service->archive($deprecated, $this->admin, 'Retention archive');
        $this->assertSame(PromptPresetVersion::STATUS_ARCHIVED, $archived->lifecycle_status);
        $this->assertSame('Retention archive', $archived->change_note);
    }

    /** @return array<string, string> */
    private function context(): array
    {
        return [
            'content_type' => 'buying_guide',
            'language_code' => 'en',
            'market_code' => 'US',
        ];
    }

    private function moduleVersion(string $key, string $type, int $priority): PromptModuleVersion
    {
        $module = PromptModule::query()->create([
            'module_key' => $key,
            'display_name' => ucwords(str_replace('_', ' ', $key)),
            'module_type' => $type,
            'applicability' => [
                'content_type' => ['buying_guide'],
                'language_code' => ['en'],
                'market_code' => ['US'],
            ],
            'default_priority' => $priority,
            'is_enabled' => true,
            'owned_by_admin_id' => $this->admin->id,
        ]);

        return PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => "{$key} version 1",
            'variable_contract' => ['required' => ['audience']],
            'content_sha256' => hash('sha256', "{$key} version 1"),
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->admin->id,
        ]);
    }

    private function secondVersion(PromptModuleVersion $version, string $status): PromptModuleVersion
    {
        $content = $version->module->module_key.' version 2';

        return PromptModuleVersion::forceCreate([
            'prompt_module_id' => $version->prompt_module_id,
            'version' => 2,
            'content' => $content,
            'variable_contract' => ['required' => ['audience']],
            'content_sha256' => hash('sha256', $content),
            'lifecycle_status' => $status,
            'created_by_admin_id' => $this->admin->id,
        ]);
    }

    private function bind(
        PromptPresetVersion $presetVersion,
        PromptModuleVersion $moduleVersion,
        int $sortOrder,
        bool $required,
        ?array $configuration = null,
    ): void {
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $presetVersion->id,
            'prompt_module_version_id' => $moduleVersion->id,
            'sort_order' => $sortOrder,
            'is_required' => $required,
            'configuration' => $configuration,
        ]);
    }

    /** @param list<string> $queries */
    private function assertModuleQueryPrecedesVersionQuery(array $queries): void
    {
        $moduleQueryIndex = $this->queryIndexForTable($queries, 'prompt_modules');
        $versionQueryIndex = $this->queryIndexForTable($queries, 'prompt_module_versions');

        $this->assertLessThan($versionQueryIndex, $moduleQueryIndex);
    }

    /** @param list<string> $queries */
    private function queryIndexForTable(array $queries, string $table): int
    {
        foreach ($queries as $index => $sql) {
            if (str_starts_with($sql, 'select * from "'.$table.'"')) {
                return $index;
            }
        }

        $this->fail("Expected a query for {$table}.");
    }
}
