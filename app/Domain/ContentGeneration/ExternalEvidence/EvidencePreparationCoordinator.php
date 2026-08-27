<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use App\Models\ContentGenerationRun;
use App\Models\GenerationEvidencePreparation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class EvidencePreparationCoordinator
{
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
                'deadline_at' => now()->addSeconds((int) config('external_evidence.budget_seconds', 180)),
            ],
        );
        if (! hash_equals((string) $preparation->input_digest, $digest)) {
            throw new RuntimeException('Evidence preparation input changed for an existing run.');
        }
        if ($preparation->sealed_at !== null) {
            return (array) $preparation->result_json;
        }
        $preparation = $this->acquireLease($run, $preparation);
        if ($preparation->deadline_at?->isPast()) {
            return $this->seal($run, $preparation, $digest, $this->draftOnly([], [], 'evidence_preparation_deadline_exceeded'));
        }

        $claims = (array) ($input['claims'] ?? []);
        $external = (array) ($preparation->external_evidence ?? []);
        try {
            $assessment = $this->assessor->assess(['claims' => $claims, 'language' => $input['language']], array_merge($localEvidence, $external), $run->aiModel);
        } catch (\Throwable $exception) {
            $this->recordAssessmentFailure($preparation, $exception);

            return $this->seal($run, $preparation, $digest, $this->draftOnly(array_merge($localEvidence, $external), [], 'evidence_assessment_invalid'));
        }
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

        $maxPages = (int) config('external_evidence.max_pages', 6);
        $maxSearchRounds = (int) config('external_evidence.max_search_rounds', 2);
        if ($missing !== [] && count($external) < $maxPages) {
            $collected = $this->planner->collect(
                $missingForPlanner,
                (array) ($input['reference_urls'] ?? []),
                (array) ($input['official_domains'] ?? []),
                (array) ($input['general_domains'] ?? []),
                $maxPages - (int) $preparation->pages_fetched,
                $maxSearchRounds - (int) $preparation->search_rounds,
                array_values(array_unique(array_merge(
                    array_filter(array_column($external, 'source_url')),
                    array_filter(array_column((array) $preparation->attempt_log, 'url')),
                ))),
                fn (array $event) => $this->persistPlannerEvent((int) $preparation->id, $event, (string) $run->claim_token, (int) $run->lease_version),
                $preparation->deadline_at?->timestamp,
            );
            $preparation->refresh();
            $external = $this->mergeEvidence((array) $preparation->external_evidence, $collected['evidence']);
            $this->archiveMarkdown($run, $external);
            $preparation->forceFill([
                'phase' => 'assessing_combined',
                'external_evidence' => $external,
            ])->save();
            try {
                $assessment = $this->assessor->assess(['claims' => $claims, 'language' => $input['language']], array_merge($localEvidence, $external), $run->aiModel);
            } catch (\Throwable $exception) {
                $this->recordAssessmentFailure($preparation, $exception);

                return $this->seal($run, $preparation, $digest, $this->draftOnly(array_merge($localEvidence, $external), [], 'evidence_assessment_invalid'));
            }
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
                || (string) $locked->lease_token !== (string) $run->claim_token
                || (int) $locked->lease_version !== (int) $run->lease_version
                || (string) $lockedRun->claim_token !== (string) $locked->lease_token
                || (int) $lockedRun->lease_version !== (int) $locked->lease_version) {
                throw new RuntimeException('Evidence preparation lease was lost before sealing.');
            }
            $locked->forceFill(['phase' => 'sealed', 'result_json' => $result, 'sealed_at' => now()])->save();

            return $result;
        });
    }

    private function acquireLease(ContentGenerationRun $run, GenerationEvidencePreparation $preparation): GenerationEvidencePreparation
    {
        return DB::transaction(function () use ($run, $preparation): GenerationEvidencePreparation {
            $lockedRun = ContentGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked = GenerationEvidencePreparation::query()->lockForUpdate()->findOrFail($preparation->id);
            if ($locked->sealed_at !== null) {
                return $locked;
            }
            if ((string) $lockedRun->claim_token !== (string) $run->claim_token
                || (int) $lockedRun->lease_version !== (int) $run->lease_version) {
                throw new RuntimeException('Generation lease was lost before evidence preparation.');
            }
            if ((string) $locked->lease_token !== (string) $run->claim_token
                || (int) $locked->lease_version !== (int) $run->lease_version) {
                $locked->forceFill([
                    'lease_token' => $run->claim_token,
                    'lease_version' => (int) $run->lease_version,
                    'phase' => 'retrying',
                ])->save();
            }

            return $locked->fresh();
        });
    }

    private function recordAssessmentFailure(GenerationEvidencePreparation $preparation, \Throwable $exception): void
    {
        $preparation->forceFill([
            'phase' => 'assessment_failed',
            'attempt_log' => array_merge((array) $preparation->attempt_log, [[
                'type' => 'assessment',
                'status' => 'failed',
                'error_class' => $exception::class,
            ]]),
        ])->save();
    }

    private function persistPlannerEvent(int $preparationId, array $event, string $leaseToken, int $leaseVersion): void
    {
        DB::transaction(function () use ($preparationId, $event, $leaseToken, $leaseVersion): void {
            $preparation = GenerationEvidencePreparation::query()->lockForUpdate()->findOrFail($preparationId);
            if ($preparation->sealed_at !== null) {
                throw new RuntimeException('Cannot append to a sealed evidence preparation.');
            }
            if ((string) $preparation->lease_token !== $leaseToken || (int) $preparation->lease_version !== $leaseVersion) {
                throw new RuntimeException('Evidence preparation lease was lost during an external call.');
            }
            $document = is_array($event['document'] ?? null) ? $event['document'] : null;
            unset($event['document']);
            $changes = ['attempt_log' => array_merge((array) $preparation->attempt_log, [$event])];
            if (($event['phase'] ?? null) === 'started' && ($event['type'] ?? null) === 'fetch') {
                $changes['pages_fetched'] = (int) $preparation->pages_fetched + 1;
            }
            if (($event['phase'] ?? null) === 'started' && ($event['type'] ?? null) === 'search') {
                $changes['search_rounds'] = (int) $preparation->search_rounds + 1;
            }
            if ($document !== null) {
                $changes['external_evidence'] = $this->mergeEvidence((array) $preparation->external_evidence, [$document]);
            }
            $preparation->forceFill($changes)->save();
        });
    }

    private function draftOnly(array $evidence, array $missing, string $reason): array
    {
        return ['state' => 'draft_only', 'evidence' => $evidence, 'prohibited_claims' => [], 'missing_core' => $missing ?: [$reason], 'assessment' => []];
    }
}
