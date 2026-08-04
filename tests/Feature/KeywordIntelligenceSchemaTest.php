<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordImportBatch;
use App\Models\KeywordImportRow;
use App\Models\KeywordLibrary;
use App\Models\KeywordMetric;
use App\Models\KeywordNode;
use App\Models\KeywordOpportunityScore;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class KeywordIntelligenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_keywords_and_new_intelligence_relations_coexist(): void
    {
        $library = KeywordLibrary::query()->create([
            'name' => 'LITB Search',
            'description' => '',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'mother of the bride dresses',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->assertSame('mother of the bride dresses', $keyword->fresh()->keyword);
        $this->assertSame('en', $keyword->fresh()->language_code);
        $this->assertSame('active', $keyword->fresh()->status);
        $this->assertNull($keyword->fresh()->normalized_keyword);

        $secondLegacyKeyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'wedding guest dresses',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->assertSame('wedding guest dresses', $secondLegacyKeyword->keyword);
        $this->assertNull($secondLegacyKeyword->fresh()->normalized_keyword);

        $taxonomy = KeywordTaxonomy::query()->create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);
        $fashion = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'name' => 'Fashion',
            'slug' => 'fashion',
            'path' => 'fashion',
            'depth' => 0,
        ]);
        $women = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'parent_id' => $fashion->id,
            'name' => 'Women',
            'slug' => 'women',
            'path' => 'fashion/women',
            'depth' => 1,
        ]);
        $dresses = KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'parent_id' => $women->id,
            'name' => 'Dresses',
            'slug' => 'dresses',
            'path' => 'fashion/women/dresses',
            'depth' => 2,
        ]);
        $keyword->taxonomyNodes()->attach($dresses->id, ['is_primary' => true, 'source' => 'manual']);

        KeywordMetric::query()->create([
            'keyword_id' => $keyword->id,
            'source_type' => 'search_console',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'clicks' => 1200,
            'ctr' => 0.42,
            'cost' => 1.75,
        ]);
        KeywordMetric::query()->create([
            'keyword_id' => $keyword->id,
            'source_type' => 'search_console',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'clicks' => 1350,
            'ctr' => 0.44,
            'cost' => 1.82,
        ]);

        $cluster = KeywordCluster::query()->create([
            'name' => 'Mother of the bride dresses',
            'slug' => 'mother-of-the-bride-dresses',
            'primary_keyword_id' => $keyword->id,
            'language_code' => 'en',
            'search_intent' => 'informational',
            'status' => 'active',
        ]);
        $cluster->keywords()->attach($keyword->id, ['role' => 'primary']);

        $score = KeywordOpportunityScore::query()->create([
            'cluster_id' => $cluster->id,
            'score_version' => 'v1',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'total_score' => 81.25,
            'components_json' => ['volume' => 0.8, 'competition' => 0.6],
        ]);

        $this->assertCount(1, $taxonomy->rootNodes()->get());
        $this->assertTrue($fashion->children()->sole()->is($women));
        $this->assertTrue($women->children()->sole()->is($dresses));
        $this->assertTrue($keyword->taxonomyNodes()->whereKey($dresses)->first()->pivot->is_primary);
        $this->assertCount(2, $keyword->metrics()->get());
        $this->assertTrue($cluster->keywords()->sole()->is($keyword));
        $this->assertSame('81.25', $score->total_score);
        $this->assertSame(['volume' => 0.8, 'competition' => 0.6], $score->components_json);
    }

    public function test_migration_preserves_case_variants_without_normalizing_legacy_rows(): void
    {
        $migration = require database_path('migrations/2026_08_03_000001_add_keyword_intelligence_foundation.php');
        $migration->down();

        $libraryId = DB::table('keyword_libraries')->insertGetId([
            'name' => 'Legacy search',
            'description' => '',
            'keyword_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('keywords')->insert([
            [
                'library_id' => $libraryId,
                'keyword' => 'Dress',
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ],
            [
                'library_id' => $libraryId,
                'keyword' => 'dress',
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ],
        ]);

        $migration->up();

        $this->assertSame(2, DB::table('keywords')->where('library_id', $libraryId)->count());
        $this->assertSame(2, DB::table('keywords')->where('library_id', $libraryId)->whereNull('normalized_keyword')->count());
    }

    public function test_keyword_normalization_and_intelligence_records_have_unique_scopes(): void
    {
        $library = KeywordLibrary::query()->create([
            'name' => 'LITB Search',
            'description' => '',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'mother of the bride dresses',
            'normalized_keyword' => 'mother of the bride dresses',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $cluster = KeywordCluster::query()->create([
            'name' => 'Mother of the bride dresses',
            'slug' => 'mother-of-the-bride-dresses',
            'primary_keyword_id' => $keyword->id,
            'language_code' => 'en',
            'search_intent' => 'informational',
            'status' => 'active',
        ]);

        $cluster->keywords()->attach($keyword->id, ['role' => 'primary']);
        KeywordOpportunityScore::query()->create([
            'cluster_id' => $cluster->id,
            'score_version' => 'v1',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'total_score' => 81.25,
        ]);

        $this->assertUniqueConstraint('keywords', fn () => Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'same display text is permitted when normalization differs',
            'normalized_keyword' => 'mother of the bride dresses',
            'used_count' => 0,
            'usage_count' => 0,
        ]));
        $this->assertUniqueConstraint('keyword_cluster_keywords', fn () => $cluster->keywords()->attach($keyword->id, ['role' => 'primary']));
        $this->assertUniqueConstraint('keyword_opportunity_scores', fn () => KeywordOpportunityScore::query()->create([
            'cluster_id' => $cluster->id,
            'score_version' => 'v1',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'total_score' => 70,
        ]));

        $batch = KeywordImportBatch::query()->create([
            'original_filename' => 'keywords.csv',
            'stored_path' => 'imports/keywords.csv',
            'file_sha256' => str_repeat('a', 64),
            'source_type' => 'csv',
            'status' => 'completed',
            'mapping_json' => ['keyword' => 'A'],
        ]);
        KeywordImportRow::query()->create([
            'batch_id' => $batch->id,
            'sheet_name' => 'Sheet1',
            'row_number' => 2,
            'source_payload' => ['A' => 'mother of the bride dresses'],
            'status' => 'imported',
        ]);
        $this->assertUniqueConstraint('keyword_import_rows', fn () => KeywordImportRow::query()->create([
            'batch_id' => $batch->id,
            'sheet_name' => 'Sheet1',
            'row_number' => 2,
            'source_payload' => ['A' => 'duplicate'],
            'status' => 'rejected',
        ]));
    }

    public function test_metrics_can_append_same_period_for_different_import_batches_and_dimensions(): void
    {
        $library = KeywordLibrary::query()->create(['name' => 'LITB Search', 'description' => '', 'keyword_count' => 0]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'mother of the bride dresses',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $firstBatch = KeywordImportBatch::query()->create([
            'original_filename' => 'first.csv',
            'stored_path' => 'imports/first.csv',
            'file_sha256' => str_repeat('a', 64),
            'source_type' => 'csv',
            'status' => 'completed',
            'mapping_json' => [],
        ]);
        $secondBatch = KeywordImportBatch::query()->create([
            'original_filename' => 'second.csv',
            'stored_path' => 'imports/second.csv',
            'file_sha256' => str_repeat('b', 64),
            'source_type' => 'csv',
            'status' => 'completed',
            'mapping_json' => [],
        ]);

        foreach ([
            ['import_batch_id' => $firstBatch->id, 'market_code' => 'US', 'language_code' => 'en', 'device' => 'desktop'],
            ['import_batch_id' => $secondBatch->id, 'market_code' => 'US', 'language_code' => 'en', 'device' => 'desktop'],
            ['import_batch_id' => $firstBatch->id, 'market_code' => 'GB', 'language_code' => 'en', 'device' => 'mobile'],
        ] as $scope) {
            KeywordMetric::query()->create([
                'keyword_id' => $keyword->id,
                'source_type' => 'search_console',
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                ...$scope,
            ]);
        }

        $this->assertCount(3, $keyword->metrics()->get());
    }

    public function test_idempotency_migration_backfills_legacy_batches_and_blocks_replay(): void
    {
        Storage::fake('local');
        $originalConnection = config('database.default');
        config()->set('database.connections.keyword_upgrade', [
            ...config('database.connections.sqlite'),
            'database' => ':memory:',
        ]);
        config()->set('database.default', 'keyword_upgrade');
        DB::purge('keyword_upgrade');
        try {
            (require database_path('migrations/2026_04_18_100000_create_geoflow_admins_and_site_settings_tables.php'))->up();
            (require database_path('migrations/2026_04_18_120002_sqlite_geoflow_minimal_for_testing.php'))->up();
            (require database_path('migrations/2026_08_03_000001_add_keyword_intelligence_foundation.php'))->up();
            (require database_path('migrations/2026_08_03_000002_add_keyword_import_idempotency.php'))->up();

            $this->assertFalse(Schema::hasColumn('keyword_import_batches', 'config_scope'));
            $libraryId = DB::table('keyword_libraries')->insertGetId([
                'name' => 'Legacy imports',
                'description' => '',
                'keyword_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $source = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');
            $mapping = ['query' => 'keyword', 'clicks' => 'clicks'];
            $metadata = ['device' => 'desktop'];
            $legacyBatchId = DB::table('keyword_import_batches')->insertGetId([
                'library_id' => $libraryId,
                'original_filename' => 'legacy.xlsx',
                'stored_path' => 'keyword-imports/legacy.xlsx',
                'file_sha256' => hash_file('sha256', $source),
                'import_config_hash' => null,
                'source_type' => 'search_console',
                'status' => 'completed',
                'mapping_json' => json_encode($mapping, JSON_THROW_ON_ERROR),
                'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertNull(DB::table('keyword_import_batches')->where('id', $legacyBatchId)->value('import_config_hash'));
            $upgrade = require database_path('migrations/2026_08_04_000003_upgrade_keyword_import_config_scope.php');
            $upgrade->up();

            $legacy = DB::table('keyword_import_batches')->where('id', $legacyBatchId)->first();
            $this->assertTrue(Schema::hasColumn('keyword_import_batches', 'config_scope'));
            $this->assertNotNull($legacy->import_config_hash);
            $this->assertSame(64, strlen($legacy->import_config_hash));
            $this->assertSame('legacy-'.$legacyBatchId, $legacy->config_scope);
            try {
                (new KeywordImportService)->createBatch([
                    'library_id' => $libraryId,
                    'source_path' => $source,
                    'original_filename' => 'replay.xlsx',
                    'source_type' => 'search_console',
                    'mapping_json' => $mapping,
                    'metadata_json' => $metadata,
                    'period_start' => '2026-07-01',
                    'period_end' => '2026-07-31',
                ]);
                $this->fail('The legacy file and configuration must block replay.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('already have a batch', $exception->getMessage());
            }

            $upgrade->down();
            $this->assertFalse(Schema::hasColumn('keyword_import_batches', 'config_scope'));
            $this->assertNull(DB::table('keyword_import_batches')->where('id', $legacyBatchId)->value('import_config_hash'));
        } finally {
            DB::purge('keyword_upgrade');
            config()->set('database.default', $originalConnection);
        }
    }

    private function assertUniqueConstraint(string $table, callable $write): void
    {
        try {
            $write();
            $this->fail('Expected the database uniqueness contract to reject this record.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression('/unique constraint failed|duplicate key/i', $exception->getMessage());
            $this->assertStringContainsString($table, $exception->getMessage());
            $this->addToAssertionCount(1);
        }
    }
}
