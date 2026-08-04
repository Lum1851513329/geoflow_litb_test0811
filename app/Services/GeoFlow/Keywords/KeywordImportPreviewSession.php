<?php

namespace App\Services\GeoFlow\Keywords;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class KeywordImportPreviewSession
{
    public function __construct(
        private readonly KeywordImportPreview $preview,
        private readonly KeywordWorkbookReader $reader,
        private readonly KeywordColumnMapper $mapper,
    ) {}

    /** @param array<string,string> $mapping */
    public function begin(UploadedFile $file, string $sourceType, array $mapping): array
    {
        $this->pruneExpired();
        $path = 'keyword-import-previews/'.Str::uuid().'.xlsx';
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || ! Storage::disk('local')->put($path, $contents)) {
            throw new InvalidArgumentException('keyword_import.preview_store_failed');
        }

        try {
            $absolutePath = Storage::disk('local')->path($path);
            $inspection = $this->reader->inspect($absolutePath);
            $headers = $inspection['sheets'][0]['headers'] ?? [];
            $resolvedMapping = $this->resolveMapping($this->mapper->suggest($headers, $sourceType), $mapping, $headers);
            $preview = $this->preview->build($absolutePath, $sourceType, $resolvedMapping);
            $hash = hash_file('sha256', $absolutePath);
            if ($hash === false) {
                throw new InvalidArgumentException('keyword_import.preview_hash_failed');
            }

            return [
                'preview' => $preview,
                'headers' => $headers,
                'resolved_mapping' => $resolvedMapping,
                'token' => Crypt::encryptString(json_encode([
                    'path' => $path,
                    'sha256' => $hash,
                    'original_filename' => $file->getClientOriginalName(),
                    'source_type' => $sourceType,
                    'expires_at' => now()->addMinutes(30)->timestamp,
                ], JSON_THROW_ON_ERROR)),
            ];
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function resolve(string $token, string $sourceType): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('keyword_import.preview_token_invalid', previous: $exception);
        }
        if (! is_array($payload)
            || ! isset($payload['path'], $payload['sha256'], $payload['original_filename'], $payload['source_type'], $payload['expires_at'])
            || ! is_string($payload['path'])
            || preg_match('#^keyword-import-previews/[0-9a-f-]{36}\.xlsx$#i', $payload['path']) !== 1
            || ! is_int($payload['expires_at'])
            || ! hash_equals((string) $payload['source_type'], $sourceType)
            || ! Storage::disk('local')->exists($payload['path'])) {
            throw new InvalidArgumentException('keyword_import.preview_token_invalid');
        }
        if ($payload['expires_at'] < now()->timestamp) {
            $this->forget($payload['path']);
            throw new InvalidArgumentException('keyword_import.preview_token_invalid');
        }
        $absolutePath = Storage::disk('local')->path($payload['path']);
        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false || ! hash_equals((string) $payload['sha256'], $hash)) {
            throw new InvalidArgumentException('keyword_import.preview_token_invalid');
        }

        return $payload + ['absolute_path' => $absolutePath];
    }

    public function forget(string $path): void
    {
        if (preg_match('#^keyword-import-previews/[0-9a-f-]{36}\.xlsx$#i', $path) === 1) {
            Storage::disk('local')->delete($path);
        }
    }

    private function pruneExpired(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes(30)->timestamp;
        foreach ($disk->files('keyword-import-previews') as $path) {
            if (preg_match('#^keyword-import-previews/[0-9a-f-]{36}\.xlsx$#i', $path) === 1 && $disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }

    /** @param array<string,string> $suggested @param array<string,string> $explicit @param list<string> $headers */
    private function resolveMapping(array $suggested, array $explicit, array $headers): array
    {
        $resolved = $suggested;
        foreach ($explicit as $header => $field) {
            if (! in_array($header, $headers, true)) {
                continue;
            }
            foreach ($resolved as $existingHeader => $existingField) {
                if ($existingField === $field) {
                    unset($resolved[$existingHeader]);
                }
            }
            $resolved[$header] = $field;
        }

        return $resolved;
    }
}
