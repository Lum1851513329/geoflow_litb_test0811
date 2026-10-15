# GEOFlow Keyword Foundation and Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a backward-compatible hierarchical keyword domain, auditable Excel import pipeline, topic clusters, and explainable opportunity scoring for GEOFlow.

**Architecture:** Keep the existing `keyword_libraries` and `keywords` contracts operational while adding normalized keyword metadata and separate taxonomy, metric, relation, import, cluster, and score records. Import is a preview-then-confirm workflow; parsing and normalization are pure services, while persistence is transactional and idempotent. Admin controllers remain thin and delegate all domain behavior to focused services.

**Tech Stack:** PHP 8.3+, Laravel 12, Eloquent, Blade, Laravel queues, PHPUnit 11, Laravel Pint, PhpSpreadsheet 5 for `.xlsx` parsing.

## Global Constraints

- Preserve all existing keyword-library admin and API routes and payload fields.
- Use reversible Laravel migrations; no migration may depend on production-only data.
- Do not import either LITB workbook into the running database during implementation.
- Preview must not write keyword, metric, taxonomy, relation, cluster, or score records.
- Metrics are append-only by import batch; unknown values remain `null`, never synthetic zeroes.
- Existing tasks, titles, prompts, workers, and public publishing must continue to operate.
- All admin writes retain existing authentication, CSRF, validation, localization, and activity-log conventions.
- File storage paths use generated names and a controlled private disk path, never the client filename.
- Run focused tests after each task and the complete test suite before completion.

---

## File Structure

New domain models live in `app/Models` and expose only relationships and casts. Keyword parsing, normalization, import, taxonomy, clustering, and scoring live in separate `app/Services/GeoFlow/Keywords` classes. Admin HTTP handling lives in a dedicated controller and request classes; queue execution lives in one job. Keyword admin views receive view models from services and do not contain persistence logic.

The plan intentionally postpones Prompt recipes and Worker changes to the second implementation plan. The only cross-plan contract introduced here is the stable `KeywordCluster` model and its primary-key ID.

### Task 1: Add Keyword Domain Schema and Models

**Files:**
- Create: `database/migrations/2026_08_03_000001_add_keyword_intelligence_foundation.php`
- Modify: `app/Models/Keyword.php`
- Modify: `app/Models/KeywordLibrary.php`
- Create: `app/Models/KeywordTaxonomy.php`
- Create: `app/Models/KeywordNode.php`
- Create: `app/Models/KeywordMetric.php`
- Create: `app/Models/KeywordRelation.php`
- Create: `app/Models/KeywordImportBatch.php`
- Create: `app/Models/KeywordImportRow.php`
- Create: `app/Models/KeywordCluster.php`
- Create: `app/Models/KeywordClusterKeyword.php`
- Create: `app/Models/KeywordOpportunityScore.php`
- Test: `tests/Feature/KeywordIntelligenceSchemaTest.php`

**Interfaces:**
- Produces: `Keyword::taxonomyNodes()`, `Keyword::metrics()`, `Keyword::clusters()`, `KeywordTaxonomy::rootNodes()`, `KeywordNode::children()`, `KeywordCluster::keywords()`, and model casts used by all later tasks.
- Produces database uniqueness contracts for normalized keywords, import rows, cluster membership, and score versions.

- [ ] **Step 1: Write failing migration and relationship tests**

Create tests that migrate the database, create a legacy keyword with only the old fields, create `Fashion → Women → Dresses`, attach the keyword as primary, append two metric periods, create a cluster, and assert all relations and casts.

```php
public function test_legacy_keywords_and_new_intelligence_relations_coexist(): void
{
    $library = KeywordLibrary::query()->create([
        'name' => 'LITB Search', 'description' => '', 'keyword_count' => 0,
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
}
```

- [ ] **Step 2: Run the schema test and verify it fails**

Run: `php artisan test tests/Feature/KeywordIntelligenceSchemaTest.php`

Expected: FAIL because the new tables, columns, and models do not exist.

- [ ] **Step 3: Implement the reversible migration**

