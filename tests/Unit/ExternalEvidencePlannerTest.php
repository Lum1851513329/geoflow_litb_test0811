<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\ExternalEvidence\ExternalEvidencePlanner;
use App\Domain\ContentGeneration\ExternalEvidence\ExternalEvidenceProvider;
use App\Domain\ContentGeneration\ExternalEvidence\ExternalSourcePolicy;
use Tests\TestCase;

final class ExternalEvidencePlannerTest extends TestCase
{
    public function test_fetches_known_allowed_urls_before_search_and_deduplicates_final_urls(): void
    {
        $provider = new RecordingEvidenceProvider;
        $planner = new ExternalEvidencePlanner($provider, new ExternalSourcePolicy);

        $result = $planner->collect(
            [['id' => 'care', 'question' => 'How should it be washed?']],
            ['https://www.lightinthebox.com/care'],
            ['lightinthebox.com'],
            ['example.org'],
        );

        self::assertSame('fetch:https://www.lightinthebox.com/care', $provider->calls[0]);
        self::assertStringStartsWith('search:', $provider->calls[1]);
        self::assertCount(2, $result['evidence']);
        self::assertSame(2, $result['pages_used']);
    }

    public function test_rejects_wrong_brand_urls_and_does_nothing_without_explicit_domains(): void
    {
        $provider = new RecordingEvidenceProvider;
        $planner = new ExternalEvidencePlanner($provider, new ExternalSourcePolicy);

        $empty = $planner->collect([['question' => 'Q']], ['https://evil.example/a'], [], []);
        self::assertSame([], $provider->calls);
        self::assertSame([], $empty['evidence']);

        $planner->collect([], ['https://lightinthebox.com.evil.example/a'], ['lightinthebox.com'], []);
        self::assertSame([], $provider->calls);
    }
}

final class RecordingEvidenceProvider implements ExternalEvidenceProvider
{
    public array $calls = [];

    public function search(array $queries, array $options = []): array
    {
        $this->calls[] = 'search:'.implode('|', $queries);

        return ['status' => 'ok', 'results' => [
            ['url' => 'https://example.org/guide'],
            ['url' => 'https://www.lightinthebox.com/care'],
        ]];
    }

    public function fetch(string $url, array $options = []): array
    {
        $this->calls[] = 'fetch:'.$url;

        return [
            'status' => 'fetched',
            'final_url' => $url,
            'title' => 'Verified page',
            'markdown' => 'Verified source content for care instructions.',
            'error_code' => null,
        ];
    }
}
