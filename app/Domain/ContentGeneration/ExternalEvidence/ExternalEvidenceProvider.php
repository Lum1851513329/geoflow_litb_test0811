<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

interface ExternalEvidenceProvider
{
    public function search(array $queries, array $options = []): array;

    public function fetch(string $url, array $options = []): array;
}
