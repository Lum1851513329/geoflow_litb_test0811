<?php

namespace Tests\Unit;

use App\Domain\PromptComposition\PromptModuleService;
use App\Exceptions\ImmutablePromptVersionException;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromptModuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->admin = Admin::query()->create([
            'username' => 'prompt-module-admin',
            'password' => 'password',
            'email' => 'prompt-module-admin@example.test',
            'display_name' => 'Prompt Module Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_publishing_a_version_deprecates_previous_production_without_mutating_content(): void
    {
        $module = $this->moduleWithVersions(['production', 'draft']);
        $draft = $module->versions()->where('lifecycle_status', 'draft')->firstOrFail();
        $production = $module->versions()->where('lifecycle_status', 'production')->firstOrFail();
        $productionContent = $production->content;

        $published = app(PromptModuleService::class)->publish($draft, $this->admin, 'Improve depth');

        $this->assertSame('production', $published->fresh()->lifecycle_status);
        $this->assertSame('deprecated', $module->versions()->where('version', 1)->value('lifecycle_status'));
        $this->assertSame('Improve depth', $published->change_note);
        $this->assertSame($productionContent, $production->fresh()->content);
    }

    public function test_module_key_remains_stable_when_a_new_version_is_created(): void
    {
        $module = $this->moduleWithVersions(['draft']);

        app(PromptModuleService::class)->createVersion($module, $this->versionData('Second snapshot.'), $this->admin);

        $this->assertSame('module-lifecycle', $module->fresh()->module_key);
    }

    public function test_identity_update_uses_the_service_without_mutating_stable_key_or_snapshot(): void
    {
        $module = $this->moduleWithVersions(['draft']);
        $version = $module->versions()->sole();

        $updated = app(PromptModuleService::class)->updateIdentity($module, [
            'display_name' => 'Updated module identity',
            'description' => 'Updated description',
            'default_priority' => 80,
            'is_enabled' => false,
        ], $this->admin);

        $this->assertSame('Updated module identity', $updated->display_name);
        $this->assertSame('module-lifecycle', $updated->module_key);
        $this->assertSame($version->content, $version->fresh()->content);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'prompt_module:updated',
            'target_type' => 'prompt_module',
            'target_id' => $module->id,
        ]);
    }

    public function test_creating_versions_allocates_the_next_version_number(): void
    {
        $module = app(PromptModuleService::class)->createModule(
            $this->identity('transactional-module'),
            $this->versionData('First snapshot.'),
            $this->admin,
        );

        $second = app(PromptModuleService::class)->createVersion(
            $module,
            $this->versionData('Second snapshot.'),
            $this->admin,
        );

        $this->assertSame([1, 2], $module->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame(2, $second->version);
    }

    public function test_creating_a_version_cannot_bypass_the_publish_lifecycle_with_production_status(): void
    {
        $module = $this->moduleWithVersions(['production']);

        $created = app(PromptModuleService::class)->createVersion($module, [
            ...$this->versionData('Second snapshot.'),
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ], $this->admin);

        $this->assertSame(PromptModuleVersion::STATUS_DRAFT, $created->lifecycle_status);
        $this->assertSame(PromptModuleVersion::STATUS_PRODUCTION, $module->versions()->where('version', 1)->value('lifecycle_status'));
    }

    public function test_database_rejects_content_mutation_for_a_version_used_by_a_preset(): void
    {
        $version = $this->moduleWithActivePresetDependency();

        $this->expectException(QueryException::class);

        $version->forceFill(['content' => 'Replacement prompt text.'])->save();
    }

    public function test_archiving_a_version_referenced_by_an_active_preset_is_rejected(): void
    {
        $version = $this->moduleWithActivePresetDependency();

        $this->expectException(ImmutablePromptVersionException::class);

        app(PromptModuleService::class)->archive($version, $this->admin, 'No longer needed');
    }

    public function test_archived_versions_cannot_be_published_again(): void
    {
        $version = $this->moduleWithVersions(['archived'])->versions()->sole();

        $this->expectException(ImmutablePromptVersionException::class);

        app(PromptModuleService::class)->publish($version, $this->admin, 'Restore archived version');
    }

    public function test_explicit_deprecation_clears_the_current_version_without_mutating_content(): void
    {
        $module = $this->moduleWithVersions(['production']);
        $version = $module->versions()->sole();
        $module->forceFill(['current_version_id' => $version->id])->save();
        $content = $version->content;

        $deprecated = app(PromptModuleService::class)->deprecate($version, $this->admin, 'Superseded manually');

        $this->assertSame(PromptModuleVersion::STATUS_DEPRECATED, $deprecated->lifecycle_status);
        $this->assertNull($module->fresh()->current_version_id);
        $this->assertSame($content, $deprecated->content);
        $this->assertSame('Superseded manually', $deprecated->change_note);
    }

    public function test_publish_and_archive_query_the_parent_module_before_relevant_versions(): void
    {
        $module = $this->moduleWithVersions(['production', 'draft']);
        $draft = $module->versions()->where('version', 2)->sole();
        $publishQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$publishQueries): void {
            $publishQueries[] = $query->sql;
        });

        $published = app(PromptModuleService::class)->publish($draft, $this->admin, 'Promote draft');

        $this->assertSame(PromptModuleVersion::STATUS_PRODUCTION, $published->lifecycle_status);
        $this->assertModuleQueryPrecedesVersionQuery($publishQueries);

        $deprecated = $module->versions()->where('version', 1)->sole();
        $archiveQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$archiveQueries): void {
            $archiveQueries[] = $query->sql;
        });

        $archived = app(PromptModuleService::class)->archive($deprecated, $this->admin, 'Retire old snapshot');

        $this->assertSame(PromptModuleVersion::STATUS_ARCHIVED, $archived->lifecycle_status);
        $this->assertModuleQueryPrecedesVersionQuery($archiveQueries);
    }

    public function test_lifecycle_activity_logs_exclude_prompt_text_and_retain_snapshot_digest(): void
    {
        $module = $this->moduleWithVersions(['draft']);
        $draft = $module->versions()->sole();

        app(PromptModuleService::class)->publish($draft, $this->admin, 'Approved after review');

        $details = (string) AdminActivityLog::query()->where('action', 'prompt_module_version:published')->sole()->details;
        $this->assertStringNotContainsString($draft->content, $details);
        $this->assertStringContainsString('"snapshot_digest":"'.hash('sha256', $draft->content).'"', $details);
        $this->assertStringContainsString('"reason_digest":"'.hash('sha256', 'Approved after review').'"', $details);
    }

    /** @param list<string> $statuses */
    private function moduleWithVersions(array $statuses): PromptModule
    {
        $module = PromptModule::query()->create($this->identity('lifecycle'));

        foreach ($statuses as $index => $status) {
            PromptModuleVersion::forceCreate([
                'prompt_module_id' => $module->id,
                'version' => $index + 1,
                'content' => 'Prompt snapshot '.($index + 1).'.',
                'variable_contract' => ['required' => ['audience']],
                'output_contract' => ['type' => 'article'],
                'parameter_defaults' => ['tone' => 'helpful'],
                'content_sha256' => hash('sha256', 'Prompt snapshot '.($index + 1).'.'),
                'lifecycle_status' => $status,
                'created_by_admin_id' => $this->admin->id,
            ]);
        }

        return $module;
    }

    private function moduleWithActivePresetDependency(): PromptModuleVersion
    {
        $module = $this->moduleWithVersions(['production']);
        $version = $module->versions()->sole();
        $preset = PromptPreset::query()->create([
            'preset_key' => 'active-preset',
            'display_name' => 'Active preset',
            'owned_by_admin_id' => $this->admin->id,
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 1200],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->admin->id,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $presetVersion->id,
            'prompt_module_version_id' => $version->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);

        return $version;
    }

    /** @return array<string, mixed> */
    private function identity(string $key): array
    {
        return [
            'module_key' => "module-{$key}",
            'display_name' => 'Lifecycle module',
            'module_type' => PromptModule::TYPE_CONTENT_TYPE,
            'description' => 'Prompt module lifecycle test fixture.',
            'applicability' => ['content_type' => ['guide']],
            'is_mandatory' => false,
            'default_priority' => 10,
            'is_enabled' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function versionData(string $content): array
    {
        return [
            'content' => $content,
            'variable_contract' => ['required' => ['audience']],
            'output_contract' => ['type' => 'article'],
            'parameter_defaults' => ['tone' => 'helpful'],
            'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
            'change_note' => 'Initial snapshot',
        ];
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
            if (str_contains($sql, 'from "'.$table.'"')) {
                return $index;
            }
        }

        $this->fail("Expected a query for {$table}.");
    }
}
