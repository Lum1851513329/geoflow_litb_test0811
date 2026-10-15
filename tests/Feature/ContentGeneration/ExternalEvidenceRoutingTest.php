<?php

namespace Tests\Feature\ContentGeneration;

use App\Domain\ContentGeneration\ContentGenerationCoordinator;
use App\Models\ContentBrief;
use ReflectionMethod;
use Tests\TestCase;

final class ExternalEvidenceRoutingTest extends TestCase
{
    public function test_general_claims_use_general_external_source_scope(): void
    {
        $brief = new ContentBrief([
            'knowledge_mode' => 'general',
            'topic' => 'How to choose running shoes',
            'core_question' => 'What should shoppers compare?',
        ]);
        $coordinator = (new \ReflectionClass(ContentGenerationCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ContentGenerationCoordinator::class, 'evidenceClaims');
        $method->setAccessible(true);

        $claims = $method->invoke($coordinator, $brief, [
            'sections' => [[
                'key' => 'criteria',
                'questions_to_answer' => ['Which options and criteria matter most?'],
                'required_fact_ids' => [],
            ]],
        ]);

        $this->assertNotEmpty($claims);
        $this->assertSame(['general'], array_values(array_unique(array_column($claims, 'source_scope'))));
    }

    public function test_brand_claims_use_official_external_source_scope(): void
    {
        $brief = new ContentBrief([
            'knowledge_mode' => 'brand_auto',
            'topic' => 'AceGolf return policy',
            'core_question' => 'What is the current policy?',
        ]);
        $coordinator = (new \ReflectionClass(ContentGenerationCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ContentGenerationCoordinator::class, 'evidenceClaims');
        $method->setAccessible(true);

        $claims = $method->invoke($coordinator, $brief, ['sections' => []]);

        $this->assertSame('official', $claims[0]['source_scope']);
    }
}