Add nullable/defaulted compatibility columns to `keywords`, then create taxonomy nodes, the keyword-node pivot, metrics, relations, import batches/rows, clusters/memberships, and opportunity scores. Add foreign keys, delete policies, lookup indexes, and these uniqueness constraints:

```php
$table->unique(
    ['library_id', 'normalized_keyword', 'language_code', 'market_scope'],
    'keywords_normalized_locale_unique'
);
$table->unique(['batch_id', 'sheet_name', 'row_number']);
$table->unique(['cluster_id', 'keyword_id']);
$table->unique(['cluster_id', 'score_version', 'period_start', 'period_end']);
```

`market_code` remains nullable as specified; add a non-null `market_scope` normalized to the ISO code or `GLOBAL` so PostgreSQL and SQLite enforce the same uniqueness semantics. Use `restrictOnDelete()` for taxonomy nodes with children and business records referenced by clusters; use `cascadeOnDelete()` only for true owned records such as batch rows and cluster memberships.

- [ ] **Step 4: Implement models, casts, and relationships**

Each model must define `$fillable`, typed casts, and explicit foreign-key relationship names. Preserve `Keyword::UPDATED_AT = null` and all old fillable fields.

- [ ] **Step 5: Run focused and legacy material tests**

Run:

```bash
php artisan test tests/Feature/KeywordIntelligenceSchemaTest.php
php artisan test tests/Feature/AdminMaterialsPagesTest.php --filter=keyword
php artisan test tests/Feature/ApiV1ContractTest.php --filter=keyword
```

