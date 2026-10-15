<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentGeneration\BulkContentImportService;
use App\Domain\ContentGeneration\BulkContentImportValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmSemrushImportRequest;
use App\Http\Requests\Admin\PreviewSemrushImportRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\Prompt;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\Semrush\SemrushDuplicateTopicDetector;
use App\Services\GeoFlow\Semrush\SemrushImportPreviewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Upload-preview-confirm wrapper around the existing, unchanged
 * bulk-content-import pipeline: converts a raw Semrush topic-gap CSV
 * (via SemrushTopicGapConverter) into candidate rows, lets the admin see
 * accepted/rejected topics before anything is created, then — on confirm
 * — builds the exact xlsx shape BulkContentImportParser already expects
 * and hands it to the same BulkContentImportService::import() the manual
 * upload form uses. Nothing about the existing parser/service changes;
 * this only adds a conversion + review step in front of it.
 */
final class SemrushImportController extends Controller
{
    private const HEADERS = ['site', 'preset', 'category', 'topic', 'brand', 'directory', 'knowledge_site', 'core_question', 'primary_keyword', 'reference_urls', 'knowledge_mode', 'content_type', 'external_evidence_policy', 'external_general_domains', 'brand_profile_snapshot_hash'];

    public function preview(PreviewSemrushImportRequest $request, SemrushImportPreviewSession $session, SemrushDuplicateTopicDetector $duplicateDetector): View|RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('semrush_csv');

        try {
            $result = $session->begin($file, $request->validated(), (int) $request->user('admin')->id);
        } catch (InvalidArgumentException $exception) {
            if (str_starts_with($exception->getMessage(), 'semrush_import.missing_columns:')) {
                $missing = substr($exception->getMessage(), strlen('semrush_import.missing_columns:'));

                return back()->withErrors(['semrush_csv' => "CSV 缺少必需列：{$missing}。请确认这是 Semrush Topic Gap 导出文件。"]);
            }

            return back()->withErrors(['semrush_csv' => '无法读取上传的文件。']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['semrush_csv' => '转换失败，请稍后重试。']);
        }

        // Semrush re-exports periodically and the same topic often comes back
        // reworded, so a straight title match won't catch it — this flags
        // likely-already-written rows for the admin to review/uncheck below,
        // it never excludes anything on its own.
        $result['conversion']['accepted'] = $duplicateDetector->annotate($result['conversion']['accepted']);

