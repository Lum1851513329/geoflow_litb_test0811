<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use InvalidArgumentException;

final class EvidenceCoverageAssessment
{
    public function __construct(
        public readonly array $supported,
        public readonly array $missingOptional,
        public readonly array $missingCore,
    ) {}

    public static function fromArray(array $payload, array $evidence, array $requiredClaimIds): self
    {
        if (array_keys($payload) !== ['claims'] || ! is_array($payload['claims'])) {
            throw new InvalidArgumentException('Assessment must contain exactly one claims array.');
        }

        $evidenceById = [];
        foreach ($evidence as $item) {
            $id = trim((string) ($item['fact_id'] ?? ''));
            if ($id !== '') {
                $evidenceById[$id] = (string) ($item['content'] ?? '');
            }
        }

        $required = array_fill_keys(array_map('strval', $requiredClaimIds), true);
        $seen = [];
        $supported = [];
        $missingOptional = [];
        $missingCore = [];

        foreach ($payload['claims'] as $claim) {
            if (! is_array($claim)) {
                throw new InvalidArgumentException('Every claim must be an object.');
            }
            $id = trim((string) ($claim['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                throw new InvalidArgumentException($id === '' ? 'Claim id is required.' : "duplicate claim: {$id}");
            }
            $seen[$id] = true;
            $core = ($claim['core'] ?? null) === true;
            if (isset($required[$id]) && ! $core) {
                throw new InvalidArgumentException("Required claim {$id} must remain core.");
            }

            $status = (string) ($claim['status'] ?? '');
            if (! in_array($status, ['supported', 'missing', 'conflict'], true)) {
                throw new InvalidArgumentException("Invalid status for claim {$id}.");
            }
            $ids = array_values(array_unique(array_map('strval', is_array($claim['evidence_ids'] ?? null) ? $claim['evidence_ids'] : [])));
            $quotes = is_array($claim['quotes'] ?? null) ? $claim['quotes'] : [];
            foreach ($ids as $evidenceId) {
                if (! array_key_exists($evidenceId, $evidenceById)) {
                    throw new InvalidArgumentException("Claim {$id} references unknown evidence {$evidenceId}.");
                }
            }
            foreach ($quotes as $quote) {
                $evidenceId = (string) ($quote['evidence_id'] ?? '');
                $text = trim((string) ($quote['text'] ?? ''));
                if (! in_array($evidenceId, $ids, true) || ! array_key_exists($evidenceId, $evidenceById)) {
                    throw new InvalidArgumentException("Claim {$id} quote references unknown evidence.");
                }
                if ($text === '' || ! str_contains($evidenceById[$evidenceId], $text)) {
                    throw new InvalidArgumentException("Claim {$id} quote must be verbatim evidence text.");
                }
            }
            if ($status === 'supported' && ($ids === [] || $quotes === [])) {
                throw new InvalidArgumentException("Supported claim {$id} needs cited evidence and a verbatim quote.");
            }

            $normalized = [
                'id' => $id,
                'question' => trim((string) ($claim['question'] ?? '')),
                'core' => $core,
                'status' => $status,
                'evidence_ids' => $ids,
                'quotes' => $quotes,
                'reason' => trim((string) ($claim['reason'] ?? '')),
            ];
            if ($status === 'supported') {
                $supported[] = $normalized;
            } elseif ($core) {
                $missingCore[] = $normalized;
            } else {
                $missingOptional[] = $normalized;
            }
        }

        foreach (array_keys($required) as $id) {
            if (! isset($seen[$id])) {
                throw new InvalidArgumentException("Missing required claim {$id}.");
            }
        }

        return new self($supported, $missingOptional, $missingCore);
    }
}