Expected: PASS; existing keyword library creation and item APIs retain their old fields.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_03_000001_add_keyword_intelligence_foundation.php app/Models/Keyword.php app/Models/KeywordLibrary.php app/Models/KeywordTaxonomy.php app/Models/KeywordNode.php app/Models/KeywordMetric.php app/Models/KeywordRelation.php app/Models/KeywordImportBatch.php app/Models/KeywordImportRow.php app/Models/KeywordCluster.php app/Models/KeywordClusterKeyword.php app/Models/KeywordOpportunityScore.php tests/Feature/KeywordIntelligenceSchemaTest.php
git commit -m "feat: add keyword intelligence schema"
```

### Task 2: Add Deterministic Keyword Normalization and Classification

**Files:**
- Create: `app/Services/GeoFlow/Keywords/NormalizedKeyword.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordNormalizer.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordClassifier.php`
- Create: `config/keyword_intelligence.php`
- Test: `tests/Unit/KeywordNormalizerTest.php`
- Test: `tests/Unit/KeywordClassifierTest.php`

**Interfaces:**
- Produces: `KeywordNormalizer::normalize(string $value, ?string $language, ?string $market): NormalizedKeyword`.
- Produces: `KeywordClassifier::classify(NormalizedKeyword $keyword): array{keyword_type:string,search_intent:string,brand_relation:string}`.
- Consumes: configurable LightInTheBox spelling variants and intent phrase lists from `config/keyword_intelligence.php`.

- [ ] **Step 1: Write normalization tests**

Cover Unicode normalization, whitespace, case folding, locale validation, brand spelling preservation, blank rejection, and the fact that singular/plural words are not automatically merged.

```php
$normalized = $service->normalize('  Light in the BOX   Dresses ', 'EN', 'us');
$this->assertSame('light in the box dresses', $normalized->normalized);
$this->assertSame('en', $normalized->languageCode);
$this->assertSame('US', $normalized->marketCode);
```

- [ ] **Step 2: Run the normalizer tests and verify failure**

Run: `php artisan test tests/Unit/KeywordNormalizerTest.php`

Expected: FAIL because the value object and service do not exist.

- [ ] **Step 3: Implement the immutable value object and normalizer**

`NormalizedKeyword` contains `original`, `normalized`, `languageCode`, and `marketCode`. Reject an empty normalized value with `InvalidArgumentException`; validate language and market formats without inventing a locale.

- [ ] **Step 4: Write classifier tests**

Cover at minimum:

```php
'lightinthebox' => ['brand', 'navigational', 'branded']
'lightinthebox dresses' => ['category', 'commercial', 'brand_category']
'is lightinthebox legit' => ['trust', 'informational', 'branded']
'mother of the bride dresses' => ['category', 'commercial', 'non_brand']
'lightinthebox coupon' => ['brand', 'transactional', 'branded']
```

- [ ] **Step 5: Implement rule-based classification and run tests**

Run:

```bash
php artisan test tests/Unit/KeywordNormalizerTest.php
php artisan test tests/Unit/KeywordClassifierTest.php
```

Expected: PASS with deterministic output and no model/network calls.

- [ ] **Step 6: Commit**

```bash
git add app/Services/GeoFlow/Keywords/NormalizedKeyword.php app/Services/GeoFlow/Keywords/KeywordNormalizer.php app/Services/GeoFlow/Keywords/KeywordClassifier.php config/keyword_intelligence.php tests/Unit/KeywordNormalizerTest.php tests/Unit/KeywordClassifierTest.php
git commit -m "feat: normalize and classify keywords"
```

### Task 3: Add Safe XLSX Parsing and Mapping Preview

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `app/Services/GeoFlow/Keywords/KeywordWorkbookReader.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordColumnMapper.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordImportPreview.php`
- Test: `tests/Unit/KeywordWorkbookReaderTest.php`
- Test: `tests/Unit/KeywordColumnMapperTest.php`
- Create: `tests/Fixtures/keywords/category-search-sample.xlsx`
- Create: `tests/Fixtures/keywords/brand-search-sample.xlsx`

**Interfaces:**
- Produces: `KeywordWorkbookReader::inspect(string $absolutePath): array` with sheet names, normalized headers, sample rows, and row estimates.
- Produces: `KeywordColumnMapper::suggest(array $headers, string $sourceType): array<string,string>`.
- Produces: `KeywordImportPreview::build(string $absolutePath, string $sourceType, array $mapping): array` with valid samples, rejected samples, and counts.

- [ ] **Step 1: Add PhpSpreadsheet through Composer**

Run: `composer require phpoffice/phpspreadsheet:^5.0`

Expected: dependency resolves on the project PHP version; do not manually edit the lock file.

- [ ] **Step 2: Create minimal fixture workbooks**

Fixtures must contain representative headers and 5–10 synthetic rows only; do not commit user-provided production data. Generate fixtures once with PhpSpreadsheet and commit the resulting small `.xlsx` files.

- [ ] **Step 3: Write reader security and shape tests**

Assert `.xlsx` only, readable regular file, configured size limit, formula cells returned as stored values without formula execution, maximum sheet/row/column limits, and predictable header normalization.

- [ ] **Step 4: Run reader tests and verify failure**

Run: `php artisan test tests/Unit/KeywordWorkbookReaderTest.php tests/Unit/KeywordColumnMapperTest.php`

Expected: FAIL because the services do not exist.

- [ ] **Step 5: Implement read-only parsing and mapping suggestions**

Use PhpSpreadsheet read-data-only mode and explicit worksheet/row bounds. Known aliases must map fields such as query/keyword, clicks, impressions, CTR, position, country, language, date range, cost, and conversions. Unknown columns remain available in `source_payload` but are never silently assigned.

- [ ] **Step 6: Implement pure preview construction**

The preview service invokes the reader, mapper, normalizer, and classifier, but performs no Eloquent writes and dispatches no jobs.

- [ ] **Step 7: Run tests and verify no database writes**

Run: `php artisan test tests/Unit/KeywordWorkbookReaderTest.php tests/Unit/KeywordColumnMapperTest.php`

Expected: PASS; a database query listener in the preview test observes no insert/update/delete statements.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock app/Services/GeoFlow/Keywords/KeywordWorkbookReader.php app/Services/GeoFlow/Keywords/KeywordColumnMapper.php app/Services/GeoFlow/Keywords/KeywordImportPreview.php tests/Unit/KeywordWorkbookReaderTest.php tests/Unit/KeywordColumnMapperTest.php tests/Fixtures/keywords/category-search-sample.xlsx tests/Fixtures/keywords/brand-search-sample.xlsx
git commit -m "feat: preview keyword workbooks"
```

