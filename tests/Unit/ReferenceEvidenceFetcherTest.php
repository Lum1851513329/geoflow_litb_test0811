<?php

namespace Tests\Unit;

use App\Contracts\Outbound\OutboundTransport;
use App\Domain\ContentGeneration\ReferenceEvidenceFetcher;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\SafeOutboundHttpClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

final class ReferenceEvidenceFetcherTest extends TestCase
{
    public function test_extracts_readable_text_from_a_successfully_fetched_url(): void
    {
        $fetcher = $this->fetcherWithResponses([
            new Response(new PsrResponse(200, ['Content-Type' => 'text/html'], '<html><body><nav>Skip</nav><article><p>Real content here.</p></article></body></html>')),
        ]);

        $results = $fetcher->fetch(['https://example.com/a']);

        $this->assertSame('fetched', $results[0]['status']);
        $this->assertStringContainsString('Real content here.', $results[0]['content']);
        $this->assertStringNotContainsString('Skip', $results[0]['content']);
    }

    public function test_marks_a_url_failed_when_the_client_throws(): void
    {
        $fetcher = $this->fetcherWithTransport(new class implements OutboundTransport
        {
            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                throw new \RuntimeException('blocked');
            }
        });

        $results = $fetcher->fetch(['https://example.com/blocked']);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertNull($results[0]['content']);
        $this->assertSame('https://example.com/blocked', $results[0]['url']);
    }

    public function test_marks_a_non_200_response_failed(): void
    {
        $fetcher = $this->fetcherWithResponses([
            new Response(new PsrResponse(404, [], 'not found')),
        ]);

        $results = $fetcher->fetch(['https://example.com/missing']);

        $this->assertSame('failed', $results[0]['status']);
    }

    /** @param  list<Response>  $responses */
    private function fetcherWithResponses(array $responses): ReferenceEvidenceFetcher
    {
        return $this->fetcherWithTransport(new class($responses) implements OutboundTransport
        {
            /** @param  list<Response>  $responses */
            public function __construct(private array $responses) {}

            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                return array_shift($this->responses) ?? new Response(new PsrResponse(200, [], ''));
            }
        });
    }

    private function fetcherWithTransport(OutboundTransport $transport): ReferenceEvidenceFetcher
    {
        // SafeOutboundHttpClient is final (cannot be mocked via createMock) and its
        // "public internet only" resolveTarget()/privateTargetAllowed() checks call the
        // config() helper, so it must run through the real Laravel container. We swap the
        // OutboundTransport binding for a fake so no real network call happens, mirroring
        // tests/Unit/SafeOutboundHttpClientTest.php's RecordingOutboundTransport pattern.
        // HostResolver is already globally faked in tests/TestCase.php.
        $this->app->instance(OutboundTransport::class, $transport);

        return new ReferenceEvidenceFetcher(
            $this->app->make(SafeOutboundHttpClient::class),
            $this->app->make(Factory::class),
        );
    }
}
