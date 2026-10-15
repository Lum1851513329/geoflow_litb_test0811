<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentGeneration\BulkContentImportService;
use App\Domain\ContentGeneration\BulkContentImportTemplateBuilder;
use App\Domain\ContentGeneration\BulkContentImportValidationException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BulkContentImportController extends Controller
{
    public function __construct(
        private readonly BulkContentImportService $importer,
        private readonly BulkContentImportTemplateBuilder $templateBuilder,
    ) {}

    public function index(Request $request): View
    {
        $aiModels = AiModel::query()
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->get();

        return view('admin.bulk-content-import.index', [
            'pageTitle' => '批量导入文章任务',
            'activeMenu' => 'tasks',
            'aiModels' => $aiModels,
            'distributionChannels' => DistributionChannel::query()->select(['id', 'name', 'domain'])->orderBy('name')->get(),
            'eligiblePresets' => PromptPreset::query()
                ->select(['id', 'preset_key', 'display_name'])
                ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
                ->orderBy('display_name')
                ->get(),
            'knowledgeBrands' => KnowledgeBrand::query()->select(['id', 'key', 'name'])->orderBy('name')->get(),
            'knowledgeSites' => KnowledgeSite::query()->select(['id', 'brand_id', 'key', 'canonical_host'])->orderBy('key')->get(),
            'knowledgeDirectories' => KnowledgeDirectory::query()->select(['id', 'brand_id', 'path', 'name'])->orderBy('path')->get(),
        ]);
    }

    public function template(): StreamedResponse
    {
        $spreadsheet = $this->templateBuilder->build();
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, 'geoflow-bulk-content-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'spreadsheet' => ['required', 'file', 'mimes:xlsx'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'need_review' => ['sometimes', 'boolean'],
            'external_evidence_policy' => ['nullable', 'in:off,on_insufficient'],
            'external_general_domains' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        $path = $request->file('spreadsheet')->getRealPath();

        try {
            $result = $this->importer->import(
                $path,
                (int) $request->integer('ai_model_id'),
                $request->boolean('need_review'),
                $actor,
                (string) $request->input('external_evidence_policy', 'off'),
                $this->parseDomains((string) $request->input('external_general_domains', '')),
            );
        } catch (BulkContentImportValidationException $exception) {
            return back()->withErrors([
                'spreadsheet' => collect($exception->errors())
                    ->map(fn (array $error): string => "第 {$error['row']} 行：{$error['message']}")
                    ->implode(' '),
            ]);
        }

        return redirect()->route('admin.tasks.index')
            ->with('message', "已创建 {$result['tasks_created']} 个任务，共导入 {$result['rows_imported']} 行。");
    }

    private function parseDomains(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain, " .\t\n\r\0\x0B")),
            preg_split('/[,\s]+/', $value) ?: [],
        ))));
    }
}
