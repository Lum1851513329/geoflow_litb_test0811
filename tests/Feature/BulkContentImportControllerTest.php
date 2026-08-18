<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class BulkContentImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The shared sqlite testing schema (database/migrations/2026_04_18_120002_sqlite_geoflow_minimal_for_testing.php)
        // only creates `task_schedules` under the pgsql legacy-schema migration, not under sqlite — a pre-existing gap
        // unrelated to this task. TaskLifecycleService::createTask() unconditionally inserts into that table whenever
        // status=active (the status BulkContentImportService intentionally uses, so imported tasks start running
        // immediately, matching production Postgres behavior). Without this, every assertion below would fail with
        // "no such table: task_schedules" purely as a testing-environment artifact, not a bug in the code under test.
        if (Schema::getConnection()->getDriverName() === 'sqlite' && ! Schema::hasTable('task_schedules')) {
            Schema::create('task_schedules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->timestamp('next_run_time');
                $table->string('status', 20)->default('pending');
                $table->text('error_message')->default('');
                $table->timestamps();
            });
        }
    }

    /**
     * I3: bulk import unconditionally creates `quality_gated` Tasks, so it must sit
     * behind the same three feature flags as the rest of that pipeline. Previously the
     * route group carried no `geoflow.feature:*` middleware, so with the flags at their
     * default `false` an upload threw an uncaught `ApiException` from
     * `TaskLifecycleService` (a raw 500) instead of being undiscoverable.
     */
    public function test_bulk_import_routes_are_hidden_while_the_pipeline_flags_are_disabled(): void
    {
        config([
            'geoflow.content_strategy_enabled' => false,
            'geoflow.modular_prompts_enabled' => false,
            'geoflow.generation_quality_gate_enabled' => false,
        ]);
        $admin = Admin::query()->create([
            'username' => 'bulk-import-gate-tester', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin');

        $this->get($this->adminPath('bulk-content-import'))->assertNotFound();
        $this->get($this->adminPath('bulk-content-import/template'))->assertNotFound();
        $this->post($this->adminPath('bulk-content-import'), [])->assertNotFound();
    }

    /**
     * Routes are resolved by path here rather than by name: with the feature flags off
     * the named routes are not registered at all, so `route()` would throw before the
     * request under test could be made.
     */
    private function adminPath(string $path): string
    {
        $prefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        return '/'.$prefix.'/'.ltrim($path, '/');
    }

    public function test_uploading_a_valid_spreadsheet_creates_one_task_per_group(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        $admin = Admin::query()->create(['username' => 'bulk-import-tester', 'password' => bcrypt(bin2hex(random_bytes(32))), 'role' => 'super_admin', 'status' => 'active']);
        $channel = DistributionChannel::query()->create([
            'name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com',
            'endpoint_url' => 'https://blog.lightinthebox.com', 'channel_type' => 'wordpress', 'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'Category Authority Guide', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-and-buying-guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'LITB KB', 'content' => 'x', 'review_status' => 'reviewed']);
        $aiModel = AiModel::query()->create(['name' => 'Test model', 'status' => 'active', 'model_type' => 'chat', 'api_url' => 'https://example.invalid', 'api_key' => 'x', 'model_id' => 'x']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [
            ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'],
            ['instructions'],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic One', 'LITB KB', '', '', ''],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic Two', 'LITB KB', '', '', ''],
        ];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.bulk-content-import.store'), [
            'spreadsheet' => $file,
            'ai_model_id' => $aiModel->id,
            'need_review' => '1',
        ]);

        $response->assertRedirect(route('admin.tasks.index'));
        $this->assertSame(1, Task::query()->count());
        $task = Task::query()->firstOrFail();
        $this->assertSame('quality_gated', $task->generation_engine);
        $this->assertSame(2, $task->article_limit);
        $this->assertSame(2, $task->titleLibrary->titles()->count());
        $this->assertSame($channel->id, $task->distributionChannels()->first()?->id);
    }

    /**
     * import()'s per-group work (TitleLibrary + Task + channel sync) is atomic on its own via the
     * DB::transaction() inside createTaskForGroup(), but until this test's underlying fix, nothing
     * wrapped the outer foreach ($groups as ...) loop itself. A runtime failure in a later group
     * (e.g. DistributionOrchestrator::syncTaskChannels() throwing for reasons the upfront
     * parse()/conflicts() validation can't foresee, such as a channel becoming unavailable between
     * upload and processing) would previously leave earlier groups' Tasks permanently committed
     * even though the request as a whole failed. This proves the fix: with the outer transaction in
     * place, a failure in the second of two groups rolls back the first group's Task too, so the
     * whole batch is truly all-or-nothing.
     */
    public function test_a_runtime_failure_in_a_later_group_rolls_back_every_group_in_the_batch(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        $admin = Admin::query()->create(['username' => 'bulk-import-atomicity-tester', 'password' => bcrypt(bin2hex(random_bytes(32))), 'role' => 'super_admin', 'status' => 'active']);
        $channel = DistributionChannel::query()->create([
            'name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com',
            'endpoint_url' => 'https://blog.lightinthebox.com', 'channel_type' => 'wordpress', 'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'Category Authority Guide', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        // Two distinct categories => two distinct groups (groupKey is channel:preset:category), so the
        // import has to loop at least twice, giving a "second group" for the simulated failure to hit.
        $categoryOne = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-and-buying-guides']);
        $categoryTwo = Category::query()->create(['name' => 'Product Care', 'slug' => 'product-care']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'LITB KB', 'content' => 'x', 'review_status' => 'reviewed']);
        $aiModel = AiModel::query()->create(['name' => 'Test model', 'status' => 'active', 'model_type' => 'chat', 'api_url' => 'https://example.invalid', 'api_key' => 'x', 'model_id' => 'x']);

        // Force the second group's Task creation to fail with a plain runtime error that
        // parse()/conflicts() could never have caught upfront (they don't call
        // DistributionOrchestrator at all), simulating e.g. a channel becoming unavailable
        // mid-batch. The first group's call is left as a no-op success so it reaches the point
        // of having a real, committed-looking Task before the second group blows up.
        $callCount = 0;
        $orchestrator = Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('lockTaskChannelSelection')->andReturnNull();
        $orchestrator->shouldReceive('syncTaskChannels')->twice()->andReturnUsing(function () use (&$callCount): void {
            $callCount++;
            if ($callCount === 2) {
                throw new \RuntimeException('simulated distribution failure for the second group');
            }
        });
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [
            ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'],
            ['instructions'],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic One', 'LITB KB', '', '', ''],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Product Care', 'Topic Two', 'LITB KB', '', '', ''],
        ];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.bulk-content-import.store'), [
            'spreadsheet' => $file,
            'ai_model_id' => $aiModel->id,
            'need_review' => '1',
        ]);

        // The controller only catches BulkContentImportValidationException, so this uncaught
        // RuntimeException surfaces as a raw 500 — that UX gap is a separate, known concern (see
        // the report), but the point of this test is solely the DB-level guarantee below: even
        // though group one's Task looked fully created before group two failed, none of it survives.
        $response->assertStatus(500);
        $this->assertSame(0, Task::query()->count(), 'a runtime failure partway through the batch must roll back every group, not just the failing one');
        $this->assertSame(0, TitleLibrary::query()->count());
        $this->assertSame(0, Title::query()->count());
    }
}
