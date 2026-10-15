# Brand-scoped Knowledge Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Unify task, brief and spreadsheet knowledge selection under an explicit brand boundary.
**Architecture:** A scope resolver and bounded evidence selector serve every entry point. Scope is persisted on tasks/briefs; evidence is revalidated and snapshotted at reservation; legacy records retain explicit-library mode.
**Tech Stack:** Laravel, PHP, PostgreSQL, Blade, PhpSpreadsheet; Docker PHP tests with SQLite memory database.
**Spec:** docs/superpowers/specs/2026-08-26-brand-scoped-knowledge-generation-design.md

## Global Constraints

- New UI records default brand_auto; old records remain legacy_manual; never infer a historical brand.
- Enforce brand/site/language/descendant directory and reviewed/active/generation_eligible before retrieval and snapshots. review_due_at before today is treated as stale; effective_date after today is excluded.
- KnowledgeSite is a source, DistributionChannel is a publishing destination.
- Default automatic budget: 12 whole chunks, 24,000 characters; manual ID requests max 50 and same content budget.
- No cross-brand, cross-site or cross-language fallback. No evidence means no paid generation in brand_auto.
- No live model calls in tests, no production publication, no remote pushes or corpus deletion.
- Preserve pre-existing edits in SeedPresetBlueprintTemplatesCommand.php, its test, docker-compose.yml, and untracked handoffs.
- Worktree: /Users/litb/Documents/litb_geo/GEOFlow/.worktrees/keyword-intelligence; Docker geoflow-app mounts it. Baseline focused suite: 44 passed.

## File and interface map

- `app/Services/GeoFlow/KnowledgeGovernance/GenerationKnowledgeScopeResolver.php`: normalize modes, IDs, source-site selection and descendant scope; query eligibility.
- `app/Services/GeoFlow/BrandKnowledgeEvidenceSelector.php`: bounded retrieval, relevance, dedup, inclusion/exclusion and provenance; no writes.
- Migration and Task/ContentBrief: durable scope; ContentGenerationRun: retrieval audit JSON; legacy worker audit through existing execution storage.
- Generation reservation/snapshot and worker: unified service integration and idempotency.
- Controllers/requests/Blade: brand selection and evidence preview; parser/template/service: new and legacy imports.

### Task 1: Scope storage and bounded evidence selection

Files: create `database/migrations/2026_08_26_000001_add_generation_knowledge_scope.php`, resolver/selector above, `tests/Feature/BrandKnowledgeEvidenceSelectorTest.php`; modify `app/Models/{Task,ContentBrief,ContentGenerationRun}.php`, `app/Services/GeoFlow/KnowledgeRetrievalService.php` only if shared scoring needs a bounded candidate entry point.

Interfaces produced:
```php
// GenerationKnowledgeScopeResolver
public function normalize(array $data, string $defaultMode = 'legacy_manual'): array;
// returns knowledge_mode, knowledge_brand_id, knowledge_site_id, knowledge_directory_id;
// brand_auto resolves unique source site; rejects missing/ambiguous/mismatched scope.
public function resolve(array $data, string $languageCode = 'en'): KnowledgeScope;
public function eligibleQuery(KnowledgeScope $scope): \Illuminate\Database\Eloquent\Builder;
// includes descendants; never requires keyword-cluster association.
// BrandKnowledgeEvidenceSelector
public function select(KnowledgeScope $scope, string $query, array $includeIds = [], array $excludeIds = []): array;
// returns ['chunk_ids'=>list<int>, 'evidence'=>list<array>, 'query'=>string, 'scope'=>array,
// 'warnings'=>list<string>]; empty result is allowed for previews.
public function validateSelection(KnowledgeScope $scope, array $ids): void;
// throws ValidationException for ineligible/missing/duplicate IDs or budget excess.
```