### Task 4: Implement Transactional and Idempotent Import

**Files:**
- Create: `app/Services/GeoFlow/Keywords/KeywordImportService.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordImportRevertService.php`
- Create: `app/Jobs/ImportKeywordWorkbookJob.php`
- Create: `app/Events/KeywordImportProgressed.php`
- Test: `tests/Feature/KeywordImportServiceTest.php`
- Test: `tests/Feature/KeywordImportJobTest.php`

**Interfaces:**
- Produces: `KeywordImportService::createBatch(array $metadata): KeywordImportBatch`.
- Produces: `KeywordImportService::import(KeywordImportBatch $batch): KeywordImportBatch`.
- Produces: `KeywordImportRevertService::revert(KeywordImportBatch $batch): KeywordImportBatch`.
- Consumes Task 2 normalization/classification and Task 3 reader/mapping contracts.

- [ ] **Step 1: Write import idempotency tests**

Test new keywords, existing legacy keywords, duplicate workbook rows, nullable metrics, two periods for one keyword, retry after a failed chunk, and duplicate file hash/config rejection.

```php
$service->import($batch);
$service->import($batch->fresh());

$this->assertDatabaseCount('keyword_metrics', 2);
$this->assertSame('completed', $batch->fresh()->status);
```

- [ ] **Step 2: Run service tests and verify failure**

Run: `php artisan test tests/Feature/KeywordImportServiceTest.php`

Expected: FAIL because import services are absent.

- [ ] **Step 3: Implement batch creation and import transactions**

Persist generated storage path, SHA-256, mapping JSON, source type, period, and operator. Process deterministic chunks; use database unique constraints plus `updateOrCreate` only where replay semantics are explicitly defined. Update `keyword_count` after successful completion.

- [ ] **Step 4: Implement safe revert behavior**

Delete metrics and batch-owned relations first. Delete a keyword only if it was created by this batch and has no metrics from another batch, cluster membership, title reference, article reference, or task reference. Otherwise retain it and record `retained_shared_keyword_count`.

- [ ] **Step 5: Write and implement queued job tests**

Assert status transitions, retry behavior, progress events, terminal error summaries, and that the job accepts a batch ID rather than a filesystem path supplied by the request.

- [ ] **Step 6: Run import tests**

Run:

```bash
php artisan test tests/Feature/KeywordImportServiceTest.php
php artisan test tests/Feature/KeywordImportJobTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/GeoFlow/Keywords/KeywordImportService.php app/Services/GeoFlow/Keywords/KeywordImportRevertService.php app/Jobs/ImportKeywordWorkbookJob.php app/Events/KeywordImportProgressed.php tests/Feature/KeywordImportServiceTest.php tests/Feature/KeywordImportJobTest.php
git commit -m "feat: import keyword workbooks safely"
```

### Task 5: Implement Taxonomy Management and Cycle Protection

**Files:**
- Create: `app/Services/GeoFlow/Keywords/KeywordTaxonomyService.php`
- Create: `app/Http/Requests/Admin/StoreKeywordNodeRequest.php`
- Create: `app/Http/Requests/Admin/MoveKeywordNodeRequest.php`
- Test: `tests/Feature/KeywordTaxonomyServiceTest.php`

**Interfaces:**
- Produces: `KeywordTaxonomyService::createNode(KeywordTaxonomy $taxonomy, array $data): KeywordNode`.
- Produces: `KeywordTaxonomyService::moveNode(KeywordNode $node, ?KeywordNode $parent, int $sortOrder): KeywordNode`.
- Produces: `KeywordTaxonomyService::assignKeywords(KeywordNode $node, array $keywordIds, ?int $primaryKeywordId = null): void`.

- [ ] **Step 1: Write hierarchy invariant tests**

