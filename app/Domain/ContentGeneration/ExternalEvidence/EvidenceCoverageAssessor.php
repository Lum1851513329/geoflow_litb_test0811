<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use App\Models\AiModel;

interface EvidenceCoverageAssessor
{
    public function assess(array $context, array $evidence, AiModel $aiModel): EvidenceCoverageAssessment;
}
