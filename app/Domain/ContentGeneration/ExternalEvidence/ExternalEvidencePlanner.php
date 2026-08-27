<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

final class ExternalEvidencePlanner
{
    public function __construct(
        private readonly ExternalEvidenceProvider $provider,
        private readonly ExternalSourcePolicy $sourcePolicy,
    ) {}

    public function collect(array $missingClaims, array $referenceUrls, array $officialDomains, array $generalDomains, int $remainingPages = 6, int $remainingSearchRounds = 2, array $excludedUrls = [], ?callable $persist = null, ?int $deadlineTimestamp = null): array
    {
        $startingPages = max(0, $remainingPages);
        $startingSearchRounds = max(0, $remainingSearchRounds);
        $documents = [];
        $attempts = [];
        $requiresOfficial = false;
        foreach ($missingClaims as $claim) {
            if (is_array($claim) && (($claim['source_scope'] ?? null) === 'official' || ($claim['brand_fact'] ?? false) === true)) {
                $requiresOfficial = true;
                break;
            }
        }
        $allowedDomains = array_values(array_unique($requiresOfficial ? $officialDomains : array_merge($officialDomains, $generalDomains)));
        if ($allowedDomains === []) {
            return ['evidence' => [], 'attempts' => [], 'pages_used' => 0, 'search_rounds_used' => 0];
        }
        $seenUrls = array_fill_keys(array_filter(array_map('strval', $excludedUrls)), true);

        foreach (array_values(array_unique(array_filter(array_map('strval', $referenceUrls)))) as $url) {
            if ($remainingPages <= 0 || $this->expired($deadlineTimestamp) || isset($seenUrls[$url]) || ! $this->sourcePolicy->accepts($url, $allowedDomains)) {
                continue;
            }
            $seenUrls[$url] = true;
            $this->fetchOne($url, $allowedDomains, $documents, $attempts, $persist, $deadlineTimestamp);
            $remainingPages--;
        }

        if ($remainingPages > 0 && $remainingSearchRounds > 0 && $missingClaims !== [] && ! $this->expired($deadlineTimestamp)) {
            $queries = array_values(array_slice(array_filter(array_map(
                static fn (array $claim): string => trim((string) ($claim['question'] ?? '')),
                $missingClaims,
            )), 0, 3));
            if ($queries !== []) {
                $persist?->__invoke(['type' => 'search', 'phase' => 'started', 'queries' => $queries]);
                $search = $this->provider->search($queries, ['include_domains' => $allowedDomains, 'timeout_seconds' => $this->remainingSeconds($deadlineTimestamp)]);
                $attempts[] = ['type' => 'search', 'queries' => $queries, 'status' => $search['status'] ?? 'failed'];
                $persist?->__invoke(['type' => 'search', 'phase' => 'finished', 'queries' => $queries, 'status' => $search['status'] ?? 'failed']);
                $remainingSearchRounds--;
                foreach ((array) ($search['results'] ?? []) as $candidate) {
                    $url = trim((string) ($candidate['url'] ?? ''));
                    if ($remainingPages <= 0 || $this->expired($deadlineTimestamp) || isset($seenUrls[$url]) || ! $this->sourcePolicy->accepts($url, $allowedDomains)) {
                        continue;
                    }
                    $seenUrls[$url] = true;
                    $this->fetchOne($url, $allowedDomains, $documents, $attempts, $persist, $deadlineTimestamp);
                    $remainingPages--;
                }
            }
        }

        return [
            'evidence' => array_values($documents),
            'attempts' => $attempts,
            'pages_used' => $startingPages - max(0, $remainingPages),
            'search_rounds_used' => $startingSearchRounds - max(0, $remainingSearchRounds),
        ];
    }

    private function fetchOne(string $url, array $domains, array &$documents, array &$attempts, ?callable $persist, ?int $deadlineTimestamp): void
    {
        $persist?->__invoke(['type' => 'fetch', 'phase' => 'started', 'url' => $url]);
        $result = $this->provider->fetch($url, ['include_domains' => $domains, 'timeout_seconds' => $this->remainingSeconds($deadlineTimestamp)]);
        $finalUrl = trim((string) ($result['final_url'] ?? $url));
        $attempts[] = ['type' => 'fetch', 'url' => $url, 'final_url' => $finalUrl, 'status' => $result['status'] ?? 'failed', 'error_code' => $result['error_code'] ?? null];
        if (($result['status'] ?? null) !== 'fetched' || ! $this->sourcePolicy->accepts($finalUrl, $domains)) {
            $persist?->__invoke(end($attempts) + ['phase' => 'finished']);

            return;
        }
        $markdown = trim((string) ($result['markdown'] ?? ''));
        if ($markdown === '') {
            return;
        }
        $documents[$finalUrl] = [
            'fact_id' => 'external:'.substr(hash('sha256', $finalUrl), 0, 24),
            'source_type' => 'external',
            'source_url' => $finalUrl,
            'title' => trim((string) ($result['title'] ?? '')) ?: $finalUrl,
            'content' => $markdown,
            'content_sha256' => hash('sha256', $markdown),
            'retrieved_at' => now()->toIso8601String(),
        ];
        $persist?->__invoke(end($attempts) + ['phase' => 'finished', 'document' => $documents[$finalUrl]]);
    }

    private function expired(?int $deadlineTimestamp): bool
    {
        return $deadlineTimestamp !== null && time() >= $deadlineTimestamp;
    }

    private function remainingSeconds(?int $deadlineTimestamp): int
    {
        return $deadlineTimestamp === null ? 30 : max(1, $deadlineTimestamp - time());
    }
}