        return view('admin.bulk-content-import.index', $this->viewData() + [
            'semrushPreview' => $result['conversion'],
            'semrushPreviewToken' => $result['token'],
            'semrushLimit' => $result['limit'],
        ]);
    }

    public function confirm(ConfirmSemrushImportRequest $request, SemrushImportPreviewSession $session, BulkContentImportService $importer): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        try {
            $preview = $session->resolve($data['preview_token'], (int) $actor->id);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['preview_token' => '预览已过期或无效，请重新上传。']);
        }

        // Manual exclusion: keep only the rows the admin left checked
        // (indices into the accepted list, same order it was previewed in
        // — resolve() deterministically re-runs the same conversion, so
        // the ordering here matches what the browser rendered).
        $keep = array_flip(array_map('intval', $data['keep']));
        $preview['conversion']['accepted'] = array_values(array_filter(
            $preview['conversion']['accepted'],
            static fn ($row, $i): bool => isset($keep[$i]),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($preview['conversion']['accepted'] === []) {
            $session->forget($preview['path']);

            return back()->withErrors(['keep' => '请至少勾选一个要导入的主题。']);
        }

        $tempXlsxPath = null;

        try {
            $tempXlsxPath = $this->buildSpreadsheet($preview);

            $result = $importer->import(
                $tempXlsxPath,
                (int) $request->integer('ai_model_id'),
                $request->boolean('need_review'),
                $actor,
                $request->filled('external_evidence_policy') ? (string) $request->input('external_evidence_policy') : 'on_insufficient',
                $this->confirmGeneralDomains($request, $preview),
                in_array($request->input('generation_engine'), ['quality_gated', 'workbuddy'], true)
                    ? (string) $request->input('generation_engine')
                    : 'quality_gated',
                $request->filled('prompt_id') ? (int) $request->input('prompt_id') : null,
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors(['preview_token' => '无法解析所选的发布渠道/模板/知识品牌，请重新开始。']);
        } catch (BulkContentImportValidationException $exception) {
            return back()->withErrors([
                'preview_token' => collect($exception->errors())
                    ->map(fn (array $error): string => "第 {$error['row']} 行：{$error['message']}")
                    ->implode(' '),
            ]);
        } finally {
            if ($tempXlsxPath !== null && is_file($tempXlsxPath)) {
                @unlink($tempXlsxPath);
            }
            $session->forget($preview['path']);
        }

        return redirect()->route('admin.tasks.index')
            ->with('message', "已创建 {$result['tasks_created']} 个任务，共导入 {$result['rows_imported']} 行（来自 Semrush 转换）。");
    }

    /** @return array<string,mixed> */
    private function viewData(): array
    {
        return [
            'aiModels' => AiModel::query()
                ->select(['id', 'name'])
                ->where('status', 'active')
                ->where(function ($query): void {
                    $query->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                })
                ->orderBy('failover_priority')
                ->orderByDesc('id')
                ->get(),
            'distributionChannels' => DistributionChannel::query()->select(['id', 'name', 'domain'])->orderBy('name')->get(),
            'eligiblePresets' => PromptPreset::query()
                ->select(['id', 'preset_key', 'display_name'])
                ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
                ->orderBy('display_name')
                ->get(),
            'knowledgeBrands' => KnowledgeBrand::query()->select(['id', 'key', 'name'])->orderBy('name')->get(),
            // type=content is the same constraint TaskLifecycleService enforces when
            // validating Task.prompt_id — only these are valid to attach to a task.
            'workBuddyPrompts' => Prompt::query()->select(['id', 'name'])->where('type', 'content')->orderBy('name')->get(),
            'knowledgeSites' => KnowledgeSite::query()->select(['id', 'brand_id', 'key', 'canonical_host'])->orderBy('key')->get(),
            'knowledgeDirectories' => KnowledgeDirectory::query()->select(['id', 'brand_id', 'path', 'name'])->orderBy('path')->get(),
        ];
    }

    /** @param array<string,mixed> $preview resolved SemrushImportPreviewSession payload */
    private function buildSpreadsheet(array $preview): string
    {
        $channel = DistributionChannel::query()->find($preview['distribution_channel_id']);
        $brand = KnowledgeBrand::query()->find($preview['knowledge_brand_id']);
        if ($channel === null || $brand === null) {
            throw new RuntimeException('semrush_import.reference_missing');
        }
        // Null prompt_preset_id means "let each row use its own
        // category-mapped preset_key" (SemrushTopicGapConverter); a
        // non-null value forces every row onto this one override preset.
        $overridePresetCell = null;
        if ($preview['prompt_preset_id'] !== null) {
            $preset = PromptPreset::query()->find($preview['prompt_preset_id']);
            if ($preset === null) {
                throw new RuntimeException('semrush_import.reference_missing');
            }
            $overridePresetCell = $preset->preset_key;
        }
        $site = $preview['knowledge_site_id'] !== null ? KnowledgeSite::query()->find($preview['knowledge_site_id']) : null;
        $directory = $preview['knowledge_directory_id'] !== null ? KnowledgeDirectory::query()->find($preview['knowledge_directory_id']) : null;

        // "Name (domain)" — BulkContentImportParser::resolveByParenthetical()
        // only reads the trailing parenthetical, so the display name itself
        // is decorative; only the domain inside the parens has to match.
        $siteCell = "{$channel->name} ({$channel->domain})";
        $brandCell = $brand->key;
        $directoryCell = $directory?->path ?? '';
        $knowledgeSiteCell = $site?->key ?? '';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Semrush 导入');

        foreach (self::HEADERS as $i => $key) {
            $sheet->setCellValueExplicit([$i + 1, 1], $key, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([$i + 1, 2], '', DataType::TYPE_STRING);
        }

        $rowNumber = 3;
        foreach ($preview['conversion']['accepted'] as $item) {
            $presetCell = $overridePresetCell ?? $item['preset_key'];
            $values = [
                $siteCell, $presetCell, $item['category'], $item['topic'], $brandCell,
                $directoryCell !== '' ? $directoryCell : (string) ($item['directory'] ?? ''),
                $knowledgeSiteCell, $item['core_question'], $item['primary_keyword'], '',
                (string) ($item['knowledge_mode'] ?? 'general'),
                (string) ($item['content_type'] ?? ''),
                (string) ($item['external_evidence_policy'] ?? 'on_insufficient'),
                implode(',', array_values((array) ($item['external_general_domains'] ?? []))),
                (string) ($item['brand_profile_snapshot_hash'] ?? ($preview['brand_profile_snapshot_hash'] ?? '')),
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit([$i + 1, $rowNumber], $value, DataType::TYPE_STRING);
            }
            $rowNumber++;
        }

        $tempPath = Storage::disk('local')->path('semrush-import-previews/'.uniqid('confirm-', true).'.xlsx');
        (new Xlsx($spreadsheet))->save($tempPath);

        return $tempPath;
    }

    private function parseDomains(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain, " .\t\n\r\0\x0B")),
            preg_split('/[,\s]+/', $value) ?: [],
        ))));
    }

    /** @param array<string,mixed> $preview @return list<string> */
    private function confirmGeneralDomains(ConfirmSemrushImportRequest $request, array $preview): array
    {
        $submitted = $this->parseDomains((string) $request->input('external_general_domains', ''));
        if ($submitted !== []) {
            return $submitted;
        }

        $domains = [];
        foreach ((array) ($preview['conversion']['accepted'] ?? []) as $item) {
            $domains = array_merge($domains, (array) ($item['external_general_domains'] ?? []));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            $domains,
        ))));
    }
}
