<?php

namespace App\Domain\ContentStrategy;

use App\Models\Admin;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ContentStrategyService
{
    /** @var list<string> */
    private const MODEL_EXTENSION_FIELDS = [
        'secondary_intents',
        'success_criteria',
        'risks',
        'evidence_gaps',
        'monitoring_questions',
    ];

    public function __construct(private readonly BlueprintValidator $blueprintValidator) {}

    public function suggest(ContentBrief $brief): StrategySuggestion
    {
        $brief->loadMissing([
            'keywordCluster.primaryKeyword',
            'keywordCluster.keywords',
            'primaryKeyword',
        ]);

        $cluster = $brief->keywordCluster;
        $primaryKeyword = $cluster?->primaryKeyword ?? $brief->primaryKeyword;
        $intent = $this->nonEmptyString($cluster?->search_intent)
            ?? $this->nonEmptyString($primaryKeyword?->search_intent)
            ?? 'informational';
        $strategy = [
            'audience' => $brief->audience,
            'core_question' => $brief->core_question,
            'primary_intent' => $intent,
            'secondary_intents' => [],
            'decision_stage' => $this->decisionStage($intent),
            'content_type' => $this->nonEmptyString($cluster?->content_type) ?? $brief->content_type,
            'content_goal' => $brief->business_goal,
            'cta_strength' => $brief->cta_strength,
            'target_url' => $this->nonEmptyString($cluster?->target_url) ?? $brief->target_url,
            'target_keyword_cluster' => $this->clusterSnapshot($cluster),
            'questions_to_answer' => $this->questions($brief, $cluster),
            'risks' => $this->nonEmptyValues($brief->prohibited_information),
            'evidence_gaps' => [],
        ];
        $evidence = $this->evidence($cluster, $primaryKeyword);
        $confidence = $cluster !== null ? 0.9 : ($primaryKeyword !== null ? 0.75 : 0.55);

        $extension = $this->runModelExtension($brief, $strategy, $evidence);
        if ($extension !== null) {
            if (isset($extension['strategy']) && ! is_array($extension['strategy'])) {
                throw new InvalidArgumentException('content_strategy.model_extension.strategy_invalid');
            }
            if (isset($extension['confidence']) && ! is_numeric($extension['confidence'])) {
                throw new InvalidArgumentException('content_strategy.model_extension.confidence_invalid');
            }
            if (isset($extension['evidence']) && ! is_array($extension['evidence'])) {
                throw new InvalidArgumentException('content_strategy.model_extension.evidence_invalid');
            }

            $strategy = array_replace($strategy, array_intersect_key(
                $extension['strategy'] ?? [],
                array_flip(self::MODEL_EXTENSION_FIELDS),
            ));
            $confidence = isset($extension['confidence']) ? (float) $extension['confidence'] : $confidence;
            $evidence = [...$evidence, ...($extension['evidence'] ?? [])];
        }

        return new StrategySuggestion($strategy, $confidence, $evidence, $extension);
    }

    /** @param array<string, mixed> $strategy */
    public function confirmStrategy(ContentBrief $brief, array $strategy, Admin $actor, string $reason): ContentStrategyVersion
    {
        return DB::transaction(function () use ($brief, $strategy, $actor, $reason): ContentStrategyVersion {
            $lockedBrief = ContentBrief::query()->whereKey($brief->getKey())->lockForUpdate()->firstOrFail();
            $suggestion = $this->suggest($lockedBrief);
            $finalClassification = array_replace($suggestion->strategy, $strategy);
            $operatorCorrection = $this->corrections($suggestion->strategy, $strategy);
            $version = (int) $lockedBrief->strategyVersions()->max('version') + 1;

            $created = ContentStrategyVersion::forceCreate([
                'content_brief_id' => $lockedBrief->id,
                'version' => $version,
                'strategy' => $finalClassification,
                'suggestion_snapshot' => [
                    ...$suggestion->snapshot(),
                    'operator_correction' => $operatorCorrection,
                    'correction_reason' => $reason,
                    'final_classification' => $finalClassification,
                ],
                'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
                'change_note' => $reason,
                'created_by_admin_id' => $actor->id,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_at' => now(),
            ]);
            $lockedBrief->forceFill(['status' => ContentBrief::STATUS_STRATEGY_READY])->save();

            return $created->fresh();
        });
    }

    /** @param array<string, mixed> $blueprint */
    public function confirmBlueprint(
        ContentBrief $brief,
        ContentStrategyVersion $strategy,
        array $blueprint,
        Admin $actor,
    ): ContentBlueprintVersion {
        $errors = $this->blueprintValidator->validate($blueprint);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($brief, $strategy, $blueprint, $actor): ContentBlueprintVersion {
            $lockedBrief = ContentBrief::query()->whereKey($brief->getKey())->lockForUpdate()->firstOrFail();
            $lockedStrategy = ContentStrategyVersion::query()
                ->whereKey($strategy->getKey())
                ->where('content_brief_id', $lockedBrief->id)
                ->lockForUpdate()
                ->first();
            if ($lockedStrategy === null) {
                throw (new ModelNotFoundException)->setModel(ContentStrategyVersion::class, [$strategy->getKey()]);
            }

            $version = (int) $lockedBrief->blueprintVersions()->max('version') + 1;

            return ContentBlueprintVersion::forceCreate([
                'content_brief_id' => $lockedBrief->id,
                'content_strategy_version_id' => $lockedStrategy->id,
                'version' => $version,
                'blueprint' => $blueprint,
                'validation_report' => [],
                'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
                'created_by_admin_id' => $actor->id,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_at' => now(),
            ])->fresh();
        });
    }

    /** @return array<string, mixed>|null */
    private function runModelExtension(ContentBrief $brief, array $strategy, array $evidence): ?array
    {
        $extension = config('content_strategy.model_extension');
        if ($extension === null) {
            return null;
        }
        if (is_string($extension) && class_exists($extension)) {
            $extension = app($extension);
        }

        if (! is_callable($extension)) {
            throw new InvalidArgumentException('content_strategy.model_extension.invalid');
        }

        $result = $extension($brief, $strategy, $evidence);
        if (! is_array($result)) {
            throw new InvalidArgumentException('content_strategy.model_extension.result_invalid');
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function clusterSnapshot(?KeywordCluster $cluster): ?array
    {
        if ($cluster === null) {
            return null;
        }

        return [
            'id' => $cluster->id === null ? null : (int) $cluster->id,
            'name' => $cluster->name,
            'slug' => $cluster->slug,
            'search_intent' => $cluster->search_intent,
            'coverage_status' => $cluster->coverage_status,
        ];
    }

    /** @return list<array{field:string,value:mixed}> */
    private function evidence(?KeywordCluster $cluster, ?Keyword $primaryKeyword): array
    {
        $evidence = [];
        if ($cluster !== null) {
            foreach (['id', 'name', 'slug', 'search_intent', 'content_type', 'target_url', 'coverage_status'] as $field) {
                if ($cluster->getAttribute($field) !== null && $cluster->getAttribute($field) !== '') {
                    $evidence[] = ['field' => "keyword_cluster.{$field}", 'value' => $cluster->getAttribute($field)];
                }
            }
        }
        if ($primaryKeyword !== null && $primaryKeyword->keyword !== null) {
            $evidence[] = ['field' => 'primary_keyword.keyword', 'value' => $primaryKeyword->keyword];
            $evidence[] = ['field' => 'primary_keyword.search_intent', 'value' => $primaryKeyword->search_intent];
        }

        return $evidence;
    }

    /** @return list<string> */
    private function questions(ContentBrief $brief, ?KeywordCluster $cluster): array
    {
        $questions = [$brief->core_question];
        if ($cluster !== null) {
            foreach ($cluster->keywords as $keyword) {
                if ($keyword->keyword_type === 'question') {
                    $questions[] = $this->asQuestion((string) $keyword->keyword);
                }
            }
        }

        return array_values(array_unique(array_filter($questions, fn (mixed $question): bool => $this->nonEmptyString($question) !== null)));
    }

    private function asQuestion(string $value): string
    {
        $value = trim($value);

        return str_ends_with($value, '?') ? $value : ucfirst($value).'?';
    }

    private function decisionStage(string $intent): string
    {
        return match ($intent) {
            'transactional' => 'decision',
            'commercial', 'commercial_investigation' => 'consideration',
            'navigational' => 'navigation',
            default => 'awareness',
        };
    }

    /** @param array<string, mixed> $suggestion @param array<string, mixed> $strategy */
    private function corrections(array $suggestion, array $strategy): array
    {
        $corrections = [];
        foreach ($strategy as $field => $value) {
            if (! array_key_exists($field, $suggestion) || $suggestion[$field] !== $value) {
                $corrections[$field] = $value;
            }
        }

        return $corrections;
    }

    /** @return list<string> */
    private function nonEmptyValues(?array $values): array
    {
        return array_values(array_filter($values ?? [], fn (mixed $value): bool => $this->nonEmptyString($value) !== null));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
