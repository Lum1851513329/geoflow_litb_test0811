<?php

namespace Tests\Unit;

use App\Services\GeoFlow\Keywords\KeywordClassifier;
use App\Services\GeoFlow\Keywords\KeywordNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KeywordClassifierTest extends TestCase
{
    /** @param array{keyword_type:string,search_intent:string,brand_relation:string} $expected */
    #[DataProvider('classificationCases')]
    public function test_it_classifies_keywords_with_configured_deterministic_rules(string $value, array $expected): void
    {
        $keyword = (new KeywordNormalizer)->normalize($value, 'en', null);

        $this->assertSame($expected, (new KeywordClassifier)->classify($keyword));
    }

    /**
     * @return array<string,array{string,array{keyword_type:string,search_intent:string,brand_relation:string}}>
     */
    public static function classificationCases(): array
    {
        return [
            'brand navigation' => ['lightinthebox', ['keyword_type' => 'brand', 'search_intent' => 'navigational', 'brand_relation' => 'branded']],
            'brand category' => ['lightinthebox dresses', ['keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'brand_category']],
            'spaced brand category' => ['light in the box dresses', ['keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'brand_category']],
            'brand trust question' => ['is lightinthebox legit', ['keyword_type' => 'trust', 'search_intent' => 'informational', 'brand_relation' => 'branded']],
            'non brand category' => ['mother of the bride dresses', ['keyword_type' => 'category', 'search_intent' => 'commercial', 'brand_relation' => 'non_brand']],
            'brand coupon' => ['lightinthebox coupon', ['keyword_type' => 'brand', 'search_intent' => 'transactional', 'brand_relation' => 'branded']],
        ];
    }

    public function test_it_uses_configured_brand_variants_and_intent_phrases(): void
    {
        config([
            'keyword_intelligence.brand_variants' => ['straße shop'],
            'keyword_intelligence.intent_phrases.transactional' => ['voucher'],
        ]);

        $keyword = (new KeywordNormalizer)->normalize('STRASSE SHOP voucher', 'de', null);

        $this->assertSame([
            'keyword_type' => 'brand',
            'search_intent' => 'transactional',
            'brand_relation' => 'branded',
        ], (new KeywordClassifier)->classify($keyword));
    }
}
