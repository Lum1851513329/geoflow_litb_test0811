<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\ExternalEvidence\EvidenceCitationRenderer;
use DomainException;
use PHPUnit\Framework\TestCase;

final class EvidenceCitationRendererTest extends TestCase
{
    public function test_renders_only_used_validated_sources_and_deduplicates_them(): void
    {
        $evidence = [
            ['fact_id' => 'e1', 'title' => 'Official [policy]', 'source_url' => 'https://example.com/policy', 'source_type' => 'external'],
            ['fact_id' => 'e2', 'title' => 'Same URL', 'source_url' => 'https://example.com/policy', 'source_type' => 'external'],
            ['fact_id' => 'local', 'title' => 'Local note', 'source_url' => null, 'source_type' => 'knowledge_chunk'],
        ];
        $result = (new EvidenceCitationRenderer)->render('Fact [[evidence:e1]]. Again [[evidence:e2]]. Local [[evidence:local]].', $evidence);

        self::assertStringContainsString('[Official \\[policy\\]](https://example.com/policy)', $result);
        self::assertSame(1, substr_count($result, '- [Official \\[policy\\]](https://example.com/policy)'));
        self::assertStringNotContainsString('Local note](', $result);
    }

    public function test_rejects_unknown_or_unsafe_citation_ids(): void
    {
        foreach ([
            [['fact_id' => 'e1', 'title' => 'Private', 'source_url' => 'http://127.0.0.1/a', 'source_type' => 'external'], 'e1'],
            [[], 'missing'],
        ] as [$evidence, $id]) {
            try {
                (new EvidenceCitationRenderer)->render("Claim [[evidence:{$id}]].", $evidence);
                self::fail('Expected invalid citation failure.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