Cover valid nesting, stable materialized path update, maximum configured depth, cross-taxonomy parent rejection, self-parenting, descendant-parent cycles, and deletion with children or cluster references.

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test tests/Feature/KeywordTaxonomyServiceTest.php`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement transactional hierarchy operations**

Lock the moved node and candidate parent, validate invariants, then update the node and all descendant paths in one transaction. Expose domain exceptions with stable translation keys rather than raw SQL errors.

- [ ] **Step 4: Implement validated request objects**

Use integer IDs, bounded names/slugs, configured maximum depth, and explicit booleans. Authorization follows the existing admin guard and does not introduce a parallel auth mechanism.

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Feature/KeywordTaxonomyServiceTest.php
git add app/Services/GeoFlow/Keywords/KeywordTaxonomyService.php app/Http/Requests/Admin/StoreKeywordNodeRequest.php app/Http/Requests/Admin/MoveKeywordNodeRequest.php tests/Feature/KeywordTaxonomyServiceTest.php
git commit -m "feat: manage keyword taxonomies"
```

### Task 6: Implement Topic Clustering and Explainable Scoring

**Files:**
- Create: `app/Services/GeoFlow/Keywords/KeywordClusterSuggestionService.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordClusterService.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordOpportunityScoringService.php`
- Create: `app/Services/GeoFlow/Keywords/KeywordOpportunityScoreResult.php`
- Extend: `config/keyword_intelligence.php`
- Test: `tests/Unit/KeywordOpportunityScoringServiceTest.php`
- Test: `tests/Feature/KeywordClusterServiceTest.php`

**Interfaces:**
- Produces: `KeywordClusterSuggestionService::suggest(iterable $keywords): array` without persistence.
- Produces: `KeywordClusterService::confirm(array $suggestion, int $adminId): KeywordCluster`.
- Produces: `KeywordOpportunityScoringService::score(KeywordCluster $cluster, DatePeriod $period): KeywordOpportunityScoreResult`.
- `KeywordOpportunityScoreResult` exposes `total`, `components`, `penalties`, `version`, `periodStart`, and `periodEnd`.

- [ ] **Step 1: Write score tests with fixed metrics**

Assert scores are bounded 0–100, explanations sum to the total after rounding, stale/missing data incurs a penalty, and branded versus non-branded clusters use different configured weights.

- [ ] **Step 2: Run scoring tests and verify failure**

Run: `php artisan test tests/Unit/KeywordOpportunityScoringServiceTest.php`

Expected: FAIL because scoring classes do not exist.

- [ ] **Step 3: Implement scoring value object and service**

Use named components: `demand`, `click_performance`, `ranking_opportunity`, `commercial_relevance`, `content_gap`, and `freshness`. Persist exact inputs and weights in `explanation_json`.

- [ ] **Step 4: Write cluster tests**

Cover primary/secondary/question/variant roles, one active primary assignment per locale and market, taxonomy and intent boundaries, administrator confirmation, and protection of confirmed clusters from automatic overwrite.

- [ ] **Step 5: Implement rule-first suggestions and confirmation**

Suggestions group by normalized stems only after matching taxonomy node, language, market, brand relation, and compatible intent. Store suggestions separately or return them transiently; only `confirm()` creates an active cluster.

- [ ] **Step 6: Run tests and commit**

```bash
php artisan test tests/Unit/KeywordOpportunityScoringServiceTest.php
php artisan test tests/Feature/KeywordClusterServiceTest.php
git add app/Services/GeoFlow/Keywords/KeywordClusterSuggestionService.php app/Services/GeoFlow/Keywords/KeywordClusterService.php app/Services/GeoFlow/Keywords/KeywordOpportunityScoringService.php app/Services/GeoFlow/Keywords/KeywordOpportunityScoreResult.php config/keyword_intelligence.php tests/Unit/KeywordOpportunityScoringServiceTest.php tests/Feature/KeywordClusterServiceTest.php
git commit -m "feat: cluster and score keyword opportunities"
```

