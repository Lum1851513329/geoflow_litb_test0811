<?php

namespace App\Domain\ContentGeneration;

use DomainException;

final class GenerationAdoptionIntegrityException extends DomainException
{
    public static function adoptedRunMissingArticle(int $runId): self
    {
        return new self("Adopted generation run {$runId} has no linked Article.");
    }
}
