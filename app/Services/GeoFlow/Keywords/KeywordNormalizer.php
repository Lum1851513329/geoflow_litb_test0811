<?php

namespace App\Services\GeoFlow\Keywords;

use InvalidArgumentException;
use Normalizer;

final class KeywordNormalizer
{
    public function normalize(string $value, ?string $language, ?string $market): NormalizedKeyword
    {
        $unicodeNormalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($unicodeNormalized === false) {
            throw new InvalidArgumentException('The keyword cannot be Unicode normalized.');
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($unicodeNormalized));
        if ($normalized === null) {
            throw new InvalidArgumentException('The keyword contains invalid UTF-8.');
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');

        return new NormalizedKeyword(
            original: $value,
            normalized: $normalized,
            languageCode: $this->normalizeLanguageCode($language),
            marketCode: $this->normalizeMarketCode($market),
        );
    }

    private function normalizeLanguageCode(?string $language): string
    {
        if ($language === null) {
            return 'en';
        }

        $language = trim($language);
        if (! preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language)) {
            throw new InvalidArgumentException('The language code must be an ISO language code or BCP 47 tag.');
        }

        $subtags = explode('-', $language);
        $subtags[0] = strtolower($subtags[0]);

        foreach (array_slice($subtags, 1) as $index => $subtag) {
            $subtags[$index + 1] = strlen($subtag) === 4
                ? ucfirst(strtolower($subtag))
                : strtoupper($subtag);
        }

        return implode('-', $subtags);
    }

    private function normalizeMarketCode(?string $market): ?string
    {
        if ($market === null) {
            return null;
        }

        $market = trim($market);
        if (! preg_match('/^[A-Za-z]{2}$/', $market)) {
            throw new InvalidArgumentException('The market code must be an ISO 3166-1 alpha-2 code.');
        }

        return strtoupper($market);
    }
}
