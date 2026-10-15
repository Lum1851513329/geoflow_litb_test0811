<?php

namespace Tests\Unit\Semrush;

use App\Services\GeoFlow\Semrush\SemrushTopicClassifier;
use Tests\TestCase;

final class SemrushTopicClassifierTest extends TestCase
{
    public function test_classifies_unbranded_shopping_advice_as_general(): void
    {
        $result = $this->app->make(SemrushTopicClassifier::class)->classify([
            'topic' => 'How to choose running shoes',
            'primary_keyword' => 'running shoes buying guide',
            'intents' => ['informational' => 70, 'commercial' => 20],
        ], $this->profile('acegolf', 'AceGolf'));

        $this->assertSame('general', $result['knowledge_mode']);
        $this->assertSame('shopping_advice', $result['content_type']);
        $this->assertFalse($result['directory_required']);
    }

    public function test_classifies_brand_policy_as_brand(): void
    {
        $result = $this->app->make(SemrushTopicClassifier::class)->classify([
            'topic' => 'AceGolf shipping and return policy',
            'primary_keyword' => 'acegolf returns',
            'intents' => ['informational' => 60, 'navigational' => 30],
        ], $this->profile('acegolf', 'AceGolf'));

        $this->assertSame('brand', $result['knowledge_mode']);
        $this->assertSame('brand_policy', $result['content_type']);
        $this->assertTrue($result['directory_required']);
    }

    public function test_classifies_brand_comparison_with_general_decision_language_as_mixed(): void
    {
        $result = $this->app->make(SemrushTopicClassifier::class)->classify([
            'topic' => 'AceGolf irons vs other golf clubs: which should you choose?',
            'primary_keyword' => 'acegolf irons comparison',
            'intents' => ['commercial' => 55, 'informational' => 30],
        ], $this->profile('acegolf', 'AceGolf'));

        $this->assertSame('mixed', $result['knowledge_mode']);
        $this->assertSame('comparison', $result['content_type']);
        $this->assertContains('brand_entity', $result['reason_codes']);
        $this->assertContains('decision_guidance', $result['reason_codes']);
    }

    /** @return array{brand: array{key:string,name:string},aliases:list<string>} */
    private function profile(string $key, string $name): array
    {
        return ['brand' => ['key' => $key, 'name' => $name], 'aliases' => [$key, strtolower($name)]];
    }
}
