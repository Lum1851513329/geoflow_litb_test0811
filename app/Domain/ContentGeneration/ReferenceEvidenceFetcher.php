<?php

namespace App\Domain\ContentGeneration;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Throwable;

final class ReferenceEvidenceFetcher
{
    private const MAX_RESPONSE_BYTES = 2_000_000;

    private const MAX_CONTENT_CHARS = 8_000;

    public function __construct(
        private readonly SafeOutboundHttpClient $client,
        private readonly Factory $http,
    ) {}

    /**
     * @param  list<string>  $urls
     * @return list<array{url: string, status: 'fetched'|'failed', content: ?string}>
     */
    public function fetch(array $urls): array
    {
        $results = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $results[] = $this->fetchOne($url);
        }

        return $results;
    }

    /** @return array{url: string, status: 'fetched'|'failed', content: ?string} */
    private function fetchOne(string $url): array
    {
        try {
            $request = $this->http->timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'GEOFlow Reference Evidence Fetcher/1.0',
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.5',
                ]);
            $response = $this->client->get($request, $url, self::MAX_RESPONSE_BYTES);
        } catch (Throwable) {
            return ['url' => $url, 'status' => 'failed', 'content' => null];
        }

        if ($response->status() !== 200) {
            return ['url' => $url, 'status' => 'failed', 'content' => null];
        }

        $text = $this->extractReadableText((string) $response->body());
        if (trim($text) === '') {
            return ['url' => $url, 'status' => 'failed', 'content' => null];
        }

        return ['url' => $url, 'status' => 'fetched', 'content' => mb_substr($text, 0, self::MAX_CONTENT_CHARS)];
    }

    private function extractReadableText(string $html): string
    {
        $html = preg_replace('#<(script|style|nav|header|footer|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
