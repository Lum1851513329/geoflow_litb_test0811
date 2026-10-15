<?php

namespace App\Services\GeoFlow\Semrush;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Upload → preview → confirm session for the Semrush topic-gap importer,
 * mirroring App\Services\GeoFlow\Keywords\KeywordImportPreviewSession:
 * the uploaded CSV is stored under a private disk, converted once to
 * build the preview, and an encrypted token (no DB row) carries the
 * stored path + the options the row-builder needs at confirm time. The
 * token is re-validated (path shape, admin match, file hash, 30-minute
 * expiry) before every use, and confirm() re-runs the same deterministic
 * conversion rather than trusting anything the browser sends back.
 */
final class SemrushImportPreviewSession
{
    public function __construct(
        private readonly SemrushTopicGapConverter $converter,
        private readonly SemrushBrandProfileResolver $profiles,
    ) {}

    /** @param array<string,mixed> $options distribution_channel_id, prompt_preset_id, knowledge_brand_id, knowledge_site_id, knowledge_directory_id, limit */
    public function begin(UploadedFile $file, array $options, int $adminId): array
    {
        $this->pruneExpired();
        $path = 'semrush-import-previews/'.Str::uuid().'.csv';
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || ! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('semrush_import.preview_store_failed');
        }

        try {
            $absolutePath = Storage::disk('local')->path($path);
            $limit = max(1, min(500, (int) ($options['limit'] ?? 50)));
            $brandId = (int) ($options['knowledge_brand_id'] ?? 0);
            $siteId = isset($options['knowledge_site_id']) && $options['knowledge_site_id'] !== '' ? (int) $options['knowledge_site_id'] : null;
            $profile = $this->profiles->resolve($brandId, $siteId);
            $conversion = $this->converter->convert($absolutePath, $limit, $brandId, $siteId);
            if (! $conversion['ok']) {
                throw new InvalidArgumentException('semrush_import.missing_columns:'.implode(',', $conversion['missing_columns'] ?? []));
            }
            $hash = hash_file('sha256', $absolutePath);
            if ($hash === false) {
                throw new RuntimeException('semrush_import.preview_hash_failed');
            }

            return [
                'conversion' => $conversion,
                'limit' => $limit,
                'token' => Crypt::encryptString(json_encode([
                    'path' => $path,
                    'sha256' => $hash,
                    'original_filename' => $file->getClientOriginalName(),
                    'admin_id' => $adminId,
                    'limit' => $limit,
                    'distribution_channel_id' => (int) $options['distribution_channel_id'],
                    // Null = each row uses its own category-mapped preset
                    // (SemrushTopicGapConverter::CATEGORY_PRESET_MAP); set = force every row onto this one preset.
                    'prompt_preset_id' => isset($options['prompt_preset_id']) && $options['prompt_preset_id'] !== '' ? (int) $options['prompt_preset_id'] : null,
                    'knowledge_brand_id' => (int) $options['knowledge_brand_id'],
                    'knowledge_site_id' => isset($options['knowledge_site_id']) && $options['knowledge_site_id'] !== '' ? (int) $options['knowledge_site_id'] : null,
                    'knowledge_directory_id' => isset($options['knowledge_directory_id']) && $options['knowledge_directory_id'] !== '' ? (int) $options['knowledge_directory_id'] : null,
                    'brand_profile_snapshot_hash' => $profile['snapshot_hash'],
                    'expires_at' => now()->addMinutes(30)->timestamp,
                ], JSON_THROW_ON_ERROR)),
            ];
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function resolve(string $token, int $adminId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('semrush_import.preview_token_invalid', previous: $exception);
        }
        if (! is_array($payload)
            // prompt_preset_id is legitimately null (per-row auto-mapped preset) —
            // checked for key presence below, not via isset() with the rest.
            || ! isset($payload['path'], $payload['sha256'], $payload['expires_at'], $payload['admin_id'], $payload['limit'],
                $payload['distribution_channel_id'], $payload['knowledge_brand_id'], $payload['brand_profile_snapshot_hash'])
            || ! array_key_exists('prompt_preset_id', $payload)
            || ! is_string($payload['path'])
            || preg_match('#^semrush-import-previews/[0-9a-f-]{36}\.csv$#i', $payload['path']) !== 1
            || ! is_int($payload['expires_at'])
            || ! is_int($payload['admin_id'])
            || $payload['admin_id'] !== $adminId
            || ! Storage::disk('local')->exists($payload['path'])) {
            throw new InvalidArgumentException('semrush_import.preview_token_invalid');
        }
        if ($payload['expires_at'] < now()->timestamp) {
            $this->forget($payload['path']);
            throw new InvalidArgumentException('semrush_import.preview_token_invalid');
        }
        $absolutePath = Storage::disk('local')->path($payload['path']);
        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false || ! hash_equals((string) $payload['sha256'], $hash)) {
            throw new InvalidArgumentException('semrush_import.preview_token_invalid');
        }

        try {
            $profile = $this->profiles->resolve((int) $payload['knowledge_brand_id'], ($payload['knowledge_site_id'] ?? null) !== null ? (int) $payload['knowledge_site_id'] : null);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('semrush_import.preview_token_invalid', previous: $exception);
        }
        if (! hash_equals((string) $payload['brand_profile_snapshot_hash'], (string) $profile['snapshot_hash'])) {
            throw new InvalidArgumentException('semrush_import.preview_token_invalid');
        }

        $conversion = $this->converter->convert($absolutePath, (int) $payload['limit'], (int) $payload['knowledge_brand_id'], ($payload['knowledge_site_id'] ?? null) !== null ? (int) $payload['knowledge_site_id'] : null);
        if (! $conversion['ok']) {
            throw new InvalidArgumentException('semrush_import.preview_token_invalid');
        }

        return $payload + ['absolute_path' => $absolutePath, 'conversion' => $conversion];
    }

    public function forget(string $path): void
    {
        if (preg_match('#^semrush-import-previews/[0-9a-f-]{36}\.csv$#i', $path) === 1) {
            Storage::disk('local')->delete($path);
        }
    }

    public function pruneExpired(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes(30)->timestamp;
        $deleted = 0;
        foreach ($disk->files('semrush-import-previews') as $path) {
            if (preg_match('#^semrush-import-previews/[0-9a-f-]{36}\.csv$#i', $path) === 1 && $disk->lastModified($path) < $cutoff) {
                $deleted += $disk->delete($path) ? 1 : 0;
            }
        }

        return $deleted;
    }
}
