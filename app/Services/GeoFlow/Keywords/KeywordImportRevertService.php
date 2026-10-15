<?php

namespace App\Services\GeoFlow\Keywords;

use App\Events\KeywordImportProgressed;
use App\Models\Keyword;
use App\Models\KeywordImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class KeywordImportRevertService
{
    public function revert(KeywordImportBatch $batch): KeywordImportBatch
    {
        $reverted = DB::transaction(function () use ($batch): KeywordImportBatch {
            $batch = KeywordImportBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($batch->status === 'reverted') {
                return $batch;
            }
            if (! in_array($batch->status, ['completed', 'failed'], true)) {
                throw new InvalidArgumentException("A keyword import in {$batch->status} status cannot be reverted.");
            }
            $batch->forceFill(['status' => 'reverting'])->save();

            $ownedKeywordIds = $batch->rows()->where('status', 'imported')->whereNotNull('keyword_id')->pluck('keyword_id')->unique();
            $batch->metrics()->delete();
            $retained = 0;

            foreach ($ownedKeywordIds as $keywordId) {
                $keyword = Keyword::query()->find($keywordId);
                if (! $keyword) {
                    continue;
                }

                $reasons = $this->sharedReferenceReasons($keyword, $batch);
                if ($reasons !== []) {
                    $retained++;
                    $batch->rows()->where('keyword_id', $keyword->id)->update([
                        'status' => 'retained_shared',
                        'error_message' => 'Retained shared keyword: '.implode(', ', $reasons).'.',
                    ]);

                    continue;
                }

                $batch->rows()->where('keyword_id', $keyword->id)->update([
                    'status' => 'reverted',
                    'error_message' => null,
                ]);
                $keyword->delete();
            }

            $batch->rows()
                ->whereIn('status', ['duplicate', 'rejected'])
                ->update(['status' => 'reverted']);
            $batch->library?->forceFill([
                'keyword_count' => Keyword::query()->where('library_id', $batch->library_id)->count(),
            ])->save();
            $batch->forceFill([
                'status' => 'reverted',
                'retained_shared_keyword_count' => $retained,
                'reverted_at' => now(),
            ])->save();

            return $batch->fresh();
        });

        $dispatch = static function () use ($reverted): void {
            try {
                KeywordImportProgressed::dispatch(
                    (int) $reverted->id,
                    'reverted',
                    (int) $reverted->processed_count,
                    (int) $reverted->total_count,
                );
            } catch (Throwable $exception) {
                Log::warning('Keyword import revert progress event failed.', [
                    'batch_id' => $reverted->id,
                    'exception' => $exception,
                ]);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }

        return $reverted;
    }

    /** @return array<int,string> */
    private function sharedReferenceReasons(Keyword $keyword, KeywordImportBatch $batch): array
    {
        $reasons = [];
        if ($keyword->metrics()->where(function ($query) use ($batch): void {
            $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batch->id);
        })->exists()) {
            $reasons[] = 'metric from another batch or source';
        }
        if ($keyword->clusters()->exists() || $keyword->primaryClusters()->exists()) {
            $reasons[] = 'cluster membership';
        }
        if ($keyword->taxonomyNodes()->exists()) {
            $reasons[] = 'taxonomy membership';
        }
        if ($keyword->incomingRelations()->exists() || $keyword->outgoingRelations()->exists()) {
            $reasons[] = 'keyword relation';
        }
        if (Keyword::query()->where('canonical_keyword_id', $keyword->id)->exists()) {
            $reasons[] = 'canonical keyword relation';
        }
        if ($this->hasTitleReference($keyword)) {
            $reasons[] = 'title reference';
        }
        if ($this->hasArticleReference($keyword)) {
            $reasons[] = 'article reference';
        }

        return $reasons;
    }

    private function hasTitleReference(Keyword $keyword): bool
    {
        $needle = $this->normalizeText($keyword->keyword);

        return DB::table('titles')->whereNotNull('keyword')->pluck('keyword')->contains(
            fn (mixed $value): bool => $this->normalizeText((string) $value) === $needle
        );
    }

    private function hasArticleReference(Keyword $keyword): bool
    {
        $needle = $this->normalizeText($keyword->keyword);
        foreach (DB::table('articles')->select(['original_keyword', 'keywords'])->cursor() as $article) {
            if ($this->normalizeText((string) $article->original_keyword) === $needle) {
                return true;
            }

            $keywords = $this->articleKeywords($article->keywords);
            foreach ($keywords as $value) {
                if ($this->normalizeText($value) === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function articleKeywords(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        return preg_split('/[,;\n]+/u', $value) ?: [];
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return mb_convert_case($value ?? '', MB_CASE_FOLD, 'UTF-8');
    }
}
