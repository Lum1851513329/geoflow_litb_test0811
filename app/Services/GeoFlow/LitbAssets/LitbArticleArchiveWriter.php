<?php

namespace App\Services\GeoFlow\LitbAssets;

use App\Models\ContentGenerationRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LitbArticleArchiveWriter
{
    /** @var array<int,string> */
    private const CONTENT_BEARING_STATUSES = [
        ContentGenerationRun::STATUS_READY_FOR_REVIEW,
        ContentGenerationRun::STATUS_COMPLETED,
        ContentGenerationRun::STATUS_ADOPTED,
    ];

    /** @var array<int,string> */
    private const DIAGNOSTIC_ONLY_STATUSES = [
        ContentGenerationRun::STATUS_NEEDS_EVIDENCE,
        ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED,
        ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
        ContentGenerationRun::STATUS_FAILED,
    ];

    private readonly string $root;

    public function __construct(LitbAssetConfig $config)
    {
        $this->root = $config->archiveRoot();
    }

    public function write(ContentGenerationRun $run): LitbArchiveResult
    {
        $assetKey = $this->assetKey($run);
        if (! in_array((string) $run->status, self::CONTENT_BEARING_STATUSES, true)) {
            throw new InvalidArgumentException('litb_archive.non_terminal_or_non_content_bearing_run');
        }
        $content = trim((string) ($run->adopted_output ?: $run->raw_output));
        if ($content === '') {
            throw new InvalidArgumentException('litb_archive.missing_final_content');
        }
        $slug = $this->slug($assetKey, $run);

        $articlePath = $this->root.'/articles/en/'.$slug.'.md';
        $this->atomicPut($articlePath, $this->renderFrontMatter($run, $assetKey, $content)."\n---\n\n".$content."\n");

        $evidencePath = $this->writeEvidenceManifest($run, $slug);
        $qualityPath = $this->writeQualityReports($run, $slug);

        return new LitbArchiveResult($assetKey, $articlePath, $evidencePath, $qualityPath);
    }

    public function writeDiagnostics(ContentGenerationRun $run): LitbArchiveResult
    {
        $assetKey = $this->assetKey($run);
        if (! in_array((string) $run->status, [...self::CONTENT_BEARING_STATUSES, ...self::DIAGNOSTIC_ONLY_STATUSES], true)) {
            throw new InvalidArgumentException('litb_archive.non_terminal_run');
        }
        $slug = $this->slug($assetKey, $run);

        $evidencePath = $this->writeEvidenceManifest($run, $slug);
        $qualityPath = $this->writeQualityReports($run, $slug);

        return new LitbArchiveResult($assetKey, null, $evidencePath, $qualityPath);
    }

    private function assetKey(ContentGenerationRun $run): string
    {
        $assetKey = (string) ($run->contentBrief?->asset_key ?? '');
        if ($assetKey === '' || preg_match('/^litb-quarterly-2026-q3\.[a-z0-9.-]+$/', $assetKey) !== 1) {
            throw new InvalidArgumentException('litb_archive.invalid_asset_key');
        }

        return $assetKey;
    }

    private function slug(string $assetKey, ContentGenerationRun $run): string
    {
        $provenance = (array) ($run->contentBrief?->asset_provenance ?? []);
        $slug = Str::slug((string) ($provenance['slug'] ?? $assetKey));
        if ($slug === '' || str_contains($slug, '..')) {
            throw new InvalidArgumentException('litb_archive.invalid_slug');
        }

        return $slug;
    }

    /** @return array<string,mixed> */
    private function renderFrontMatter(ContentGenerationRun $run, string $assetKey, string $content): string
    {
        $brief = $run->contentBrief;
        $provenance = (array) ($brief?->asset_provenance ?? []);
        $lines = [
            'title: '.$this->yamlScalar($provenance['seo_title'] ?? $brief?->topic ?? ''),
            'slug: '.$this->yamlScalar($provenance['slug'] ?? $assetKey),
            'excerpt: '.$this->yamlScalar($provenance['meta_description'] ?? ''),
            'seo_title: '.$this->yamlScalar($provenance['seo_title'] ?? ''),
            'meta_description: '.$this->yamlScalar($provenance['meta_description'] ?? ''),
            'canonical: '.$this->yamlScalar('https://www.lightinthebox.com/en/campaign/'.($provenance['slug'] ?? $assetKey)),
            'language: '.$this->yamlScalar((string) ($brief?->language_code ?? 'en')),
            'market: '.$this->yamlScalar((string) ($brief?->market_code ?? 'GLOBAL')),
            'category: '.$this->yamlScalar((string) ($provenance['pillar'] ?? '')),
            'tags: '.$this->yamlList(array_filter([$provenance['primary_keyword'] ?? null, ...(array) ($provenance['secondary_keywords'] ?? [])])),
            'keywords: '.$this->yamlList(array_filter([$provenance['primary_keyword'] ?? null, ...(array) ($provenance['secondary_keywords'] ?? []), ...(array) ($provenance['question_keywords'] ?? [])])),
            'schema_types: '.$this->yamlList((array) ($provenance['schema_types'] ?? [])),
            'store_landing_page_type: '.$this->yamlScalar((string) ($provenance['store_landing_page_type'] ?? '')),
            'content_brief_asset_key: '.$this->yamlScalar($assetKey),
            'content_generation_run_id: '.(int) $run->id,
            'generation_run_id: '.(int) $run->id,
            'generation_status: '.$this->yamlScalar((string) $run->status),
            'content_sha256: '.$this->yamlScalar(hash('sha256', $content)),
            'ai_model_id: '.(int) $run->ai_model_id,
            'preset_version_id: '.(int) $run->preset_version_id,
            'strategy_version_id: '.(int) $run->strategy_version_id,
            'blueprint_version_id: '.(int) $run->blueprint_version_id,
            'article_id: '.($run->article_id !== null ? (int) $run->article_id : 'null'),
            'planned_publish_at: '.$this->yamlScalar((string) ($brief?->planned_publish_at?->toAtomString() ?? '')),
            'generated_at: '.$this->yamlScalar((string) (($run->completed_at ?? now())->toAtomString())),
        ];

        return "---\n".implode("\n", $lines);
    }

    private function writeEvidenceManifest(ContentGenerationRun $run, string $slug): string
    {
        $evidence = $run->evidence()->get()->map(fn ($row): array => [
            'evidence_key' => $row->evidence_key,
            'knowledge_chunk_id' => $row->knowledge_chunk_id,
            'is_required' => (bool) $row->is_required,
            'evidence_snapshot' => $row->evidence_snapshot,
        ])->values()->all();

        $path = $this->root.'/evidence-manifests/'.$slug.'.json';
        $this->atomicPut($path, $this->jsonEncode([
            'content_generation_run_id' => (int) $run->id,
            'content_brief_asset_key' => $run->contentBrief?->asset_key,
            'status' => $run->status,
            'evidence' => $evidence,
        ]));

        return $path;
    }

    private function writeQualityReports(ContentGenerationRun $run, string $slug): string
    {
        $reports = $run->qualityReports()->get()->map(fn ($report): array => [
            'report_type' => $report->report_type,
            'status' => $report->status,
            'passed' => (bool) $report->passed,
            'score' => $report->score,
            'issues' => $report->issues,
            'revision_instructions' => $report->revision_instructions,
        ])->values()->all();

        $path = $this->root.'/quality-reports/'.$slug.'.json';
        $this->atomicPut($path, $this->jsonEncode([
            'content_generation_run_id' => (int) $run->id,
            'content_brief_asset_key' => $run->contentBrief?->asset_key,
            'status' => $run->status,
            'error_message' => $run->error_message,
            'quality_reports' => $reports,
        ]));

        return $path;
    }

    /** @param array<string,mixed> $payload */
    private function jsonEncode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function atomicPut(string $path, string $contents): void
    {
        if (str_contains($path, '..')) {
            throw new InvalidArgumentException('litb_archive.path_traversal_rejected');
        }
        $temporary = $path.'.'.Str::uuid()->toString().'.tmp';
        File::ensureDirectoryExists(dirname($path));
        File::put($temporary, $contents);
        File::move($temporary, $path);
    }

    private function yamlScalar(mixed $value): string
    {
        $string = (string) $value;
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $string);

        return '"'.$escaped.'"';
    }

    /** @param array<int,mixed> $values */
    private function yamlList(array $values): string
    {
        $values = array_values(array_unique(array_map('strval', array_filter($values, fn ($v): bool => trim((string) $v) !== ''))));
        if ($values === []) {
            return '[]';
        }

        return '['.implode(', ', array_map(fn (string $v): string => $this->yamlScalar($v), $values)).']';
    }
}
