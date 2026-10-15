<?php

namespace Tests\Unit;

use App\Contracts\Outbound\OutboundTransport;
use App\Domain\ContentGeneration\ExternalEvidence\WigoloRestClient;
use App\Services\Outbound\ResolvedOutboundTarget;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

final class WigoloRestClientTest extends TestCase
{
    public function test_disabled_provider_makes_no_request(): void
    {
        $transport = new RecordingTransport([]);
        $client = $this->client($transport, ['enabled' => false]);

        self::assertSame('unavailable', $client->search(['return policy'])['status']);
        self::assertSame([], $transport->requests);
    }

    public function test_search_sends_evidence_only_parameters_and_normalizes_candidates(): void
    {
        $transport = new RecordingTransport([$this->jsonResponse([
            'results' => [
                ['url' => 'https://example.com/a', 'title' => 'A', 'snippet' => 'summary'],
                ['url' => 'javascript:alert(1)', 'title' => 'bad'],
            ],
            'warnings' => ['one engine failed'],
        ])]);
        $client = $this->client($transport);

        $result = $client->search(['return policy'], ['include_domains' => ['example.com']]);

        self::assertSame('ok', $result['status']);
        self::assertSame([['url' => 'https://example.com/a', 'title' => 'A']], $result['results']);
        self::assertSame('/v1/search', parse_url($transport->requests[0]['url'], PHP_URL_PATH));
        self::assertSame(['return policy'], $transport->requests[0]['data']['query']);
        self::assertSame('Bearer test-token', $transport->requests[0]['headers']['Authorization']);
        self::assertTrue($transport->requests[0]['data']['include_content']);
        self::assertSame('json', $transport->requests[0]['data']['citation_format']);
        self::assertArrayNotHasKey('format', $transport->requests[0]['data']);
    }

    public function test_fetch_keeps_markdown_and_upstream_provenance(): void
    {
        $transport = new RecordingTransport([$this->jsonResponse([
            'url' => 'https://example.com/a', 'final_url' => 'https://example.com/article',
            'title' => 'Article', 'markdown' => '# Article\n\nUseful body.',
            'cache' => ['status' => 'miss'], 'truncated' => false,
        ])]);
        $result = $this->client($transport)->fetch('https://example.com/a');

        self::assertSame('fetched', $result['status']);
        self::assertSame('https://example.com/article', $result['final_url']);
        self::assertSame('# Article\n\nUseful body.', $result['markdown']);
        self::assertSame('miss', $result['metadata']['cache']['status']);
    }

    public function test_fetch_fails_closed_for_challenge_empty_truncated_and_malformed_responses(): void
    {
        $responses = [
            $this->jsonResponse(['status' => 'blocked_by_challenge', 'url' => 'https://example.com/a']),
            $this->jsonResponse(['url' => 'https://example.com/b', 'markdown' => '']),
            $this->jsonResponse(['url' => 'https://example.com/c', 'markdown' => 'partial', 'truncated' => true]),
            new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], '{')),
        ];
        $client = $this->client(new RecordingTransport($responses));

        self::assertSame('blocked', $client->fetch('https://example.com/a')['status']);
        self::assertSame('incomplete', $client->fetch('https://example.com/b')['status']);
        self::assertSame('incomplete', $client->fetch('https://example.com/c')['status']);
        self::assertSame('failed', $client->fetch('https://example.com/d')['status']);
    }

    public function test_http_auth_and_rate_errors_are_normalized_without_returning_secrets(): void
    {
        foreach ([401 => 'authentication_failed', 429 => 'rate_limited', 500 => 'upstream_error'] as $status => $code) {
            $client = $this->client(new RecordingTransport([new Response(new PsrResponse($status, [], 'secret leak?'))]));
            $result = $client->fetch('https://example.com/a');
            self::assertSame('failed', $result['status']);
            self::assertSame($code, $result['error_code']);
            self::assertStringNotContainsString('test-token', json_encode($result));
        }
    }

    public function test_rejects_unsafe_target_before_calling_service(): void
    {
        $transport = new RecordingTransport([]);
        $result = $this->client($transport)->fetch('http://127.0.0.1/private');
        self::assertSame('failed', $result['status']);
        self::assertSame('unsafe_target_url', $result['error_code']);
        self::assertSame([], $transport->requests);
    }

    private function client(RecordingTransport $transport, array $config = []): WigoloRestClient
    {
        $this->app->instance(OutboundTransport::class, $transport);
        $defaults = [
            'enabled' => true, 'base_url' => 'https://wigolo.example.test', 'api_token' => 'test-token',
            'timeout_seconds' => 30, 'connect_timeout_seconds' => 5, 'max_response_bytes' => 2000000,
            'max_results' => 5, 'max_fetches' => 3, 'max_tokens_out' => 5000,
        ];
        $this->app['config']->set('external_evidence', array_replace($defaults, $config));

        return $this->app->make(WigoloRestClient::class);
    }

    private function jsonResponse(array $data): Response
    {
        return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($data)));
    }
}

final class RecordingTransport implements OutboundTransport
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target, array $data, int $maxBytes, bool $crossOrigin = false): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $target->url, 'data' => $data, 'headers' => $request->getOptions()['headers'] ?? []];

        return array_shift($this->responses) ?? new Response(new PsrResponse(500));
    }
}
