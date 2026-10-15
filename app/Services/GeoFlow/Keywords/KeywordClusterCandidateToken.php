<?php

namespace App\Services\GeoFlow\Keywords;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

final class KeywordClusterCandidateToken
{
    public function issue(array $suggestion, int $adminId): string
    {
        return Crypt::encryptString(json_encode(['suggestion' => $suggestion, 'admin_id' => $adminId, 'expires_at' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $token, int $adminId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('keyword_cluster.candidate_token_invalid', previous: $exception);
        }
        if (! is_array($payload)
            || ! is_array($payload['suggestion'] ?? null)
            || ! is_int($payload['admin_id'] ?? null)
            || $payload['admin_id'] !== $adminId
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] < now()->timestamp) {
            throw new InvalidArgumentException('keyword_cluster.candidate_token_invalid');
        }

        return $payload['suggestion'];
    }
}
