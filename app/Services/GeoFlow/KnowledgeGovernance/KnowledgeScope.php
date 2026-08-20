<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use InvalidArgumentException;

final readonly class KnowledgeScope
{
    public string $languageCode;

    public function __construct(
        public int $brandId,
        public int $siteId,
        string $languageCode,
        public ?int $directoryId = null,
        public ?int $keywordClusterId = null,
    ) {
        if ($brandId <= 0 || $siteId <= 0) {
            throw new InvalidArgumentException('Knowledge scope requires positive brand and site IDs.');
        }

        $languageCode = $this->canonicalLanguageTag($languageCode);
        if ($languageCode === null) {
            throw new InvalidArgumentException('Knowledge scope requires a valid BCP 47-compatible language code.');
        }

        if ($directoryId !== null && $directoryId <= 0) {
            throw new InvalidArgumentException('Knowledge scope directory ID must be positive.');
        }

        if ($keywordClusterId !== null && $keywordClusterId <= 0) {
            throw new InvalidArgumentException('Knowledge scope keyword cluster ID must be positive.');
        }

        $this->languageCode = $languageCode;
    }

    private function canonicalLanguageTag(string $value): ?string
    {
        $parts = explode('-', str_replace('_', '-', trim($value)));
        if ($parts === [] || in_array('', $parts, true)) {
            return null;
        }

        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z0-9]{1,8}$/', $part) !== 1) {
                return null;
            }
        }

        if (strtolower($parts[0]) === 'x') {
            return count($parts) > 1
                ? implode('-', array_map('strtolower', $parts))
                : null;
        }

        if (preg_match('/^[A-Za-z]{2,3}$/', $parts[0]) !== 1) {
            return null;
        }

        $normalized = [strtolower($parts[0])];
        $partCount = count($parts);
        $index = 1;

        for ($extlangCount = 0; $extlangCount < 3 && $index < $partCount; $extlangCount++) {
            if (preg_match('/^[A-Za-z]{3}$/', $parts[$index]) !== 1) {
                break;
            }
            $normalized[] = strtolower($parts[$index]);
            $index++;
        }

        if ($index < $partCount && preg_match('/^[A-Za-z]{4}$/', $parts[$index]) === 1) {
            $normalized[] = ucfirst(strtolower($parts[$index]));
            $index++;
        }

        if ($index < $partCount
            && (preg_match('/^[A-Za-z]{2}$/', $parts[$index]) === 1 || preg_match('/^\d{3}$/', $parts[$index]) === 1)) {
            $normalized[] = strtoupper($parts[$index]);
            $index++;
        }

        $variants = [];
        while ($index < $partCount && $this->isVariantSubtag($parts[$index])) {
            $variant = strtolower($parts[$index]);
            if (isset($variants[$variant])) {
                return null;
            }
            $variants[$variant] = true;
            $normalized[] = $variant;
            $index++;
        }

        $extensionSingletons = [];
        while ($index < $partCount && $this->isExtensionSingleton($parts[$index])) {
            $singleton = strtolower($parts[$index]);
            if (isset($extensionSingletons[$singleton])) {
                return null;
            }
            $extensionSingletons[$singleton] = true;
            $normalized[] = $singleton;
            $index++;

            $extensionStart = $index;
            while ($index < $partCount && preg_match('/^[A-Za-z0-9]{2,8}$/', $parts[$index]) === 1) {
                $normalized[] = strtolower($parts[$index]);
                $index++;
            }
            if ($extensionStart === $index) {
                return null;
            }
        }

        if ($index < $partCount && strtolower($parts[$index]) === 'x') {
            $normalized[] = 'x';
            $index++;
            if ($index === $partCount) {
                return null;
            }

            while ($index < $partCount) {
                $normalized[] = strtolower($parts[$index]);
                $index++;
            }
        }

        return $index === $partCount ? implode('-', $normalized) : null;
    }

    private function isVariantSubtag(string $part): bool
    {
        return preg_match('/^[A-Za-z0-9]{5,8}$/', $part) === 1
            || preg_match('/^\d[A-Za-z0-9]{3}$/', $part) === 1;
    }

    private function isExtensionSingleton(string $part): bool
    {
        return strlen($part) === 1
            && ctype_alnum($part)
            && strtolower($part) !== 'x';
    }
}
