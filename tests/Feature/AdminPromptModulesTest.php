<?php

namespace Tests\Feature;

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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
