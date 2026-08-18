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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
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
}
