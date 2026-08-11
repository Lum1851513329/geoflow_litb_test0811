<?php

namespace App\Services\GeoFlow\LitbAssets;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class LitbSourceScanner
{
    /** @return list<LitbSourceRecord> */
    public function scanKnowledge(string $root): array
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException('litb_assets.knowledge_root_missing');
        }

        $records = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (! in_array($extension, ['md', 'txt', 'docx'], true)) {
                continue;
            }
            $absolutePath = $file->getRealPath();
            if ($absolutePath === false || ! str_starts_with($absolutePath, $resolvedRoot.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($resolvedRoot) + 1));
            $sha256 = hash_file('sha256', $absolutePath);
            if ($sha256 === false) {
                throw new RuntimeException('litb_assets.source_hash_failed');
            }
            $classification = $this->classify($relativePath);
            $records[] = new LitbSourceRecord(
                sourceKey: 'litb.knowledge.'.hash('sha256', mb_strtolower($relativePath, 'UTF-8')),
                absolutePath: $absolutePath,
                relativePath: $relativePath,
                sha256: $sha256,
                title: $this->title($file->getBasename('.'.$extension)),
                languageCode: $this->language($relativePath),
                groupKey: $classification['group_key'],
                sourceType: $classification['source_type'],
                riskLevel: $classification['risk_level'],
                reviewStatus: $classification['review_status'],
                timeSensitive: $classification['time_sensitive'],
            );
        }

        usort($records, fn (LitbSourceRecord $left, LitbSourceRecord $right): int => strcmp($left->sourceKey, $right->sourceKey));

        return $records;
    }

    /** @return array{group_key:string,source_type:string,risk_level:string,review_status:string,time_sensitive:bool} */
    private function classify(string $relativePath): array
    {
        $path = mb_strtolower($relativePath, 'UTF-8');
        if (str_contains($path, 'officialknowege/litb_reports')) {
            return ['group_key' => 'marketing-research-reference', 'source_type' => 'research', 'risk_level' => 'high', 'review_status' => 'unreviewed', 'time_sensitive' => true];
        }
        if (preg_match('#/(customer_service|customer-service|02_)#', $path) === 1
            || str_contains($path, 'payment') || str_contains($path, 'shipping')
            || str_contains($path, 'return') || str_contains($path, 'contact')) {
            return ['group_key' => 'customer-service-policies', 'source_type' => 'official', 'risk_level' => 'medium', 'review_status' => 'reviewed', 'time_sensitive' => true];
        }
        if (str_contains($path, 'news') || str_contains($path, 'investor')) {
            return ['group_key' => 'news-investor-information', 'source_type' => 'official', 'risk_level' => 'high', 'review_status' => 'reviewed', 'time_sensitive' => true];
        }
        if (str_contains($path, 'product') || str_contains($path, 'categor')) {
            return ['group_key' => 'product-category', 'source_type' => 'official', 'risk_level' => 'medium', 'review_status' => 'reviewed', 'time_sensitive' => false];
        }

        return ['group_key' => 'official-company-brand', 'source_type' => 'official', 'risk_level' => 'medium', 'review_status' => 'reviewed', 'time_sensitive' => false];
    }

    private function title(string $basename): string
    {
        $title = preg_replace('/^\d+(?:\.\d+)?[_\s-]*/u', '', $basename) ?? $basename;

        return trim(str_replace(['_', '-'], ' ', $title));
    }

    private function language(string $relativePath): string
    {
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $relativePath) === 1) {
            return 'zh-CN';
        }
        if (preg_match('/(?:^|\/)(?:pt|portuguese)(?:\/|$)/i', $relativePath) === 1) {
            return 'pt';
        }

        return 'en';
    }
}