### Task 7: Add Admin Routes, Import Wizard, Taxonomy, and Cluster Screens

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/Admin/KeywordIntelligenceController.php`
- Create: `app/Http/Requests/Admin/PreviewKeywordImportRequest.php`
- Create: `app/Http/Requests/Admin/ConfirmKeywordImportRequest.php`
- Create: `resources/views/admin/keyword-intelligence/index.blade.php`
- Create: `resources/views/admin/keyword-intelligence/import.blade.php`
- Create: `resources/views/admin/keyword-intelligence/taxonomy.blade.php`
- Create: `resources/views/admin/keyword-intelligence/clusters.blade.php`
- Create: `resources/views/admin/keyword-intelligence/cluster-detail.blade.php`
- Modify: `resources/views/admin/partials/sidebar.blade.php`
- Modify: `lang/zh_CN/admin.php`
- Modify: `lang/en/admin.php`
- Test: `tests/Feature/AdminKeywordIntelligenceTest.php`

**Interfaces:**
- Consumes Tasks 3–6 service contracts.
- Produces named routes under `admin.keyword-intelligence.*` without changing existing `admin.keyword-libraries.*` routes.

- [ ] **Step 1: Write authentication and route tests**

Assert every page redirects anonymous users, renders for authenticated admins, includes CSRF-protected forms, paginates large keyword lists, and preserves existing keyword-library navigation.

- [ ] **Step 2: Write preview/confirm behavior tests**

Assert preview creates no business records, confirm stores the upload under a generated private name and dispatches `ImportKeywordWorkbookJob`, invalid mapping returns localized validation errors, and revert requires explicit batch ID and completed status.

- [ ] **Step 3: Run feature tests and verify failure**

Run: `php artisan test tests/Feature/AdminKeywordIntelligenceTest.php`

Expected: FAIL with missing routes/controller/views.

- [ ] **Step 4: Add routes, request validation, and thin controller actions**

Routes must cover overview, keyword filters, import upload/preview/confirm/status/revert, taxonomy create/move/assign, clusters list/detail/confirm, and score recomputation. Controller actions call domain services and redirect or return JSON using existing admin conventions.

- [ ] **Step 5: Add localized Blade screens**

The import screen must visibly separate preview and confirm. The score screen shows every component, penalty, version, and metric period. Empty, importing, failed, completed, reverting, and reverted states each have distinct copy.

- [ ] **Step 6: Run admin and navigation regression tests**

Run:

```bash
php artisan test tests/Feature/AdminKeywordIntelligenceTest.php
php artisan test tests/Feature/AdminMaterialsPagesTest.php
php artisan test tests/Feature/AdminDashboardQuickStartTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/Admin/KeywordIntelligenceController.php app/Http/Requests/Admin/PreviewKeywordImportRequest.php app/Http/Requests/Admin/ConfirmKeywordImportRequest.php app/Http/Requests/Admin/StoreKeywordNodeRequest.php app/Http/Requests/Admin/MoveKeywordNodeRequest.php resources/views/admin/keyword-intelligence/index.blade.php resources/views/admin/keyword-intelligence/import.blade.php resources/views/admin/keyword-intelligence/taxonomy.blade.php resources/views/admin/keyword-intelligence/clusters.blade.php resources/views/admin/keyword-intelligence/cluster-detail.blade.php resources/views/admin/partials/sidebar.blade.php lang/zh_CN/admin.php lang/en/admin.php tests/Feature/AdminKeywordIntelligenceTest.php
git commit -m "feat: add keyword intelligence admin"
```

### Task 8: Validate the Two LITB Workbooks Without Importing Them

**Files:**
- Create: `tests/Feature/LitbKeywordWorkbookCompatibilityTest.php`
- Modify: `.gitignore` only if a narrow local fixture-report path is required
- Create: `storage/app/private/keyword-imports/.gitignore` if the directory contract requires a tracked placeholder

**Interfaces:**
- Consumes the read-only `KeywordImportPreview` contract from Task 3.
- Produces a compatibility report in test output only; no workbook or user data is committed.

- [ ] **Step 1: Write environment-gated compatibility tests**

Read workbook paths from `LITB_CATEGORY_KEYWORDS_XLSX` and `LITB_BRAND_KEYWORDS_XLSX`. Skip with an explicit message when absent. Assert both files produce a non-empty keyword mapping, recognized metric fields, valid samples, and bounded rejection counts.

```php
if (! is_file((string) env('LITB_CATEGORY_KEYWORDS_XLSX'))) {
    $this->markTestSkipped('LITB category workbook path is not configured.');
}
```

- [ ] **Step 2: Run compatibility tests against the supplied files**

Run:

```bash
LITB_CATEGORY_KEYWORDS_XLSX='/Users/litb/Documents/litb_geo/litb关键词/20260803 web search 品类词.xlsx' \
LITB_BRAND_KEYWORDS_XLSX='/Users/litb/Documents/litb_geo/litb关键词/品牌词 202600803.xlsx' \
php artisan test tests/Feature/LitbKeywordWorkbookCompatibilityTest.php
```

Expected: PASS and no rows added to keyword intelligence tables.

- [ ] **Step 3: Verify filesystem and database non-mutation**

Before and after the test, compare workbook SHA-256 values and assert keyword, metric, batch, and import-row counts are unchanged.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/LitbKeywordWorkbookCompatibilityTest.php storage/app/private/keyword-imports/.gitignore .gitignore
git commit -m "test: validate LITB keyword workbooks"
```