- [x] Write failing tests catching brand/site leakage, unrelated results, library-six truncation, descendant and eligibility filtering, unique-site resolution, malformed IDs, duplicates, oversized chunks and exclusions. Use existing governance fixture conventions and real database queries.
```php
$selection = app(BrandKnowledgeEvidenceSelector::class)->select($scope, 'linen dress');
$this->assertSame([$allowedChunk->id], $selection['chunk_ids']);
$this->assertNotContains($otherBrandChunk->id, $selection['chunk_ids']);
```
- [x] Run `docker exec geoflow-app php artisan test --compact --filter=BrandKnowledgeEvidenceSelectorTest`; record expected missing-feature failure.
- [x] Implement migration with default legacy_manual; nullable scope IDs and integer model casts; `knowledge_retrieval_snapshot` JSON on generation runs. Normalize IDs strictly, query brand/site relationship, traverse only same-brand descendants with cycle protection. Add selector with deterministic stable ranking, normalize/hash content, exclude metadata-only candidates, full-scope candidate query (no per-library first-five truncation), bounded DB candidate load and final budgets. Use existing lexical scoring where suitable; never enable uncalibrated vector-only matches; record this conservative fallback in warnings when applicable.
```php
return KnowledgeBase::query()->where('brand_id', $scope->brandId)
    ->where('site_id', $scope->siteId)->where('language_code', $scope->languageCode)
    ->where('review_status', 'reviewed')->where('lifecycle_status', 'active')
    ->where('generation_eligible', true);
```
- [x] Run new test and `KnowledgeRetrievalIsolationTest|KnowledgeOwnershipSchemaTest`; record green, format only touched files with vendor/bin/pint.
- [x] Commit only owned files; self-review and report TDD evidence and exact contracts.

### Task 2: Generation, task lifecycle and immutable snapshots

Files: modify `app/Domain/ContentGeneration/{ContentGenerationRequestService,GenerationEvidenceSnapshotter}.php`, `app/Domain/ContentStrategy/ContentBriefQuickCreateService.php`, `app/Services/GeoFlow/{WorkerExecutionService,TaskLifecycleService}.php`; create `tests/Feature/BrandScopedGenerationTest.php`. Read existing run/task tests to reuse realistic fixtures.

Consumes resolver/selector contracts from Task 1. Produces persisted task scope, scope copied to generated brief, run knowledge_retrieval_snapshot, and scope-checked evidence snapshots.

- [x] Write red tests: direct reserve retrieves relevant allowed chunks; no evidence rolls back run/dispatch; explicit free-text or foreign IDs rejected; preview becoming stale rejected; replay retains prior snapshot despite KB update; task scope survives lifecycle persistence and quick-create; legacy IDs remain compatible and mixed-brand legacy selection is rejected.
```php
$result = $service->reserve($brief, $request, $admin);
$this->assertSame([$chunk->id], $result['run']->evidence()->pluck('knowledge_chunk_id')->all());
// A replay after source edit must still contain the previous snapshot text.
```
- [x] Run `docker exec geoflow-app php artisan test --compact --filter=BrandScopedGenerationTest` and record red.
- [x] In reserve, do replay detection before retrieval; then resolve locked brief scope, compose query from topic/core_question/primary keyword/cluster keywords, select evidence using request `knowledge_chunk_ids` additions and `excluded_knowledge_chunk_ids`; forbid `evidence` in brand_auto; persist result audit, require nonempty, revalidate at snapshot. Include additions/exclusions in fingerprint without fingerprinting mutable retrieval results.
```php
if ($lockedBrief->knowledge_mode === 'brand_auto') {
    $scope = $resolver->resolve($lockedBrief->getAttributes(), $lockedBrief->language_code);
    $selection = $selector->select($scope, $query, $includeIds, $excludeIds);
    if ($selection['chunk_ids'] === []) {
        throw ValidationException::withMessages(['knowledge_chunk_ids' => '该品牌内证据不足']);
    }
}
```
- [x] Snapshotter rechecks scoped parent records under lock before copying content; include source URL, scope IDs, directory, language, version, dates/hash and selection provenance. Reject scope bypass on direct snapshot calls. Preserve old snapshots/idempotent replays.
- [x] TaskLifecycle normalizes/persists mode/scope; brand_auto clears explicit library pivot. QuickCreate stores scope with language. Worker quality branch bypasses legacy first-six and external URL evidence in brand_auto; copies scope and invokes reserve. Legacy-generation branch uses same selector and persists immutable evidence audit with worker execution prior to model call; missing evidence is clear failure. Validate legacy explicit IDs share a scope before mixing.
- [x] Run new tests plus task-driven generation, content generation and worker suites selected by test file names; record results, commit owned files, report.

### Task 3: Brand-aware task and brief admin forms

Files: `app/Http/Controllers/Admin/{TaskController,ContentBriefController}.php`, `app/Http/Requests/Admin/{StoreContentBriefRequest,RequestContentGenerationRequest}.php` (locate actual namespace/path first), task and brief views under `resources/views/admin/`, existing admin route file if adding POST preview; create `tests/Feature/BrandScopedKnowledgeAdminTest.php`. Add a shared Blade partial for mode/brand/site/directory fields and shared controller form-options helper only if needed by both controllers.

