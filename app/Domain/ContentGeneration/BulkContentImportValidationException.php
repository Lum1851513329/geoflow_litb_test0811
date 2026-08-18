<?php

namespace App\Domain\ContentGeneration;

use RuntimeException;

final class BulkContentImportValidationException extends RuntimeException
{
    /** @param list<array{row: int, message: string}> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Bulk content import validation failed.');
    }

    /** @return list<array{row: int, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
