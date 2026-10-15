<?php

namespace App\Domain\ContentGeneration;

final readonly class QualityIssue
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public function __construct(
        public string $code,
        public string $severity,
        public ?string $sectionKey,
        public string $message,
        public string $repairInstruction,
    ) {}

    /** @return array{code: string, severity: string, section_key: string|null, message: string, repair_instruction: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'section_key' => $this->sectionKey,
            'message' => $this->message,
            'repair_instruction' => $this->repairInstruction,
        ];
    }
}
