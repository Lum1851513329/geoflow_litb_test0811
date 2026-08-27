<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use App\Models\ContentGenerationRun;
use App\Models\GenerationEvidencePreparation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class EvidencePreparationCoordinator
{
    private const MAX_PAGES = 6;

    private const MAX_SEARCH_ROUNDS = 2;

    public function __construct(
        private readonly EvidenceCoverageAssessor $assessor,
        private readonly ExternalEvidencePlanner $planner,
    ) {}

    public function prepare(ContentGenerationRun $run, array $data): array
    {
        $run->loadMissing(['contentBrief', 'aiModel', 'evidence']);
        $localEvidence = $this->localEvidence($run);
        $input = $this->snapshotInput($run, $localEvidence, $data);
        $digest = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $preparation = GenerationEvidencePreparation::query()->firstOrCreate(
            ['content_generation_run_id' => $run->id],
            [
                'input_digest' => $digest,
                'input_json' => $input,
                'phase' => 'assessing_local',
                'external_evidence' => [],
                'attempt_log' => [],
                'lease_token' => $run->claim_token,
                'lease_version' => (int) $run->lease_version,
                'deadline_at' => now()->addSeconds(180),
            ],
        );
        if (! hash_equals((string) $preparation->input_digest, $digest)) {
            throw new RuntimeException('Evidence preparation input changed for an existing run.');
        }
        if ($preparation->sealed_at !== null) {
            return (array) $preparation->result_json;
        }
        if ($preparation->deadline_at?->isPast()) {
            return $this->seal($run, $preparation, $digest, $this->draftOnly([], [], 'evidence_preparation_deadline_exceeded'));
        }

        $claims = (array) ($input['claims'] ?? []);
        $external = (array) ($preparation->external_evidence ?? []);
        $assessment = $this->assessor->assess(['claims' => $claims, 'language' => $input['language']], array_merge($localEvidence, $external), $run->aiModel);
        $missing = array_merge($assessment->missingCore, $assessment->missingOptional);
        $claimsById = [];
        foreach ($claims as $claim) {
            if (is_array($claim) && trim((string) ($claim['id'] ?? '')) !== '') {
                $claimsById[(string) $claim['id']] = $claim;
            }
        }
        $missingForPlanner = array_map(
            static fn (array $claim): array => array_merge($claim, $claimsById[$claim['id']] ?? []),
            $missing,
        );

        if ($missing !== [] && count($external) < self::MAX_PAGES) {
            $collected = $this->planner->collect(
                $missingForPlanner,
                (array) ($input['reference_urls'] ?? []),
                (array) ($input['official_domains'] ?? []),
                (array) ($input['general_domains'] ?? []),
                self::MAX_PAGES - (int) $preparation->pages_fetched,
                self::MAX_SEARCH_ROUNDS - (int) $preparation->search_rounds,
                array_values(array_unique(array_merge(
                    array_filter(array_column($external, 'source_url')),
                    array_filter(array_column((array) $preparation->attempt_log, 'url')),
                ))),
            );
            $external = $this->mergeEvidence($external, $collected['evidence']);
            $this->archiveMarkdown($run, $external);
            $preparation->forceFill([
                'phase' => 'assessing_combined',
                'external_evidence' => $external,
                'attempt_log' => array_merge((array) $preparation->attempt_log, $collected['attempts']),
                'pages_fetched' => (int) $preparation->pages_fetched + (int) $collected['pages_used'],
                'search_rounds' => (int) $preparation->search_rounds + (int) $collected['search_rounds_used'],
            ])->save();
            $assessment = $this->assessor->assess(['claims' => $claims, 'language' => $input['language']], array_merge($localEvidence, $external), $run->aiModel);
        }

        $result = [
            'state' => $assessment->missingCore === [] ? 'ready' : 'draft_only',
            'evidence' => array_merge($localEvidence, $external),
            'prohibited_claims' => array_values(array_map(static fn (array $claim): string => $claim['id'], $assessment->missingOptional)),
            'missing_core' => array_values(array_map(static fn (array $claim): string => $claim['id'], $assessment->missingCore)),
            'assessment' => [
                'supported' => $assessment->supported,
                'missing_optional' => $assessment->missingOptional,
                'missing_core' => $assessment->missingCore,
            ],
        ];

        return $this->seal($run, $preparation, $digest, $result);
    }

    public function snapshotInput(ContentGenerationRun $run, array $localEvidence, array $data): array
    {
        $brief = $run->contentBrief;

        return [
            'run_id' => (int) $run->id,
            'topic' => (string) ($brief?->topic ?? ''),
            'language' => (string) ($brief?->language_code ?? 'en'),
            'claims' => array_values((array) ($data['claims'] ?? [])),
            'reference_urls' => array_values((array) ($brief?->reference_urls ?? [])),
            'official_domains' => array_values((array) ($data['official_domains'] ?? [])),
            'general_domains' => array_values((array) ($data['general_domains'] ?? [])),
            'local_evidence_hashes' => array_map(static fn (array $item): array => [
                'fact_id' => $item['fact_id'],
                'sha256' => hash('sha256', (string) $item['content']),
            ], $localEvidence),
        ];
    }

    private function localEvidence(ContentGenerationRun $run): array
    {
        return $run->evidence->map(static function ($row): array {
            $snapshot = (array) $row->evidence_snapshot;

            return [
                'fact_id' => (string) ($snapshot['fact_id'] ?? $row->evidence_key),
                'source_type' => (string) ($snapshot['source_type'] ?? 'knowledge_chunk'),
                'source_url' => $snapshot['source_url'] ?? null,
                'title' => (string) ($snapshot['title'] ?? $row->evidence_key),
                'content' => (string) ($snapshot['content'] ?? ''),
            ];
        })->filter(static fn (array $item): bool => trim($item['content']) !== '')->values()->all();
    }

    private function mergeEvidence(array $existing, array $incoming): array
    {
        $byUrl = [];
        foreach (array_merge($existing, $incoming) as $item) {
            $key = (string) ($item['source_url'] ?? $item['fact_id'] ?? '');
            if ($key !== '') {
                $byUrl[$key] = $item;
            }
        }

        return array_values($byUrl);
    }

    private function archiveMarkdown(ContentGenerationRun $run, array $evidence): void
    {
        foreach ($evidence as $item) {
            if (($item['source_type'] ?? null) !== 'external' || trim((string) ($item['content'] ?? '')) === '') {
                continue;
            }
            Storage::disk('local')->put(
                'external-evidence/run-'.$run->id.'/'.($item['content_sha256'] ?? hash('sha256', $item['content'])).'.md',
                (string) $item['content'],
            );
        }
    }

    private function seal(ContentGenerationRun $run, GenerationEvidencePreparation $preparation, string $digest, array $result): array
    {
        return DB::transaction(function () use ($run, $preparation, $digest, $result): array {
            $lockedRun = ContentGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked = GenerationEvidencePreparation::query()->lockForUpdate()->findOrFail($preparation->id);
            if ($locked->sealed_at !== null) {
                return (array) $locked->result_json;
            }
            if (! hash_equals((string) $locked->input_digest, $digest)
                || (string) $lockedRun->claim_token !== (string) $locked->lease_token
                || (int) $lockedRun->lease_version !== (int) $locked->lease_version) {
                throw new RuntimeException('Evidence preparation lease was lost before sealing.');
            }
            $locked->forceFill(['phase' => 'sealed', 'result_json' => $result, 'sealed_at' => now()])->save();

            return $result;
        });
    }

    private function draftOnly(array $evidence, array $missing, string $reason): array
    {
        return ['state' => 'draft_only', 'evidence' => $evidence, 'prohibited_claims' => [], 'missing_core' => $missing ?: [$reason], 'assessment' => []];
    }
}
