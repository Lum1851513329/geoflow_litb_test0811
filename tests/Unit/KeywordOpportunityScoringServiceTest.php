<?php

namespace Tests\Unit;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordMetric;
use App\Models\KeywordOpportunityScore;
use App\Services\GeoFlow\Keywords\KeywordOpportunityScoringService;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordOpportunityScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_score_is_bounded_explained_and_persists_its_exact_snapshot(): void
    {
        $cluster = $this->cluster('non_brand');
        $metric = $this->metric($cluster->primaryKeyword, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'clicks' => 400,
            'impressions' => 10_000,
            'ctr' => 0.04,
            'average_position' => 8,
            'cost' => 160,
            'conversions' => 40,
        ]);

        $result = app(KeywordOpportunityScoringService::class)->score($cluster, $this->period());

        $this->assertGreaterThanOrEqual(0, $result->total);
        $this->assertLessThanOrEqual(100, $result->total);
        $this->assertSame([
            'demand',
            'click_performance',
            'ranking_opportunity',
            'commercial_relevance',
            'content_gap',
            'freshness',
        ], array_keys($result->components));
        $this->assertSame(
            $result->total,
            round(array_sum($result->components) - array_sum($result->penalties), 2),
        );
        $this->assertSame('2026-07-01', $result->periodStart->format('Y-m-d'));
        $this->assertSame('2026-07-31', $result->periodEnd->format('Y-m-d'));

        $score = KeywordOpportunityScore::query()->sole();
        $this->assertSame($result->version, $score->score_version);
        $this->assertSame('10000', (string) $score->inputs_json['metrics']['impressions']);
        $this->assertSame('non_brand', $score->inputs_json['cluster']['brand_relation']);
        $this->assertSame([
            'start' => '2026-07-01',
            'end' => '2026-07-31',
        ], $score->inputs_json['period']);
        $this->assertSame($metric->id, $score->inputs_json['metric_rows'][0]['id']);
        $this->assertSame('search_console', $score->inputs_json['metric_rows'][0]['source_type']);
        $this->assertSame('0.040000', $score->inputs_json['metric_rows'][0]['ctr']);
        $this->assertSame(config('keyword_intelligence.opportunity_scoring.weights.non_brand'), $score->weights_json);
        $this->assertEquals($result->components, $score->components_json);
        $this->assertEquals($result->penalties, $score->penalties_json);
        $this->assertSame(number_format($result->total, 2, '.', ''), $cluster->fresh()->latest_opportunity_score);
    }

    public function test_stale_and_missing_metric_data_reduce_the_score_with_named_penalties(): void
    {
        $complete = $this->cluster('non_brand', 'complete');
        $this->metric($complete->primaryKeyword, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'clicks' => 400,
            'impressions' => 10_000,
            'ctr' => 0.04,
            'average_position' => 8,
            'cost' => 160,
            'conversions' => 40,
        ]);

        $incomplete = $this->cluster('non_brand', 'incomplete');
        $this->metric($incomplete->primaryKeyword, [
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'clicks' => null,
            'impressions' => 10_000,
            'ctr' => null,
            'average_position' => null,
            'cost' => null,
            'conversions' => null,
        ]);

        $service = app(KeywordOpportunityScoringService::class);
        $fresh = $service->score($complete, $this->period('2026-01-01', '2026-07-31'));
        $penalized = $service->score($incomplete, $this->period('2026-01-01', '2026-07-31'));

        $this->assertArrayNotHasKey('stale_data', $fresh->penalties);
        $this->assertArrayHasKey('stale_data', $penalized->penalties);
        $this->assertArrayHasKey('missing_data', $penalized->penalties);
        $this->assertGreaterThan(0, $penalized->penalties['stale_data']);
        $this->assertGreaterThan(0, $penalized->penalties['missing_data']);
        $this->assertLessThan($fresh->total, $penalized->total);
        $this->assertSame(
            $penalized->total,
            round(array_sum($penalized->components) - array_sum($penalized->penalties), 2),
        );
    }

    public function test_low_score_penalty_allocation_conserves_every_cent_at_the_zero_floor(): void
    {
        $cluster = $this->cluster('non_brand', 'zero-floor');
        $cluster->update([
            'search_intent' => 'informational',
            'coverage_status' => 'covered',
        ]);
        $cluster->primaryKeyword->update(['search_intent' => 'informational']);
        $this->metric($cluster->primaryKeyword, [
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'average_position' => null,
            'cost' => null,
            'conversions' => null,
        ]);

        $result = app(KeywordOpportunityScoringService::class)->score(
            $cluster,
            $this->period('2026-01-01', '2026-07-31'),
        );

        $this->assertSame(3.0, round(array_sum($result->components), 2));
        $this->assertSame(0.0, $result->total);
        $this->assertSame(
            $result->total,
            round(array_sum($result->components) - array_sum($result->penalties), 2),
        );
        $this->assertSame(
            $result->total,
            (float) KeywordOpportunityScore::query()->where('cluster_id', $cluster->id)->sole()->total_score,
        );
    }

    public function test_branded_and_non_branded_clusters_use_distinct_configured_weights(): void
    {
        $nonBrand = $this->cluster('non_brand', 'generic');
        $branded = $this->cluster('branded', 'brand');

        foreach ([$nonBrand, $branded] as $cluster) {
            $this->metric($cluster->primaryKeyword, [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'clicks' => 100,
                'impressions' => 10_000,
                'ctr' => 0.01,
                'average_position' => 30,
                'cost' => 50,
                'conversions' => 2,
            ]);
        }

        $service = app(KeywordOpportunityScoringService::class);
        $nonBrandResult = $service->score($nonBrand, $this->period());
        $brandedResult = $service->score($branded, $this->period());

        $this->assertNotSame(
            config('keyword_intelligence.opportunity_scoring.weights.non_brand'),
            config('keyword_intelligence.opportunity_scoring.weights.branded'),
        );
        $this->assertNotSame($nonBrandResult->components, $brandedResult->components);
        $this->assertNotSame($nonBrandResult->total, $brandedResult->total);
        $this->assertSame(
            config('keyword_intelligence.opportunity_scoring.weights.branded'),
            KeywordOpportunityScore::query()->where('cluster_id', $branded->id)->sole()->weights_json,
        );
    }

    private function period(string $start = '2026-07-01', string $end = '2026-07-31'): DatePeriod
    {
        return new DatePeriod(
            new DateTimeImmutable($start),
            new DateInterval('P1D'),
            new DateTimeImmutable($end),
            DatePeriod::INCLUDE_END_DATE,
        );
    }

    private function cluster(string $brandRelation, string $suffix = 'cluster'): KeywordCluster
    {
        $library = KeywordLibrary::query()->create([
            'name' => "Scoring {$suffix}",
            'description' => '',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => "summer dress {$suffix}",
            'normalized_keyword' => "summer dress {$suffix}",
            'language_code' => 'en',
            'market_code' => 'US',
            'market_scope' => 'US',
            'keyword_type' => 'category',
            'search_intent' => 'commercial',
            'brand_relation' => $brandRelation,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $cluster = KeywordCluster::query()->create([
            'name' => "Summer dress {$suffix}",
            'slug' => "summer-dress-{$suffix}",
            'primary_keyword_id' => $keyword->id,
            'language_code' => 'en',
            'market_code' => 'US',
            'market_scope' => 'US',
            'search_intent' => 'commercial',
            'status' => 'active',
            'coverage_status' => 'uncovered',
        ]);
        $cluster->keywords()->attach($keyword->id, ['role' => 'primary']);

        return $cluster;
    }

    /** @param array<string,mixed> $attributes */
    private function metric(Keyword $keyword, array $attributes): KeywordMetric
    {
        return KeywordMetric::query()->create([
            'keyword_id' => $keyword->id,
            'source_type' => 'search_console',
            'language_code' => 'en',
            'market_code' => 'US',
            ...$attributes,
        ]);
    }
}