### Task 9: Full Verification and Second-Plan Handoff

**Files:**
- Modify: `README.md` only if keyword intelligence setup or the PhpSpreadsheet dependency requires operator documentation
- Create: `docs/keyword-intelligence.md`
- Test: all affected and full suites

**Interfaces:**
- Produces the documented `KeywordCluster` and import contracts consumed by the Prompt/Worker implementation plan.

- [ ] **Step 1: Document operator behavior**

Document preview versus confirm, private file storage, import batch lifecycle, safe revert rules, taxonomy invariants, cluster confirmation, score interpretation, queue requirement, and the fact that production workbooks are not committed.

- [ ] **Step 2: Run formatting and syntax checks**

Run:

```bash
./vendor/bin/pint --test
find app database/migrations tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: Pint reports no formatting changes required and every file reports `No syntax errors detected`. The repository has no configured static analyzer, so syntax checks plus PHPUnit are the recorded verification boundary.

- [ ] **Step 3: Run focused suites**

```bash
php artisan test tests/Feature/KeywordIntelligenceSchemaTest.php
php artisan test tests/Unit/KeywordNormalizerTest.php tests/Unit/KeywordClassifierTest.php
php artisan test tests/Unit/KeywordWorkbookReaderTest.php tests/Unit/KeywordColumnMapperTest.php
php artisan test tests/Feature/KeywordImportServiceTest.php tests/Feature/KeywordImportJobTest.php
php artisan test tests/Feature/KeywordTaxonomyServiceTest.php
php artisan test tests/Unit/KeywordOpportunityScoringServiceTest.php tests/Feature/KeywordClusterServiceTest.php
php artisan test tests/Feature/AdminKeywordIntelligenceTest.php
```

Expected: PASS.

- [ ] **Step 4: Run the complete suite**

Run: `php artisan test`

Expected: PASS with the final test count recorded in the handoff.

- [ ] **Step 5: Inspect routes and migrations**

Run:

```bash
php artisan route:list --json
php artisan migrate:fresh --env=testing
php artisan migrate:rollback --step=1 --env=testing
```

Expected: new admin routes appear under the configured admin prefix; migration and rollback succeed on the test database.

- [ ] **Step 6: Commit documentation and any verification-only corrections**

```bash
git add docs/keyword-intelligence.md README.md
git commit -m "docs: explain keyword intelligence workflow"
```

- [ ] **Step 7: Write the Prompt and Worker implementation plan**

Use `docs/superpowers/specs/2026-08-03-keyword-prompt-foundation-design.md` plus the now-implemented `KeywordCluster` interfaces. The next plan must cover Prompt modules/versions/recipes, compiler/renderer/validator, generation manifests, title association, task context, `auto_keywords`, and `auto_description`.
