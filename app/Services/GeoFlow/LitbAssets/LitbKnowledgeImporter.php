<?php

namespace App\Services\GeoFlow\LitbAssets;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeSourceParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LitbKnowledgeImporter
{
    public function __construct(
        private readonly KnowledgeSourceParser $parser,
        private readonly KnowledgeChunkSyncCoordinator $chunkSyncCoordinator,
    ) {}

    /** @param list<LitbSourceRecord> $records */
    public function import(array $records, bool $dispatchChunks = true): LitbAssetManifest
    {
        $manifest = new LitbAssetManifest();
        foreach ($records as $record) {
            try {
                $content = $this->content($record);
                $knowledgeBaseId = DB::transaction(
                    fn (): ?int => $this->importRecord($record, $content, $manifest),
                    3,
                );
                if ($dispatchChunks && $knowledgeBaseId !== null) {
                    $this->chunkSyncCoordinator->request($knowledgeBaseId);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $manifest->failed($record->sourceKey, $exception->getMessage());
            }
        }

        return $manifest;
    }

    private function importRecord(LitbSourceRecord $record, string $content, LitbAssetManifest $manifest): ?int
    {
        $knowledge = KnowledgeBase::query()->where('asset_key', $record->sourceKey)->lockForUpdate()->first();
        if ($knowledge === null) {
            $nameCollision = KnowledgeBase::query()->where('name', Str::limit($record->title, 100, ''))->lockForUpdate()->first();
            if ($nameCollision !== null) {
                $manifest->conflict($record->sourceKey, 'operator_owned_name_collision');

                return null;
            }
            $knowledge = KnowledgeBase::query()->create($this->payload($record, $content));
            $manifest->created($record->sourceKey);

            return (int) $knowledge->id;
        }

        $provenance = (array) $knowledge->asset_provenance;
        if (($provenance['source_sha256'] ?? null) === $record->sha256) {
            $manifest->skipped($record->sourceKey);

            return null;
        }
        if (($provenance['content_sha256'] ?? null) !== hash('sha256', (string) $knowledge->content)) {
            $manifest->conflict($record->sourceKey, 'operator_modified_content');

            return null;
        }
        $knowledge->update($this->payload($record, $content));
        $manifest->updated($record->sourceKey);

        return (int) $knowledge->id;
    }

    /** @return array<string, mixed> */
    private function payload(LitbSourceRecord $record, string $content): array
    {
        return [
            'asset_key' => $record->sourceKey,
            'name' => Str::limit($record->title, 100, ''),
            'description' => "Imported LITB {$record->groupKey} source: {$record->relativePath}",
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim(strip_tags($content)), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'file_type' => strtolower(pathinfo($record->absolutePath, PATHINFO_EXTENSION)) === 'docx' ? 'word' : 'markdown',
            'file_path' => '',
            'source_name' => $record->relativePath,
            'source_type' => $record->sourceType,
            'business_line' => $record->groupKey,
            'risk_level' => $record->riskLevel,
            'review_status' => $record->reviewStatus,
            'asset_provenance' => [
                'namespace' => 'litb.content-assets',
                'source_key' => $record->sourceKey,
                'source_path' => $record->relativePath,
                'source_sha256' => $record->sha256,
                'content_sha256' => hash('sha256', $content),
                'language_code' => $record->languageCode,
                'group_key' => $record->groupKey,
                'time_sensitive' => $record->timeSensitive,
                'imported_at' => now()->toAtomString(),
            ],
        ];
    }

    private function content(LitbSourceRecord $record): string
    {
        $extension = strtolower(pathinfo($record->absolutePath, PATHINFO_EXTENSION));
        if ($extension === 'docx') {
            return $this->parser->extractDocxContent($record->absolutePath);
        }
        $content = file_get_contents($record->absolutePath);
        if ($content === false) {
            throw new RuntimeException('litb_assets.source_read_failed');
        }

        return $this->parser->normalizeKnowledgeText($content);
    }
}
