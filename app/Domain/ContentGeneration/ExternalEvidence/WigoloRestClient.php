<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Throwable;

final class WigoloRestClient implements ExternalEvidenceProvider
{
    public function __construct(
        private readonly SafeOutboundHttpClient $client,
        private readonly Factory $http,
        private readonly ExternalSourcePolicy $sourcePolicy,
    ) {}

    public function search(array $queries, array $options = []): array
    {
        if (! $this->available()) {
            return ['status' => 'unavailable', 'results' => [], 'warnings' => ['external_evidence_disabled']];
        }
        $queries = array_values(array_slice(array_unique(array_filter(array_map(static fn ($q): string => mb_substr(trim((string) $q), 0, 500), $queries))), 0, 3));
        if ($queries === []) {
            return ['status' => 'failed', 'results' => [], 'warnings' => ['empty_query']];
        }
        $domains = $this->domains($options['include_domains'] ?? []);
        $payload = [
            'query' => $queries,
            'max_results' => min((int) config('external_evidence.max_results', 5), max(1, (int) ($options['max_results'] ?? config('external_evidence.max_results', 5)))),
            'max_fetches' => min((int) config('external_evidence.max_fetches', 3), max(1, (int) ($options['max_fetches'] ?? config('external_evidence.max_fetches', 3)))),
            'include_content' => true,
            'search_depth' => 'balanced',
            'citation_format' => 'json',
            'max_tokens_out' => min((int) config('external_evidence.max_tokens_out', 5000), max(256, (int) ($options['max_tokens_out'] ?? config('external_evidence.max_tokens_out', 5000)))),
        ];
        if ($domains !== []) {
            $payload['include_domains'] = $domains;
        }
        foreach (['language', 'country'] as $key) {
            if (trim((string) ($options[$key] ?? '')) !== '') {
                $payload[$key] = mb_substr(trim((string) $options[$key]), 0, 20);
            }
        }
        $response = $this->request('/v1/search', $payload);
        if ($response['status'] !== 'ok') {
            return ['status' => $response['status'], 'results' => [], 'warnings' => [$response['error_code']]];
        }
        $results = [];
        foreach ((array) ($response['data']['results'] ?? []) as $result) {
            if (! is_array($result)) {
                continue;
            }
            $url = trim((string) ($result['url'] ?? ''));
            if (! $this->sourcePolicy->accepts($url, $domains)) {
                continue;
            }
            $results[$url] = ['url' => $url, 'title' => mb_substr(trim((string) ($result['title'] ?? '')), 0, 500)];
        }

        return ['status' => 'ok', 'results' => array_values($results), 'warnings' => array_values(array_filter((array) ($response['data']['warnings'] ?? []), 'is_string'))];
    }

    public function fetch(string $url, array $options = []): array
    {
        $empty = ['url' => $url, 'final_url' => null, 'title' => '', 'markdown' => null, 'metadata' => [], 'error_code' => null];
        if (! $this->available()) {
            return array_merge($empty, ['status' => 'unavailable', 'error_code' => 'external_evidence_disabled']);
        }
        $domains = $this->domains($options['include_domains'] ?? []);
        if (! $this->sourcePolicy->accepts($url, $domains)) {
            return array_merge($empty, ['status' => 'failed', 'error_code' => 'unsafe_target_url']);
        }

        $response = $this->request('/v1/fetch', ['url' => $url, 'render_js' => 'auto']);
        if ($response['status'] !== 'ok') {
            return array_merge($empty, ['status' => $response['status'], 'error_code' => $response['error_code']]);
        }
        $data = $response['data'];
        $upstreamStatus = strtolower((string) ($data['status'] ?? ''));
        if ($upstreamStatus === 'blocked_by_challenge') {
            return array_merge($empty, ['status' => 'blocked', 'error_code' => 'blocked_by_challenge']);
        }
        $finalUrl = trim((string) ($data['final_url'] ?? $data['url'] ?? $url));
        if (! $this->sourcePolicy->accepts($finalUrl, $domains)) {
            return array_merge($empty, ['status' => 'failed', 'error_code' => 'unsafe_final_url']);
        }
        $markdown = trim((string) ($data['markdown'] ?? ''));
        $metadata = array_diff_key($data, array_flip(['markdown', 'title', 'url', 'final_url']));
        if ($markdown === '' || ($data['truncated'] ?? false) === true) {
            return array_merge($empty, ['status' => 'incomplete', 'final_url' => $finalUrl, 'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 500), 'metadata' => $metadata, 'error_code' => $markdown === '' ? 'empty_content' : 'truncated_content']);
        }

        return array_merge($empty, ['status' => 'fetched', 'final_url' => $finalUrl, 'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 500), 'markdown' => $markdown, 'metadata' => $metadata]);
    }

    private function request(string $path, array $payload): array
    {
        try {
            $request = $this->http->timeout((int) config('external_evidence.timeout_seconds', 30))
                ->connectTimeout((int) config('external_evidence.connect_timeout_seconds', 5))
                ->acceptJson()->asJson()->withToken((string) config('external_evidence.api_token'));
            $response = $this->client->post($request, (string) config('external_evidence.base_url').$path, $payload, (int) config('external_evidence.max_response_bytes', 2_000_000));
        } catch (Throwable) {
            return ['status' => 'failed', 'error_code' => 'transport_error', 'data' => []];
        }
        $error = match (true) {
            $response->status() === 401 || $response->status() === 403 => 'authentication_failed',
            $response->status() === 429 => 'rate_limited',
            $response->serverError() => 'upstream_error',
            ! $response->successful() => 'request_rejected',
            default => null,
        };
        if ($error !== null) {
            return ['status' => 'failed', 'error_code' => $error, 'data' => []];
        }
        try {
            $data = json_decode((string) $response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status' => 'failed', 'error_code' => 'invalid_json', 'data' => []];
        }
        if (! is_array($data) || array_is_list($data)) {
            return ['status' => 'failed', 'error_code' => 'invalid_response', 'data' => []];
        }

        return ['status' => 'ok', 'error_code' => null, 'data' => $data];
    }

    private function available(): bool
    {
        return (bool) config('external_evidence.enabled', false)
            && trim((string) config('external_evidence.base_url')) !== ''
            && trim((string) config('external_evidence.api_token')) !== '';
    }

    private function domains(array $domains): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($domain): string => strtolower(trim((string) $domain, " .\t\n\r\0\x0B")), $domains))));
    }
}
