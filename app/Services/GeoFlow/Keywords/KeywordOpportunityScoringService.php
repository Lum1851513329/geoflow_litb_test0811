<?php

namespace App\Services\GeoFlow\Keywords;

use App\Models\KeywordCluster;
use App\Models\KeywordMetric;
use App\Models\KeywordOpportunityScore;
use DatePeriod;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class KeywordOpportunityScoringService
{
    /** @var list<string> */
    private const METRIC_FIELDS = ['impressions', 'clicks', 'ctr', 'average_position', 'conversions'];

    public function score(KeywordCluster $cluster, DatePeriod $period): KeywordOpportunityScoreResult
    {
        $periodStart = DateTimeImmutable::createFromInterface($period->getStartDate());
        $periodEndValue = $period->getEndDate();
        if ($periodEndValue === null) {
            throw new DomainException('keyword_opportunity.period_end_required');
        }

        $periodEnd = DateTimeImmutable::createFromInterface($periodEndValue);
        if ($periodEnd < $periodStart) {
            throw new DomainException('keyword_opportunity.period_invalid');
        }

        $cluster = KeywordCluster::query()->with('primaryKeyword')->find($cluster->getKey())
            ?? throw new DomainException('keyword_opportunity.cluster_not_found');
        $keywordIds = $cluster->memberships()->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $keywordIds[] = (int) $cluster->primary_keyword_id;
        $keywordIds = array_values(array_unique($keywordIds));

        $metrics = KeywordMetric::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereDate('period_start', '<=', $periodEnd->format('Y-m-d'))
            ->whereDate('period_end', '>=', $periodStart->format('Y-m-d'))
            ->where(function ($query) use ($cluster): void {
                $query->whereNull('language_code')->orWhere('language_code', $cluster->language_code);
            })
            ->where(function ($query) use ($cluster): void {
                $query->whereNull('market_code')->orWhere('market_code', $cluster->market_code);
            })
            ->get();

        $inputs = $this->inputSnapshot($cluster, $metrics, $periodStart, $periodEnd);
        $weightProfile = $cluster->primaryKeyword->brand_relation === 'non_brand' ? 'non_brand' : 'branded';
        $weights = config("keyword_intelligence.opportunity_scoring.weights.{$weightProfile}", []);
        $this->assertWeights($weights);

        $rawComponents = $this->rawComponents($cluster, $inputs);
        $components = [];
        foreach ($rawComponents as $name => $value) {
            $components[$name] = round($value * (float) $weights[$name], 2);
        }

        $penalties = $this->penalties($inputs);
        [$components, $penalties, $total] = $this->reconcile($components, $penalties);
        $version = (string) config('keyword_intelligence.opportunity_scoring.version', 'v1');

        DB::transaction(function () use ($cluster, $version, $periodStart, $periodEnd, $total, $components, $penalties, $inputs, $weights): void {
            $lockedCluster = KeywordCluster::query()->lockForUpdate()->find($cluster->id)
                ?? throw new DomainException('keyword_opportunity.cluster_not_found');
            KeywordOpportunityScore::query()->updateOrCreate(
                [
                    'cluster_id' => $lockedCluster->id,
                    'score_version' => $version,
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                ],
                [
                    'total_score' => $total,
                    'components_json' => $components,
                    'penalties_json' => $penalties,
                    'inputs_json' => $inputs,
                    'weights_json' => $weights,
                ],
            );
            $lockedCluster->forceFill(['latest_opportunity_score' => $total])->save();
        });

        return new KeywordOpportunityScoreResult(
            total: $total,
            components: $components,
            penalties: $penalties,
            version: $version,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
    }

    /**
     * @param  Collection<int,KeywordMetric>  $metrics
     * @return array<string,mixed>
     */
    private function inputSnapshot(
        KeywordCluster $cluster,
        Collection $metrics,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): array {
        $impressions = $this->sumNullable($metrics, 'impressions');
        $clicks = $this->sumNullable($metrics, 'clicks');
        $conversions = $this->sumNullable($metrics, 'conversions');
        $cost = $this->sumNullable($metrics, 'cost');

        $snapshot = [
            'period' => [
                'start' => $periodStart->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
            ],
            'cluster' => [
                'id' => (int) $cluster->id,
                'coverage_status' => (string) $cluster->coverage_status,
                'search_intent' => (string) $cluster->search_intent,
                'brand_relation' => (string) $cluster->primaryKeyword->brand_relation,
                'language_code' => (string) $cluster->language_code,
                'market_code' => $cluster->market_code,
                'market_scope' => (string) $cluster->market_scope,
            ],
            'metrics' => [
                'row_count' => $metrics->count(),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $this->weightedAverage($metrics, 'ctr'),
                'average_position' => $this->weightedAverage($metrics, 'average_position'),
                'cost' => $cost,
                'conversions' => $conversions,
                'latest_period_end' => $metrics->max(static fn (KeywordMetric $metric): ?string => $metric->period_end?->format('Y-m-d')),
                'age_days' => $this->ageInDays($metrics, $periodEnd),
                'present_fields' => [],
            ],
            'metric_rows' => $metrics
                ->sortBy('id')
                ->values()
                ->map(static fn (KeywordMetric $metric): array => [
                    'id' => (int) $metric->id,
                    'keyword_id' => (int) $metric->keyword_id,
                    'source_type' => (string) $metric->source_type,
                    'period_start' => $metric->period_start?->format('Y-m-d'),
                    'period_end' => $metric->period_end?->format('Y-m-d'),
                    'market_code' => $metric->market_code,
                    'language_code' => $metric->language_code,
                    'device' => $metric->device,
                    'clicks' => $metric->clicks,
                    'impressions' => $metric->impressions,
                    'ctr' => $metric->ctr,
                    'average_position' => $metric->average_position,
                    'cost' => $metric->cost,
                    'conversions' => $metric->conversions,
                ])
                ->all(),
        ];
        foreach (self::METRIC_FIELDS as $field) {
            $snapshot['metrics']['present_fields'][$field] = $metrics->contains(
                static fn (KeywordMetric $metric): bool => $metric->getAttribute($field) !== null,
            );
        }

        return $snapshot;
    }

    /** @return array<string,float> */
    private function rawComponents(KeywordCluster $cluster, array $inputs): array
    {
        $metrics = $inputs['metrics'];
        $impressionCeiling = max(1.0, (float) config('keyword_intelligence.opportunity_scoring.demand_impressions_ceiling', 10_000));
        $targetCtr = max(0.000001, (float) config('keyword_intelligence.opportunity_scoring.target_ctr', 0.05));
        $targetConversionRate = max(0.000001, (float) config('keyword_intelligence.opportunity_scoring.target_conversion_rate', 0.10));
        $clicks = (float) ($metrics['clicks'] ?? 0);
        $conversionRate = $clicks > 0 ? (float) ($metrics['conversions'] ?? 0) / $clicks : 0.0;
        $intentScore = match ($cluster->search_intent) {
            'transactional' => 100.0,
            'commercial' => 80.0,
            'navigational' => 60.0,
            default => 40.0,
        };

        return [
            'demand' => $this->bounded((float) ($metrics['impressions'] ?? 0) / $impressionCeiling * 100),
            'click_performance' => $this->bounded((float) ($metrics['ctr'] ?? 0) / $targetCtr * 100),
            'ranking_opportunity' => $this->rankingOpportunity($metrics['average_position']),
            'commercial_relevance' => $this->bounded(($intentScore + $this->bounded($conversionRate / $targetConversionRate * 100)) / 2),
            'content_gap' => match ($cluster->coverage_status) {
                'uncovered' => 100.0,
                'partial' => 50.0,
                default => 0.0,
            },
            'freshness' => $this->freshness($metrics['age_days']),
        ];
    }

    /** @return array<string,float> */
    private function penalties(array $inputs): array
    {
        $penalties = [];
        $present = $inputs['metrics']['present_fields'];
        $missingCount = count(array_filter($present, static fn (bool $value): bool => ! $value));
        if ($missingCount > 0) {
            $maximum = (float) config('keyword_intelligence.opportunity_scoring.missing_data_penalty', 12);
            $penalties['missing_data'] = round($maximum * $missingCount / count(self::METRIC_FIELDS), 2);
        }

        $ageDays = $inputs['metrics']['age_days'];
        $staleAfter = max(0, (int) config('keyword_intelligence.opportunity_scoring.stale_after_days', 30));
        if ($ageDays !== null && $ageDays > $staleAfter) {
            $penalties['stale_data'] = round((float) config('keyword_intelligence.opportunity_scoring.stale_data_penalty', 8), 2);
        }

        return $penalties;
    }

    /**
     * @param  array<string,float>  $components
     * @param  array<string,float>  $penalties
     * @return array{array<string,float>,array<string,float>,float}
     */
    private function reconcile(array $components, array $penalties): array
    {
        $componentTotal = round(array_sum($components), 2);
        $penaltyTotal = round(array_sum($penalties), 2);
        if ($penaltyTotal > $componentTotal && $penaltyTotal > 0) {
            $scale = $componentTotal / $penaltyTotal;
            foreach ($penalties as $name => $value) {
                $penalties[$name] = round($value * $scale, 2);
            }
        }

        $total = round(array_sum($components) - array_sum($penalties), 2);
        if ($total > 100) {
            $components['freshness'] = round($components['freshness'] - ($total - 100), 2);
            $total = 100.0;
        }

        return [$components, $penalties, max(0.0, $total)];
    }

    private function sumNullable(Collection $metrics, string $field): int|float|null
    {
        $values = $metrics->pluck($field)->filter(static fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : $values->sum();
    }

    private function weightedAverage(Collection $metrics, string $field): ?float
    {
        $eligible = $metrics->filter(static fn (KeywordMetric $metric): bool => $metric->getAttribute($field) !== null);
        if ($eligible->isEmpty()) {
            return null;
        }

        $weight = $eligible->sum(static fn (KeywordMetric $metric): int => max(1, (int) ($metric->impressions ?? 0)));
        $sum = $eligible->sum(static fn (KeywordMetric $metric): float => (float) $metric->getAttribute($field) * max(1, (int) ($metric->impressions ?? 0)));

        return round($sum / $weight, 6);
    }

    private function ageInDays(Collection $metrics, DateTimeImmutable $periodEnd): ?int
    {
        $latest = $metrics->max(static fn (KeywordMetric $metric): ?string => $metric->period_end?->format('Y-m-d'));
        if ($latest === null) {
            return null;
        }

        return max(0, (int) (new DateTimeImmutable($latest))->diff($periodEnd)->format('%r%a'));
    }

    private function rankingOpportunity(mixed $position): float
    {
        if ($position === null) {
            return 0.0;
        }

        $position = (float) $position;
        if ($position <= 3) {
            return 20.0;
        }
        if ($position <= 20) {
            return $this->bounded(100 - abs($position - 10) * 5);
        }

        return $this->bounded(80 - ($position - 20) * 2);
    }

    private function freshness(?int $ageDays): float
    {
        if ($ageDays === null) {
            return 0.0;
        }

        $freshDays = max(0, (int) config('keyword_intelligence.opportunity_scoring.fresh_days', 7));
        $staleAfter = max($freshDays + 1, (int) config('keyword_intelligence.opportunity_scoring.stale_after_days', 30));
        if ($ageDays <= $freshDays) {
            return 100.0;
        }

        return $this->bounded(100 * ($staleAfter - $ageDays) / ($staleAfter - $freshDays));
    }

    /** @param array<string,mixed> $weights */
    private function assertWeights(array $weights): void
    {
        $expected = ['demand', 'click_performance', 'ranking_opportunity', 'commercial_relevance', 'content_gap', 'freshness'];
        if (array_keys($weights) !== $expected || abs(array_sum($weights) - 1.0) > 0.000001) {
            throw new DomainException('keyword_opportunity.weights_invalid');
        }
    }

    private function bounded(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
