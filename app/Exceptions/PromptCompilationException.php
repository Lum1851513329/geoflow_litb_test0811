<?php

namespace App\Exceptions;

use RuntimeException;

final class PromptCompilationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly string $codeName = 'prompt_compilation_failed',
        public readonly array $errors = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
