<?php

namespace Tests\Unit;

use App\Services\GeoFlow\KnowledgeGovernance\GovernedKnowledgeContentNormalizer;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GovernedKnowledgeContentNormalizerTest extends TestCase
{
    /** @throws JsonException */
    #[Test]
    public function it_matches_the_shared_python_canonicalization_vectors(): void
    {
        $fixture = file_get_contents(base_path('tools/knowledge_corpus/tests/fixtures/canonicalization_vectors.json'));
        $this->assertIsString($fixture);
        $vectors = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);
        $normalizer = $this->app->make(GovernedKnowledgeContentNormalizer::class);

        foreach ($vectors as $vector) {
            $canonical = $normalizer->normalize($vector['raw']);

            $this->assertSame($vector['canonical'], $canonical, $vector['name']);
            $this->assertSame($vector['sha256'], hash('sha256', $canonical), $vector['name']);
            $this->assertSame($vector['sha256'], $normalizer->hash($vector['raw']), $vector['name']);
        }
    }
}
