<?php

namespace App\Services\GeoFlow\Keywords;

use InvalidArgumentException;

final readonly class NormalizedKeyword
{
    public function __construct(
        public string $original,
        public string $normalized,
        public ?string $languageCode,
        public ?string $marketCode,
    ) {
        if ($normalized === '') {
            throw new InvalidArgumentException('A normalized keyword cannot be empty.');
        }
    }
}
