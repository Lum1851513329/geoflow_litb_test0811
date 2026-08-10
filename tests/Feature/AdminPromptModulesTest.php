<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PromptPresetController;
use App\Http\Requests\Admin\StorePromptPresetRequest;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunModule;
use App\Models\ContentStrategyVersion;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPromptModulesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    private Admin $contentOperator;

    private PromptModule $module;

    private PromptModuleVersion $productionVersion;

    private PromptModuleVersion $draftVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config()->set('geoflow.modular_prompts_enabled', true);
        $this->createActivityLogTable();

        $this->superAdmin = $this->admin('prompt-root', 'super_admin');
        $this->contentOperator = $this->admin('prompt-operator', 'content_operator');
        $this->module = PromptModule::query()->create([
            'module_key' => 'system-foundation',
            'display_name' => 'System foundation',
            'module_type' => PromptModule::TYPE_FOUNDATION,
            'description' => 'Core system instructions.',
            'is_system' => true,
            'default_priority' => 100,
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $this->productionVersion = $this->version(1, 'Keep claims grounded.', PromptModuleVersion::STATUS_PRODUCTION);
        $this->draftVersion = $this->version(2, "Keep claims grounded.\nInclude evidence identifiers.\nPROMPT-SECRET-42", PromptModuleVersion::STATUS_DRAFT);
        $this->module->forceFill(['current_version_id' => $this->productionVersion->id])->save();
    }

    public function test_disabled_prompt_administration_is_hidden_before_authentication(): void
    {
        config()->set('geoflow.modular_prompts_enabled', false);

        $this->get(route('admin.prompt-modules.index'))->assertNotFound();
    }

    public function test_enabled_prompt_administration_requires_admin_authentication(): void
    {
        $this->get(route('admin.prompt-modules.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.prompt-presets.index'))->assertRedirect(route('admin.login'));
    }

    public function test_super_admin_can_publish_a_new_module_version_with_reason(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-modules.versions.publish', [$this->module, $this->draftVersion]),
            ['reason' => 'Depth test passed'],
        );

        $response->assertRedirect(route('admin.prompt-modules.show', $this->module));
        $this->assertSame(PromptModuleVersion::STATUS_PRODUCTION, $this->draftVersion->fresh()->lifecycle_status);
        $this->assertSame(PromptModuleVersion::STATUS_DEPRECATED, $this->productionVersion->fresh()->lifecycle_status);
        $this->assertSame('Depth test passed', $this->draftVersion->fresh()->change_note);
    }

    public function test_content_operator_cannot_publish_a_system_module(): void
    {
        $this->actingAs($this->contentOperator, 'admin')->post(
            route('admin.prompt-modules.versions.publish', [$this->module, $this->draftVersion]),
            ['reason' => 'Operator approval'],
        )->assertForbidden();

        $this->assertSame(PromptModuleVersion::STATUS_DRAFT, $this->draftVersion->fresh()->lifecycle_status);
    }

    public function test_prompt_text_is_not_included_in_activity_log_payloads(): void
    {
        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-modules.versions.publish', [$this->module, $this->draftVersion]),
            ['reason' => $this->draftVersion->content],
        )->assertRedirect();

        $details = AdminActivityLog::query()->pluck('details')->implode("\n");
        $this->assertStringNotContainsString('PROMPT-SECRET-42', $details);
        $this->assertStringNotContainsString($this->draftVersion->content, $details);
        $this->assertStringContainsString(hash('sha256', $this->draftVersion->content), $details);
    }

    public function test_invalid_module_contracts_return_field_errors(): void
    {
        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-modules.versions.store', $this->module),
            [
                'content' => 'Write for {{ audience }}.',
                'variable_contract' => '{invalid-json',
                'output_contract' => 'not-json',
                'parameter_defaults' => 'also-not-json',
            ],
        )->assertSessionHasErrors(['variable_contract', 'output_contract', 'parameter_defaults']);

        $this->assertSame(2, $this->module->versions()->count());
    }

    public function test_used_module_versions_cannot_be_deleted(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'active-system-preset',
            'display_name' => 'Active system preset',
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $presetVersion->id,
            'prompt_module_version_id' => $this->productionVersion->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);

        $this->actingAs($this->superAdmin, 'admin')->delete(
            route('admin.prompt-modules.versions.destroy', [$this->module, $this->productionVersion]),
        )->assertRedirect(route('admin.prompt-modules.show', $this->module))
            ->assertSessionHasErrors('version');

        $this->assertDatabaseHas('prompt_module_versions', ['id' => $this->productionVersion->id]);
    }

    public function test_preset_ordering_persists_exact_module_version_ids(): void
    {
        $secondModule = PromptModule::query()->create([
            'module_key' => 'geo-citability',
            'display_name' => 'GEO citability',
            'module_type' => PromptModule::TYPE_GEO,
            'default_priority' => 50,
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $secondVersion = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $secondModule->id,
            'version' => 1,
            'content' => 'Make claims easy to cite.',
            'variable_contract' => [],
            'content_sha256' => hash('sha256', 'Make claims easy to cite.'),
            'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin, 'admin')->post(route('admin.prompt-presets.store'), [
            'preset_key' => 'ordered-buying-guide',
            'display_name' => 'Ordered buying guide',
            'matching_rules' => json_encode(['content_type' => 'buying_guide']),
            'generation_policy' => json_encode(['mode' => 'auto']),
            'conflict_policy' => json_encode(['strategy' => 'priority']),
            'module_versions' => [
                ['prompt_module_version_id' => $secondVersion->id, 'is_required' => '1'],
                ['prompt_module_version_id' => $this->draftVersion->id, 'is_required' => '0'],
            ],
        ])->assertRedirect();

        $presetVersion = PromptPreset::query()->where('preset_key', 'ordered-buying-guide')->sole()->versions()->sole();
        $this->assertSame(
            [$secondVersion->id, $this->draftVersion->id],
            $presetVersion->moduleVersions()->pluck('prompt_module_version_id')->all(),
        );
        $this->assertSame([1, 2], $presetVersion->moduleVersions()->pluck('sort_order')->all());
    }

    public function test_module_detail_shows_a_line_diff_and_generation_run_article_usage(): void
    {
        $article = $this->article();
        $run = $this->generationRun($article);
        ContentGenerationRunModule::query()->create([
            'content_generation_run_id' => $run->id,
            'prompt_module_version_id' => $this->draftVersion->id,
            'sort_order' => 1,
            'is_required' => true,
            'module_snapshot' => ['module_key' => $this->module->module_key, 'version' => 2],
        ]);

        $response = $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-modules.show', [
            $this->module,
            'version_id' => $this->draftVersion->id,
            'compare_to' => $this->productionVersion->id,
        ]));

        $response->assertOk()
            ->assertSee('Include evidence identifiers.')
            ->assertSee('Generated usage article')
            ->assertViewHas('diffLines', fn (array $lines): bool => collect($lines)->contains(
                fn (array $line): bool => $line['kind'] === 'added' && $line['line'] === 'Include evidence identifiers.',
            ));
    }

    public function test_rejected_preset_version_does_not_partially_update_preset_identity(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'atomic-preset',
            'display_name' => 'Original preset name',
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        $this->draftVersion->update(['lifecycle_status' => PromptModuleVersion::STATUS_ARCHIVED]);

        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-presets.versions.store', $preset),
            [
                'preset_key' => 'atomic-preset',
                'display_name' => 'Partially saved name',
                'matching_rules' => json_encode(['content_type' => 'guide']),
                'generation_policy' => json_encode(['mode' => 'auto']),
                'conflict_policy' => '{}',
                'module_versions' => [[
                    'prompt_module_version_id' => $this->draftVersion->id,
                    'is_required' => '1',
                ]],
            ],
        )->assertSessionHasErrors('module_versions');

        $this->assertSame('Original preset name', $preset->fresh()->display_name);
        $this->assertSame(1, $preset->versions()->count());
    }

    public function test_empty_json_object_is_a_valid_module_variable_contract(): void
    {
        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-modules.versions.store', $this->module),
            [
                'content' => 'No variables are required.',
                'variable_contract' => '{}',
                'output_contract' => '{}',
                'parameter_defaults' => '{}',
            ],
        )->assertSessionDoesntHaveErrors();

        $this->assertSame(3, $this->module->versions()->count());
    }

    public function test_empty_json_objects_are_valid_preset_policies(): void
    {
        $this->actingAs($this->superAdmin, 'admin')->post(route('admin.prompt-presets.store'), [
            'preset_key' => 'empty-policy-preset',
            'display_name' => 'Empty policy preset',
            'matching_rules' => '{}',
            'generation_policy' => '{}',
            'conflict_policy' => '{}',
            'module_versions' => [[
                'prompt_module_version_id' => $this->draftVersion->id,
                'is_required' => '0',
            ]],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('prompt_presets', ['preset_key' => 'empty-policy-preset']);
    }

    public function test_module_and_preset_indexes_render_admin_creation_forms(): void
    {
        $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-modules.index'))
            ->assertOk()
            ->assertSee($this->module->display_name)
            ->assertSee('name="_token"', false)
            ->assertViewHas('modules', fn ($modules): bool => $modules->perPage() === 20);

        $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-presets.index'))
            ->assertOk()
            ->assertSee('name="module_versions[0][prompt_module_version_id]"', false)
            ->assertSee('name="_token"', false)
            ->assertViewHas('presets', fn ($presets): bool => $presets->perPage() === 20);
    }

    public function test_preset_edit_renders_ordered_exact_bindings(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'rendered-preset',
            'display_name' => 'Rendered preset',
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $version = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $version->id,
            'prompt_module_version_id' => $this->draftVersion->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);

        $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-presets.edit', $preset))
            ->assertOk()
            ->assertSee($this->module->display_name)
            ->assertSee('#'.$this->draftVersion->id)
            ->assertSee('module_versions[0][prompt_module_version_id]', false);
    }

    public function test_operator_is_reauthorized_after_a_stale_custom_preset_becomes_system(): void
    {
        [$preset] = $this->presetWithDraftVersion('stale-operator');
        $this->app->afterResolving(StorePromptPresetRequest::class, function () use ($preset): void {
            PromptPreset::query()->whereKey($preset->id)->update(['is_system' => true]);
        });

        $this->actingAs($this->contentOperator, 'admin')->post(
            route('admin.prompt-presets.versions.store', $preset),
            $this->presetVersionPayload($this->draftVersion),
        )->assertForbidden();

        $this->assertTrue($preset->fresh()->is_system);
        $this->assertSame(1, $preset->versions()->count());
    }

    public function test_super_admin_preserves_concurrent_system_promotion_when_creating_preset_version(): void
    {
        [$preset] = $this->presetWithDraftVersion('stale-super');
        $this->app->afterResolving(StorePromptPresetRequest::class, function () use ($preset): void {
            PromptPreset::query()->whereKey($preset->id)->update(['is_system' => true]);
        });

        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-presets.versions.store', $preset),
            $this->presetVersionPayload($this->draftVersion),
        )->assertRedirect();

        $this->assertTrue($preset->fresh()->is_system);
        $this->assertSame(2, $preset->versions()->count());
    }

    public function test_operator_cannot_publish_a_stale_preset_promoted_to_system(): void
    {
        [$preset, $version] = $this->presetWithDraftVersion('stale-publish');
        $stalePreset = $preset->fresh();
        PromptPreset::query()->whereKey($preset->id)->update(['is_system' => true]);
        $request = Request::create('/prompt-presets/publish', 'POST', ['reason' => 'Reviewed']);
        $request->setUserResolver(fn (?string $guard = null): Admin => $this->contentOperator);

        $this->expectException(AuthorizationException::class);

        app(PromptPresetController::class)->publish($request, $stalePreset, $version);
    }

    public function test_operator_cannot_write_protected_system_fields(): void
    {
        [$preset] = $this->presetWithDraftVersion('protected-fields');

        $this->actingAs($this->contentOperator, 'admin')->post(
            route('admin.prompt-presets.versions.store', $preset),
            array_merge($this->presetVersionPayload($this->draftVersion), ['is_system' => '1']),
        )->assertSessionHasErrors('is_system');

        $this->assertFalse($preset->fresh()->is_system);

        $customModule = PromptModule::query()->create([
            'module_key' => 'protected-module-fields',
            'display_name' => 'Protected module fields',
            'module_type' => PromptModule::TYPE_GEO,
            'owned_by_admin_id' => $this->contentOperator->id,
        ]);
        $this->actingAs($this->contentOperator, 'admin')->patch(
            route('admin.prompt-modules.update', $customModule),
            [
                'display_name' => $customModule->display_name,
                'module_type' => $customModule->module_type,
                'description' => $customModule->description,
                'applicability' => '{}',
                'default_priority' => $customModule->default_priority,
                'is_mandatory' => '0',
                'is_enabled' => '1',
                'is_system' => '1',
            ],
        )->assertSessionHasErrors('is_system');

        $this->assertFalse($customModule->fresh()->is_system);
    }

    public function test_module_identity_can_be_updated_without_mutating_version_snapshots(): void
    {
        $originalContent = $this->draftVersion->content;

        $this->actingAs($this->superAdmin, 'admin')->patch(
            route('admin.prompt-modules.update', $this->module),
            [
                'display_name' => 'Updated foundation',
                'module_type' => PromptModule::TYPE_GEO,
                'description' => 'Updated identity description',
                'applicability' => json_encode(['market_code' => ['US']]),
                'default_priority' => 240,
                'is_mandatory' => '1',
                'is_enabled' => '0',
            ],
        )->assertRedirect(route('admin.prompt-modules.show', $this->module));

        $fresh = $this->module->fresh();
        $this->assertSame('Updated foundation', $fresh->display_name);
        $this->assertSame(PromptModule::TYPE_GEO, $fresh->module_type);
        $this->assertSame('Updated identity description', $fresh->description);
        $this->assertSame(['market_code' => ['US']], $fresh->applicability);
        $this->assertSame(240, $fresh->default_priority);
        $this->assertTrue($fresh->is_mandatory);
        $this->assertFalse($fresh->is_enabled);
        $this->assertSame($originalContent, $this->draftVersion->fresh()->content);
    }

    public function test_module_and_preset_versions_have_explicit_deprecate_and_archive_actions(): void
    {
        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-modules.versions.deprecate', [$this->module, $this->productionVersion]),
            ['reason' => 'Superseded explicitly'],
        )->assertRedirect();
        $this->assertSame(PromptModuleVersion::STATUS_DEPRECATED, $this->productionVersion->fresh()->lifecycle_status);

        [$preset, $presetVersion] = $this->presetWithDraftVersion('lifecycle-actions');
        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-presets.versions.deprecate', [$preset, $presetVersion]),
            ['reason' => 'No longer selected'],
        )->assertRedirect();
        $this->assertSame(PromptPresetVersion::STATUS_DEPRECATED, $presetVersion->fresh()->lifecycle_status);

        $this->actingAs($this->superAdmin, 'admin')->post(
            route('admin.prompt-presets.versions.archive', [$preset, $presetVersion]),
            ['reason' => 'Retention archive'],
        )->assertRedirect();
        $this->assertSame(PromptPresetVersion::STATUS_ARCHIVED, $presetVersion->fresh()->lifecycle_status);
    }

    public function test_module_version_history_is_paginated_without_hydrating_unselected_content(): void
    {
        foreach (range(3, 28) as $number) {
            $this->version($number, "Snapshot {$number} ".str_repeat('x', 1000), PromptModuleVersion::STATUS_DRAFT);
        }

        $response = $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-modules.show', $this->module));

        $response->assertOk()->assertViewHas('versions', function (mixed $versions): bool {
            if (! $versions instanceof LengthAwarePaginator || $versions->perPage() > 20) {
                return false;
            }

            return collect($versions->items())->every(
                fn (PromptModuleVersion $version): bool => ! array_key_exists('content', $version->getAttributes())
                    && ! array_key_exists('variable_contract', $version->getAttributes()),
            );
        });
    }

    public function test_module_version_option_search_is_capped_and_never_selects_prompt_bodies(): void
    {
        foreach (range(1, 35) as $number) {
            $module = PromptModule::query()->create([
                'module_key' => "searchable-{$number}",
                'display_name' => "Searchable {$number}",
                'module_type' => PromptModule::TYPE_GEO,
            ]);
            PromptModuleVersion::forceCreate([
                'prompt_module_id' => $module->id,
                'version' => 1,
                'content' => "OPTION-SECRET-{$number}",
                'variable_contract' => [],
                'content_sha256' => hash('sha256', "OPTION-SECRET-{$number}"),
                'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
            ]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->superAdmin, 'admin')->getJson(
            route('admin.prompt-module-version-options', ['q' => 'Searchable']),
        );

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('per_page', 25);
        $this->assertStringNotContainsString('content', strtolower($queries));
        $this->assertStringNotContainsString('OPTION-SECRET', $response->getContent());
    }

    public function test_diff_reports_truncation_when_changes_fall_outside_the_bounded_window(): void
    {
        $oldLines = array_map(fn (int $line): string => "line {$line}", range(1, 900));
        $newLines = $oldLines;
        $newLines[599] = 'changed line 600';
        $old = $this->version(3, implode("\n", $oldLines), PromptModuleVersion::STATUS_DRAFT);
        $new = $this->version(4, implode("\n", $newLines), PromptModuleVersion::STATUS_DRAFT);

        $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-modules.show', [
            $this->module,
            'version_id' => $new->id,
            'compare_to' => $old->id,
        ]))->assertOk()
            ->assertSee(__('admin.prompt_modules.diff.truncated', ['old' => 900, 'new' => 900, 'limit' => 500]))
            ->assertViewHas('diffMetadata', fn (array $metadata): bool => $metadata['truncated'] === true
                && $metadata['old_line_count'] === 900
                && $metadata['new_line_count'] === 900);
    }

    public function test_invalid_or_cross_parent_version_queries_are_rejected(): void
    {
        $foreignModule = PromptModule::query()->create([
            'module_key' => 'foreign-module', 'display_name' => 'Foreign', 'module_type' => PromptModule::TYPE_SEO,
        ]);
        $foreignVersion = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $foreignModule->id, 'version' => 1, 'content' => 'Foreign body',
            'variable_contract' => [], 'content_sha256' => hash('sha256', 'Foreign body'),
            'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
        ]);
        [$preset, $presetVersion] = $this->presetWithDraftVersion('query-scope');
        [$foreignPreset, $foreignPresetVersion] = $this->presetWithDraftVersion('query-scope-foreign');

        $this->actingAs($this->superAdmin, 'admin')
            ->get(route('admin.prompt-modules.show', [$this->module, 'version_id' => 999999]))->assertNotFound();
        $this->get(route('admin.prompt-modules.show', [$this->module, 'version_id' => $foreignVersion->id]))->assertNotFound();
        $this->get(route('admin.prompt-presets.edit', [$preset, 'version_id' => $foreignPresetVersion->id]))->assertNotFound();
        $this->post(route('admin.prompt-presets.versions.publish', [$preset, $foreignPresetVersion]), ['reason' => 'Cross parent'])
            ->assertNotFound();
        $this->assertSame(PromptPresetVersion::STATUS_DRAFT, $presetVersion->fresh()->lifecycle_status);
        $this->assertSame(PromptPresetVersion::STATUS_DRAFT, $foreignPreset->versions()->sole()->lifecycle_status);
    }

    public function test_prompt_content_is_escaped_in_module_detail_and_diff(): void
    {
        $xss = '<script>alert("prompt-xss")</script>';
        $version = $this->version(3, $xss, PromptModuleVersion::STATUS_DRAFT);

        $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-modules.show', [
            $this->module, 'version_id' => $version->id, 'compare_to' => $this->draftVersion->id,
        ]))->assertOk()->assertSee(e($xss), false)->assertDontSee($xss, false);
    }

    public function test_preset_editor_exposes_add_remove_and_reorder_controls(): void
    {
        [$preset] = $this->presetWithDraftVersion('binding-controls', bind: true);

        $this->actingAs($this->superAdmin, 'admin')->get(route('admin.prompt-presets.edit', $preset))
            ->assertOk()
            ->assertSee('data-add-binding', false)
            ->assertSee('data-remove-binding', false)
            ->assertSee('data-move-binding-up', false)
            ->assertSee('data-move-binding-down', false);
    }

    public function test_explicit_binding_order_indices_control_persisted_exact_version_order(): void
    {
        $module = PromptModule::query()->create([
            'module_key' => 'ordered-second', 'display_name' => 'Ordered second', 'module_type' => PromptModule::TYPE_SEO,
        ]);
        $version = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id, 'version' => 1, 'content' => 'Second exact version',
            'variable_contract' => [], 'content_sha256' => hash('sha256', 'Second exact version'),
            'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
        ]);

        $this->actingAs($this->superAdmin, 'admin')->post(route('admin.prompt-presets.store'), [
            'preset_key' => 'explicit-order', 'display_name' => 'Explicit order',
            'matching_rules' => '{}', 'generation_policy' => '{}', 'conflict_policy' => '{}',
            'module_versions' => [
                ['prompt_module_version_id' => $this->draftVersion->id, 'sort_order' => 2],
                ['prompt_module_version_id' => $version->id, 'sort_order' => 1],
            ],
        ])->assertRedirect();

        $stored = PromptPreset::query()->where('preset_key', 'explicit-order')->sole()->versions()->sole();
        $this->assertSame([$version->id, $this->draftVersion->id], $stored->moduleVersions()->pluck('prompt_module_version_id')->all());
    }

    public function test_feature_disabled_option_and_nested_routes_return_not_found(): void
    {
        config()->set('geoflow.modular_prompts_enabled', false);

        $this->get(route('admin.prompt-module-version-options'))->assertNotFound();
        $this->get(route('admin.prompt-modules.show', $this->module))->assertNotFound();
        $this->post(route('admin.prompt-modules.versions.publish', [$this->module, $this->draftVersion]), ['reason' => 'Hidden'])
            ->assertNotFound();
    }

    private function version(int $number, string $content, string $status): PromptModuleVersion
    {
        return PromptModuleVersion::forceCreate([
            'prompt_module_id' => $this->module->id,
            'version' => $number,
            'content' => $content,
            'variable_contract' => ['variables' => ['audience' => ['type' => 'string']]],
            'output_contract' => ['format' => 'markdown'],
            'parameter_defaults' => ['temperature' => 0.2],
            'content_sha256' => hash('sha256', $content),
            'lifecycle_status' => $status,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
    }

    private function admin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => str($username)->headline(),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function article(): Article
    {
        $category = Category::query()->create(['name' => 'Prompt usage', 'slug' => 'prompt-usage']);
        $author = Author::query()->create(['name' => 'Prompt Usage Author']);

        return Article::query()->create([
            'title' => 'Generated usage article',
            'slug' => 'generated-usage-article',
            'content' => 'Generated article body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
        ]);
    }

    private function generationRun(Article $article): ContentGenerationRun
    {
        $brief = ContentBrief::query()->create([
            'topic' => 'Prompt usage',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'Administrators',
            'core_question' => 'Where is this Prompt used?',
            'business_goal' => 'Audit Prompt usage',
            'content_type' => 'guide',
            'status' => ContentBrief::STATUS_STRATEGY_READY,
        ]);
        $strategy = ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => [],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => [],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'usage-preset',
            'display_name' => 'Usage preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Usage model',
            'version' => 'test',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'usage-model',
        ]);

        return ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'article_id' => $article->id,
            'compiled_prompt' => 'Compiled Prompt.',
            'status' => ContentGenerationRun::STATUS_COMPLETED,
            'idempotency_key' => 'prompt-usage-run',
        ]);
    }

    /** @return array{PromptPreset, PromptPresetVersion} */
    private function presetWithDraftVersion(string $key, bool $bind = false): array
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => $key,
            'display_name' => str($key)->headline(),
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $version = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        if ($bind) {
            PromptPresetModuleVersion::query()->create([
                'prompt_preset_version_id' => $version->id,
                'prompt_module_version_id' => $this->draftVersion->id,
                'sort_order' => 1,
                'is_required' => true,
            ]);
        }

        return [$preset, $version];
    }

    /** @return array<string, mixed> */
    private function presetVersionPayload(PromptModuleVersion $version): array
    {
        return [
            'preset_key' => 'stale-payload',
            'display_name' => 'Stale payload',
            'matching_rules' => '{}',
            'generation_policy' => '{}',
            'conflict_policy' => '{}',
            'module_versions' => [[
                'prompt_module_version_id' => $version->id,
                'sort_order' => 1,
                'is_required' => '1',
            ]],
        ];
    }

    private function createActivityLogTable(): void
    {
        if (Schema::hasTable('admin_activity_logs')) {
            return;
        }

        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_username')->default('');
            $table->string('admin_role')->default('');
            $table->string('action');
            $table->string('request_method')->default('GET');
            $table->string('page')->default('');
            $table->string('target_type')->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->default('');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
