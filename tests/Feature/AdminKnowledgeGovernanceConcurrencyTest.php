<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminKnowledgeGovernanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(Admin::query()->create([
            'username' => 'knowledge_concurrency_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-concurrency-admin@example.com',
            'display_name' => 'Knowledge Concurrency Admin',
            'role' => 'admin',
            'status' => 'active',
        ]), 'admin');
    }

    public function test_both_edit_routes_reload_committed_history_with_a_serializing_lock(): void
    {
        Queue::fake();
        $scope = $this->createOwnership();
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value): string
            {
                return $value ? ' /* geoflow-lock-for-update */' : '';
            }
        });
        $baselineTransactionLevel = DB::transactionLevel();
        $lockedReads = [];
        $documentIds = [];
        DB::listen(function (QueryExecuted $query) use (&$lockedReads): void {
            if (str_contains($query->sql, 'geoflow-lock-for-update')) {
                $lockedReads[] = [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'transaction_level' => DB::transactionLevel(),
                ];
            }
        });

        try {
            foreach (['form', 'detail'] as $routeKind) {
                $knowledgeBase = $this->createDocument($scope, $routeKind);
                $documentIds[] = (int) $knowledgeBase->id;
                $staleRouteModel = KnowledgeBase::query()->findOrFail($knowledgeBase->id);
                $committedProvenance = [
                    'ingestion_method' => 'read_only_folder',
                    'operator_edits' => [[
                        'operation' => 'prior_committed_operator_update',
                        'admin_id' => 999,
                        'edited_at' => '2026-08-20T00:00:00+00:00',
                    ]],
                ];
                DB::table('knowledge_bases')->where('id', $knowledgeBase->id)->update([
                    'asset_provenance' => json_encode($committedProvenance, JSON_THROW_ON_ERROR),
                ]);
                $this->assertArrayNotHasKey('operator_edits', (array) $staleRouteModel->asset_provenance);

                if ($routeKind === 'form') {
                    $this->put(
                        route('admin.knowledge-bases.update', ['knowledgeBaseId' => $knowledgeBase->id]),
                        $this->governedPayload($scope, [
                            'name' => 'Concurrent provenance form',
                            'content' => 'Form route update body.',
                        ]),
                    )->assertRedirect(route('admin.knowledge-bases.index'));
                } else {
                    $this->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->id]), [
                        'name' => 'Concurrent provenance detail',
                        'description' => '',
                        'content' => 'Detail route update body.',
                        'file_type' => 'markdown',
                    ])->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->id]));
                }

                $operations = collect((array) ($knowledgeBase->fresh()->asset_provenance['operator_edits'] ?? []))
                    ->pluck('operation')
                    ->all();
                $this->assertSame([
                    'prior_committed_operator_update',
                    $routeKind === 'form' ? 'admin_form_update' : 'admin_detail_update',
                ], $operations);
            }
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }

        $this->assertCount(4, $lockedReads, json_encode($lockedReads, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        foreach ($lockedReads as $lockedRead) {
            $this->assertStringContainsString('from "knowledge_bases"', $lockedRead['sql']);
            $this->assertGreaterThan($baselineTransactionLevel, $lockedRead['transaction_level']);
        }
        $lockedReadCounts = collect($lockedReads)
            ->countBy(fn (array $read): int => (int) $read['bindings'][0])
            ->all();
        foreach ($documentIds as $documentId) {
            $this->assertSame(2, $lockedReadCounts[$documentId] ?? 0);
        }
    }

    /** @return array{brand: KnowledgeBrand, site: KnowledgeSite, directory: KnowledgeDirectory} */
    private function createOwnership(): array
    {
        $brand = KnowledgeBrand::query()->create(['key' => 'lightinthebox', 'name' => 'LightInTheBox']);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'lightinthebox-main',
            'canonical_host' => 'www.lightinthebox.test',
            'role' => 'commerce-primary',
        ]);
        $directory = KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'policy-compliance',
            'path' => 'policy-compliance',
            'name' => 'Policy & Compliance',
        ]);

        return compact('brand', 'site', 'directory');
    }

    /**
     * @param  array{brand: KnowledgeBrand, site: KnowledgeSite, directory: KnowledgeDirectory}  $scope
     */
    private function createDocument(array $scope, string $routeKind): KnowledgeBase
    {
        return KnowledgeBase::query()->create([
            'name' => 'Concurrent provenance '.$routeKind,
            'description' => 'Governed evidence',
            'content' => "Original {$routeKind} body.\n",
            'file_type' => 'markdown',
            'brand_id' => $scope['brand']->id,
            'site_id' => $scope['site']->id,
            'primary_directory_id' => $scope['directory']->id,
            'canonical_document_key' => 'manual:concurrency-'.$routeKind,
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'source_name' => 'Official source',
            'source_url' => 'https://www.lightinthebox.test/source',
            'source_type' => 'official_web_page',
            'retrieved_at' => '2026-08-19 00:00:00',
            'risk_level' => 'medium',
            'review_status' => 'reviewed',
            'version_label' => 'v1',
            'lifecycle_status' => 'active',
            'generation_eligible' => true,
            'citation_required' => true,
            'normalized_content_hash' => hash('sha256', "Original {$routeKind} body.\n"),
            'asset_provenance' => ['ingestion_method' => 'read_only_folder'],
        ]);
    }

    /**
     * @param  array{brand: KnowledgeBrand, site: KnowledgeSite, directory: KnowledgeDirectory}  $scope
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function governedPayload(array $scope, array $overrides): array
    {
        return array_replace([
            'name' => 'Governed knowledge',
            'description' => 'Governed evidence',
            'content' => 'Governed evidence body.',
            'file_type' => 'markdown',
            'brand_id' => $scope['brand']->id,
            'site_id' => $scope['site']->id,
            'primary_directory_id' => $scope['directory']->id,
            'canonical_document_key' => 'manual:concurrency-update',
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'source_name' => 'Official source',
            'source_url' => 'https://www.lightinthebox.test/source',
            'source_type' => 'official_web_page',
            'retrieved_at' => '2026-08-19',
            'risk_level' => 'medium',
            'review_status' => 'reviewed',
            'version_label' => 'v1',
            'lifecycle_status' => 'active',
            'generation_eligible' => '0',
            'citation_required' => '1',
            'import_action' => 'save',
        ], $overrides);
    }
}
