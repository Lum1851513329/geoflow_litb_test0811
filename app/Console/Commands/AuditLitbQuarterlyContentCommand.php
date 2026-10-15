<?php

namespace App\Console\Commands;

use App\Models\ContentGenerationRun;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditLitbQuarterlyContentCommand extends Command
{
    protected $signature = 'geoflow:litb-content:audit {--strict : Exit non-zero when any archive fails integrity checks}';

    protected $description = 'Verify LITB quarterly article archives on disk match their generation runs in the database.';

    public function handle(LitbAssetConfig $config): int
    {
        $root = $config->archiveRoot();
        $articlesDir = $root.'/articles/en';
        $issues = [];
        $checked = 0;

        foreach (File::exists($articlesDir) ? File::files($articlesDir) : [] as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $checked++;
            $slug = $file->getFilenameWithoutExtension();
            $frontMatter = $this->parseFrontMatter(File::get($file->getPathname()));
            if ($frontMatter === null) {
                $issues[] = $slug.': front_matter_unparseable';

                continue;
            }
            $issues = [...$issues, ...$this->auditArticle($slug, $frontMatter, $root)];
        }

        $this->info('Archives checked: '.$checked);
        $this->info('Issues found: '.count($issues));
        foreach ($issues as $issue) {
            $this->line('  '.$issue);
        }

        if ($this->option('strict') && $issues !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string,string> $frontMatter @return list<string> */
    private function auditArticle(string $slug, array $frontMatter, string $root): array
    {
        $issues = [];
        $runId = (int) ($frontMatter['content_generation_run_id'] ?? 0);
        $run = $runId > 0 ? ContentGenerationRun::query()->with('contentBrief')->find($runId) : null;
        if ($run === null) {
            $issues[] = $slug.': run_missing';

            return $issues;
        }

        $expectedAssetKey = (string) ($run->contentBrief?->asset_key ?? '');
        if ($expectedAssetKey !== ($frontMatter['content_brief_asset_key'] ?? '')) {
            $issues[] = $slug.': asset_key_mismatch';
        }

        $liveContent = trim((string) ($run->adopted_output ?: $run->raw_output));
        if ($liveContent === '' || hash('sha256', $liveContent) !== ($frontMatter['content_sha256'] ?? '')) {
            $issues[] = $slug.': content_hash_mismatch';
        }

        $frontMatterArticleId = $frontMatter['article_id'] ?? 'null';
        $liveArticleId = $run->article_id !== null ? (string) $run->article_id : 'null';
        if ($frontMatterArticleId !== $liveArticleId) {
            $issues[] = $slug.': article_id_mismatch';
        }

        if (! File::exists($root.'/evidence-manifests/'.$slug.'.json')) {
            $issues[] = $slug.': missing_evidence_manifest';
        }
        if (! File::exists($root.'/quality-reports/'.$slug.'.json')) {
            $issues[] = $slug.': missing_quality_manifest';
        }

        $publishableStatuses = [
            ContentGenerationRun::STATUS_READY_FOR_REVIEW,
            ContentGenerationRun::STATUS_COMPLETED,
            ContentGenerationRun::STATUS_ADOPTED,
        ];
        $hasPassedQualityReport = $run->qualityReports()->where('passed', true)->exists();
        if (in_array((string) $run->status, $publishableStatuses, true)
            && $run->qualityReports()->exists()
            && ! $hasPassedQualityReport) {
            $issues[] = $slug.': quality_status_inconsistent';
        }

        return $issues;
    }

    /** @return array<string,string>|null */
    private function parseFrontMatter(string $markdown): ?array
    {
        if (! str_starts_with($markdown, "---\n")) {
            return null;
        }
        $end = strpos($markdown, "\n---\n", 4);
        if ($end === false) {
            return null;
        }
        $block = substr($markdown, 4, $end - 4);
        $fields = [];
        foreach (explode("\n", $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
            }
            $fields[$key] = $value;
        }

        return $fields;
    }
}
