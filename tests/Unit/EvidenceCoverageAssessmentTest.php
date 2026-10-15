<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\ExternalEvidence\EvidenceCoverageAssessment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceCoverageAssessmentTest extends TestCase
{
    public function test_separates_supported_missing_optional_and_missing_core_claims(): void
    {
        $result = EvidenceCoverageAssessment::fromArray(['claims' => [
            ['id' => 'material', 'question' => 'What material?', 'core' => true, 'status' => 'supported', 'evidence_ids' => ['e1'], 'quotes' => [['evidence_id' => 'e1', 'text' => 'Made from polyester.']], 'reason' => 'direct'],
            ['id' => 'care-tip', 'question' => 'Any extra tip?', 'core' => false, 'status' => 'missing', 'evidence_ids' => [], 'quotes' => [], 'reason' => 'not supplied'],
            ['id' => 'price', 'question' => 'Current price?', 'core' => true, 'status' => 'conflict', 'evidence_ids' => ['e1'], 'quotes' => [['evidence_id' => 'e1', 'text' => 'Made from polyester.']], 'reason' => 'no price'],
        ]], [['fact_id' => 'e1', 'content' => 'This item is Made from polyester.']], ['material', 'price']);

        self::assertSame(['material'], array_column($result->supported, 'id'));
        self::assertSame(['care-tip'], array_column($result->missingOptional, 'id'));
        self::assertSame(['price'], array_column($result->missingCore, 'id'));
    }

    #[DataProvider('invalidAssessments')]
    public function test_rejects_fabricated_or_weakened_support(array $input, array $required, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        EvidenceCoverageAssessment::fromArray($input, [['fact_id' => 'e1', 'content' => 'Verified exact text.']], $required);
    }

    public static function invalidAssessments(): array
    {
        return [
            'unknown evidence' => [['claims' => [['id' => 'c', 'question' => 'Q', 'core' => true, 'status' => 'supported', 'evidence_ids' => ['fake'], 'quotes' => [['evidence_id' => 'fake', 'text' => 'x']], 'reason' => 'x']]], ['c'], 'unknown evidence'],
            'nonverbatim quote' => [['claims' => [['id' => 'c', 'question' => 'Q', 'core' => true, 'status' => 'supported', 'evidence_ids' => ['e1'], 'quotes' => [['evidence_id' => 'e1', 'text' => 'invented']], 'reason' => 'x']]], ['c'], 'verbatim'],
            'downgraded required' => [['claims' => [['id' => 'c', 'question' => 'Q', 'core' => false, 'status' => 'missing', 'evidence_ids' => [], 'quotes' => [], 'reason' => 'x']]], ['c'], 'must remain core'],
            'missing required claim' => [['claims' => []], ['c'], 'required claim'],
            'duplicate claim' => [['claims' => [
                ['id' => 'c', 'question' => 'Q', 'core' => true, 'status' => 'missing', 'evidence_ids' => [], 'quotes' => [], 'reason' => 'x'],
                ['id' => 'c', 'question' => 'Q', 'core' => true, 'status' => 'missing', 'evidence_ids' => [], 'quotes' => [], 'reason' => 'x'],
            ]], ['c'], 'duplicate'],
        ];
    }

    public function test_rejects_missing_or_unknown_claims_when_the_contract_is_frozen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('every requested claim');

        EvidenceCoverageAssessment::fromArray(['claims' => [[
            'id' => 'unexpected', 'question' => 'Q', 'core' => false, 'status' => 'missing',
            'evidence_ids' => [], 'quotes' => [], 'reason' => 'x',
        ]]], [], [], ['expected']);
    }
}