Consumes resolver normalize/resolve and selector select; generation request additions/exclusions from Task 2. Produces all UI fields and scoped preview without model invocation.

- [x] Write red HTTP tests: new forms offer brand_auto; old edit preserves mode; mismatched IDs fail; posted scope persists; preview excludes other brand/unreviewed chunks; selection reset on scope changes; new brief can generate without prior preview; legacy forms still work.
```php
$response = $this->actingAs($admin, 'admin')->post($createUrl, $validForm);
$response->assertSessionHasNoErrors();
$this->assertDatabaseHas('content_briefs', ['knowledge_mode'=>'brand_auto', 'knowledge_brand_id'=>$brand->id]);
```
- [x] Run `docker exec geoflow-app php artisan test --compact --filter=BrandScopedKnowledgeAdminTest` for red.
- [x] Add server-side scope validation using resolver, preserve old programmatic payload compatibility but render explicit brand_auto in new forms. Site defaults only when unique; directory dropdown includes brand filter. Use escaped labels/source links. Brand mode hides legacy max-five copy. Scope changes clear preview selections. Brief evidence display uses selector result with warnings/provenance, same-range manual additions and exclusions, no global latest-100 evidence. Preview is read-only; user action re-runs current retrieval. Bind UI to backend fields and preserve errors/old input.
- [x] Run new HTTP tests plus existing task/brief controller tests. Compile Blade with `docker exec geoflow-app php artisan view:cache`; remove compiled cache afterward via artisan view:clear. Commit owned files, report.

### Task 4: Brand-based bulk spreadsheet import and end-to-end verification

Files: `app/Domain/ContentGeneration/{BulkContentImportTemplateBuilder,BulkContentImportParser,BulkContentImportRow,BulkContentImportService}.php`, `resources/views/admin/bulk-content-import/index.blade.php`; extend `tests/Unit/BulkContentImportParserTest.php`, `tests/Feature/{BulkContentImportTemplateBuilderTest,BulkContentImportControllerTest}.php`, add cross-entry tests if needed.

Consumes scope contracts and task persistence from Tasks 1–2. Row knowledgeBaseId becomes nullable with backward-compatible constructor trailing scope fields; groupKey includes mode and full resolved knowledge scope.

- [x] Write red tests for new template required brand column/reference keys, brand unique-site resolution, optional descendant directory, ambiguity rejection, mixed brand/knowledge_base row rejection, legacy template acceptance, all-or-nothing failures, distinct brand grouping even same destination/preset/category.
```php
$this->assertNotSame($rowForBrandA->groupKey(), $rowForBrandB->groupKey());
$this->assertDatabaseHas('tasks', ['knowledge_mode'=>'brand_auto', 'knowledge_brand_id'=>$brand->id]);
```
- [x] Run `docker exec geoflow-app php artisan test --compact --filter=BulkContentImport` and record red.
- [x] Build new headers `site,preset,category,topic,brand,directory,knowledge_site,core_question,primary_keyword,reference_urls`; retain parser support for old `knowledge_base`. Resolve key or exact unambiguous name (never first duplicate). Reject conflicting row mode. Carry normalized scope to lifecycle without explicit libraries for brand mode. Group by resolved mode/brand/site/directory or legacy library ID; transactional validation before creation. Update help text to distinguish knowledge source from publishing destination, and state product media is not imported.
- [x] Re-run bulk tests and cross-entry regression tests. Run full suite once and compare any failures to recorded baseline before classifying regressions; no live generation. Commit owned files and report exact test outcomes.

## Completion

- [x] Review complete feature diff independently; fix significant findings and re-run affected tests.
- [x] Check migration status and apply only the new migration to local dev after a scoped backup; do not run pending unrelated migrations/seeders.
- [x] Verify local task/create, brief and bulk pages via safe HTTP checks; no production actions or paid jobs. Document navigation and migration result, tests and any unresolved limitations.
- [x] Mark plan checkboxes from actual evidence and hand off without claiming unverified generation/publication.

Outcome: feature and independent review complete through `e49090d`. Final suite:2111 passed,6 pre-existing failures,2 skipped; no new failures. Local migration and read-only runtime checks passed. No production generation/publication or remote push. Merge gate remains blocked by the existing non-green suite; branch/worktree and diagnostic scratch retained. See `docs/operations/brand-scoped-knowledge-verification-2026-08-26.md` for evidence and limitations.
