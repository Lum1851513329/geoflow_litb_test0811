<?php

namespace Tests\Unit;

use App\Services\GeoFlow\Keywords\KeywordNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class KeywordNormalizerTest extends TestCase
{
    public function test_it_normalizes_whitespace_case_and_locale_codes(): void
    {
        $normalized = (new KeywordNormalizer)->normalize('  Light in the BOX   Dresses ', 'EN', 'us');

        $this->assertSame('  Light in the BOX   Dresses ', $normalized->original);
        $this->assertSame('light in the box dresses', $normalized->normalized);
        $this->assertSame('en', $normalized->languageCode);
        $this->assertSame('US', $normalized->marketCode);
    }

    public function test_it_normalizes_unicode_to_canonical_composed_form(): void
    {
        $normalized = (new KeywordNormalizer)->normalize("Dre\u{0301}sses", 'en', null);

        $this->assertSame('drésses', $normalized->normalized);
    }

    public function test_it_uses_full_unicode_case_folding(): void
    {
        $normalized = (new KeywordNormalizer)->normalize('Straße', 'de', null);

        $this->assertSame('strasse', $normalized->normalized);
    }

    public function test_it_preserves_a_null_language_without_inferring_a_locale(): void
    {
        $normalized = (new KeywordNormalizer)->normalize('dresses', null, null);

        $this->assertNull($normalized->languageCode);
    }

    public function test_it_preserves_brand_spelling_words_without_rewriting_them(): void
    {
        $normalized = (new KeywordNormalizer)->normalize('Light in the Box Dresses', 'en', null);

        $this->assertSame('light in the box dresses', $normalized->normalized);
    }

    public function test_it_does_not_merge_singular_and_plural_keywords(): void
    {
        $normalizer = new KeywordNormalizer;

        $this->assertSame('dress', $normalizer->normalize('Dress', 'en', null)->normalized);
        $this->assertSame('dresses', $normalizer->normalize('Dresses', 'en', null)->normalized);
    }

    public function test_it_rejects_blank_keywords(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new KeywordNormalizer)->normalize(" \t\n ", 'en', null);
    }

    public function test_it_rejects_keywords_containing_only_unicode_whitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new KeywordNormalizer)->normalize("\u{00A0}", 'en', null);
    }

    public function test_it_rejects_language_and_market_codes_in_unsupported_formats(): void
    {
        $normalizer = new KeywordNormalizer;

        try {
            $normalizer->normalize('dresses', 'english', null);
            $this->fail('Expected an invalid language code to be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $normalizer->normalize('dresses', 'en', 'USA');
    }
}
