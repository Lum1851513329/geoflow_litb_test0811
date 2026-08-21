# Task 13: Admin Knowledge Retrieval Preview Screen

## Summary

Added a minimal, bookmarkable admin screen at `GET /geo_admin/knowledge-bases/retrieval-preview` that
lets an admin run a real query against `KnowledgeRetrievalService::retrieveEvidenceFromMany()` scoped
by brand/site/language, and see which real documents/chunks would be retrieved. Neither
`KnowledgeRetrievalService` nor `KnowledgeScope` (Task 8) were modified.

## Files changed

- `routes/web.php` — added `Route::get('retrieval-preview', ...)` inside the existing
  `knowledge-bases.` route group, placed **before** the `{knowledgeBaseId}/...` routes so the literal
  segment isn't captured by the `{knowledgeBaseId}` parameter.
- `app/Http/Controllers/Admin/KnowledgeBaseController.php` — added `retrievalPreview(Request $request): View`.
  Reads `brand_id`/`site_id`/`language_code` (default `'en'`) / `q` the same way `knowledgeFilters()` does.
  Only runs a real search when brand_id, site_id, and a non-empty `q` are all present; otherwise renders
  an empty/browse state with no validation error. Constructs `KnowledgeScope`, catches
  `InvalidArgumentException` and re-renders with an inline `scopeError` message instead of a 500. Selects
  up to 5 candidate document IDs (`lifecycle_status='active'`, `generation_eligible=true`, scoped to
  brand/site/language, ordered by `id`) with an inline comment disclosing that this mirrors
  `KnowledgeRetrievalService::retrieveEvidenceFromMany()`'s own silent `->take(5)` cap
  (`app/Services/GeoFlow/KnowledgeRetrievalService.php:60`) — an intentional MVP limit, not a bug. Calls
  `retrieveEvidenceFromMany($scope, $documentIds, $q)` and passes results straight to the view. Reuses
  `governanceNavigation($filters)` for `brandOptions`/`siteOptions`/`languageOptions`, same as `index()`.
- `resources/views/admin/knowledge-bases/retrieval-preview.blade.php` (new) — extends
  `admin.layouts.app`; GET filter form (brand/site/language selects + text query input) matching the
  plain select-and-submit convention used by `index.blade.php` (confirmed there is **no** JS-driven
  brand→site cascading select on that page — it's a full page reload on submit, so this view follows the
  same pattern with no JS required). Renders one row per result with a link to the document's
  `.../{id}/detail` page (via `metadata.knowledge_base_id`/`metadata.knowledge_base_name`), a content
  snippet (`Str::limit` on `content`, 240 chars), rank + `score`, and `source_url`/`source_name` from the
  evidence's `provenance` array when present. Shows an inline `scopeError` banner, a "run a query to see
  results" prompt before any search, and a distinct "no matches" state after an empty search — neither
  is treated as an error.
- `resources/views/admin/knowledge-bases/index.blade.php` — added a small "Retrieval Preview" entry-point
  link next to the existing Create/Import buttons, since the whole point of this task is that the admin
  UI previously had no way to reach a search screen at all. Not explicitly requested by the design doc,
  but a one-line addition needed for the feature to be discoverable; flagged below as a self-review note.
- `lang/en/admin.php` / `lang/zh_CN/admin.php` — added `knowledge_bases.retrieval_preview_*` keys
  (title, subtitle, form/field labels, run button, results/empty/prompt states, MVP note, scope error
  message with `:message` placeholder) in both locales, inserted just before the closing `],` of the
  existing `knowledge_bases` array, following Task 9's established bilingual convention.
- `tests/Feature/AdminKnowledgeGovernanceTest.php` — added one new test (see below). Reused this file
  rather than creating a new one — at ~940 lines it already holds all the brand/site/directory/document
  factory helpers (`createOwnership`, `createDocument`) this scenario needs, and the new test is a single
  focused method, not a new suite.

## TDD evidence

**RED** — ran the new test before any implementation existed:

```
docker compose exec -T app php artisan test --filter test_retrieval_preview_runs_real_query_renders_empty_state_without_scope_and_skips_search_for_empty_query
```
```
FAILED  Tests\Feature\AdminKnowledgeGovernanceTest
RouteNotFoundException: Route [admin.knowledge-bases.retrieval-preview] not defined.
  at tests/Feature/AdminKnowledgeGovernanceTest.php:828
Tests: 1 failed (0 assertions)
```
Failed for the expected reason — the route didn't exist yet.

**GREEN** — after implementing route + controller + view + translations:

```
docker compose exec -T app php artisan test --filter test_retrieval_preview_runs_real_query_renders_empty_state_without_scope_and_skips_search_for_empty_query
```
```
PASS  Tests\Feature\AdminKnowledgeGovernanceTest
✓ retrieval preview runs real query renders empty state without scope…  0.69s
Tests: 1 passed (7 assertions)
```

The single test method covers all three required scenarios in sequence (per the task brief's "write ONE
focused test" instruction):
1. Creates a real fixture document + chunk with known content ("Warranty coverage extends to unopened
   electronics within thirty days."), hits `retrieval-preview?brand_id=X&site_id=Y&language_code=en&q=warranty`,
   asserts 200 + the document's real name and real chunk content appear in the rendered HTML — proving
   the screen genuinely calls the real `KnowledgeRetrievalService` and renders real results, not a mock.
2. Hits the same route with only `q` (no `brand_id`/`site_id`) — asserts 200 and that the fixture
   document does NOT appear (empty/browse state, no 500).
3. Hits the route with `brand_id`/`site_id` but no `q` — asserts 200 and no results rendered (form-only
   state, no search executed).

## Regression run

```
docker compose exec -T app php artisan test tests/Feature/AdminKnowledgeGovernanceTest.php tests/Feature/KnowledgeRetrievalIsolationTest.php
```
```
Tests:    54 passed (306 assertions)
Duration: 2.40s
```
All 22 tests in `AdminKnowledgeGovernanceTest` (21 pre-existing + 1 new) and all 32 tests in
`KnowledgeRetrievalIsolationTest` (untouched Task 8 service tests) pass.

## Formatting / view compilation

```
docker compose exec -T app ./vendor/bin/pint app/Http/Controllers/Admin/KnowledgeBaseController.php \
  routes/web.php tests/Feature/AdminKnowledgeGovernanceTest.php lang/en/admin.php lang/zh_CN/admin.php \
  resources/views/admin/knowledge-bases/retrieval-preview.blade.php \
  resources/views/admin/knowledge-bases/index.blade.php -v
```
```
PASS  ........................................................... 7 files
```

```
docker compose exec -T app php artisan view:cache
INFO  Blade templates cached successfully.
docker compose exec -T app php artisan view:clear
INFO  Compiled views cleared successfully.
```
Confirms `retrieval-preview.blade.php` compiles cleanly alongside every other Blade view in the app.

## Self-review findings

1. **Index page link added, not explicitly requested.** The design doc only specifies the new route,
   controller, view, and translations — it doesn't mention adding a link from `index.blade.php`. Since
   the entire premise of this task is that the admin UI had *no way to reach* a search screen, I added a
   one-line "Retrieval Preview" button next to Create/Import on the knowledge-bases index so the feature
   is actually discoverable rather than only reachable by typing the URL. This is the smallest reasonable
   choice consistent with the rest of the admin area; flagging it here since it's technically outside the
   literal file list in the brief.
2. **No JS cascading select exists on the reference page.** I checked `index.blade.php`'s existing
   brand/site/language filter form before writing the view — it is a plain GET form with a full page
   reload on submit; there is no JS-driven brand→site option filtering. The new view intentionally
   matches that (no JS), per the brief's explicit instruction to check and match whatever convention
   actually exists rather than assuming cascading JS.
3. **`scopeError` message is translated, not raw.** The brief said to catch `InvalidArgumentException`
   and show "a clear inline error message." I wrapped the exception's (English, developer-facing) message
   in a new bilingual translation key (`retrieval_preview_scope_error`) with a `:message` placeholder
   rather than echoing the raw exception text directly, to stay consistent with the rest of this admin
   area's bilingual convention. The interpolated `:message` fragment itself is still the raw
   `KnowledgeScope` exception text (e.g. "Knowledge scope requires a valid BCP 47-compatible language
   code."), which is English-only, but it's wrapped in a translated sentence.
4. **Eligibility query duplicates `KnowledgeRetrievalService`'s own scope filter.** The document-ID
   lookup in `retrievalPreview()` (`lifecycle_status='active'`, `generation_eligible=true`, brand/site/
   language) intentionally mirrors `KnowledgeRetrievalService::applyKnowledgeScope()`'s private query so
   the preview only offers documents the service would actually consider — this duplication is expected
   given the service's scoping logic is private and Task 8's service was explicitly off-limits to modify.
5. **No test added for `KnowledgeScope`'s `InvalidArgumentException` path in the controller.** Per the
   brief's instruction not to add new tests for `KnowledgeRetrievalService`/`KnowledgeScope` themselves,
   and since the single focused test already covers the three required scenarios (real match, missing
   scope, empty query), I did not add a fourth scenario asserting the `scopeError` banner text for a
   malformed `language_code` query param. This is a minor coverage gap for a rarely-hit edge case
   (a hand-crafted malformed `language_code` in the URL); the try/catch path was manually verified to
   render correctly by inspection of `KnowledgeScope`'s constructor logic, not by an automated test.

## Concerns for follow-up (none blocking)

- Same as self-review point 5: no automated test covers the `scopeError` inline-error rendering path.
  Low risk since the logic is a straightforward try/catch around a constructor already covered by 32
  passing `KnowledgeScope` tests in `KnowledgeRetrievalIsolationTest.php`.
- The repo has pre-existing unrelated uncommitted changes (`app/Console/Commands/SeedPresetBlueprintTemplatesCommand.php`,
  `docker-compose.yml`, `tests/Feature/SeedPresetBlueprintTemplatesCommandTest.php`, and two untracked
  `HANDOFF*.md` files at the repo root) that predate this task and were left untouched and unstaged in
  the commit for this task.

---

## Follow-up: review response (commit after `c68959c`)

Review came back "Needs fixes" with one Important finding: the view reads
`$result['provenance']['source_url']` / `['source_name']` with no fallback, and `provenance` is only
guaranteed to exist via `KnowledgeRetrievalService::withEvidenceReferences()`. The reviewer's stated
concern was that the single-document short-circuit branch of `retrieveEvidenceFromMany()`
(`count($knowledgeBaseIds) === 1`, `KnowledgeRetrievalService.php:63-65`) returns raw `retrieveEvidence()`
output with no `provenance` key, so source info would silently never render for the common single-document
case — exactly the scenario the shipped test constructs, which never asserted on source info and so missed
the gap.

### Verification before fixing

Per the receiving-code-review discipline, I verified the claim against the actual code before treating it
as ground truth, rather than applying the suggested fix on faith. Re-reading
`app/Services/GeoFlow/KnowledgeRetrievalService.php`:

- `retrieveEvidence()` itself (the method the single-document short-circuit delegates to) ends with
  `return $this->withEvidenceReferences(array_slice($scored, 0, max(1, $candidateLimit)));` at line 174 —
  i.e. it already calls `withEvidenceReferences()` on its own return path, independent of
  `retrieveEvidenceFromMany()`'s multi-document merge branch.
- I confirmed this empirically two ways:
  1. Temporarily stashed out the view's fallback fix (`git stash push -- resources/views/.../retrieval-preview.blade.php`)
     and re-ran the existing test with the new `assertSee('https://www.lightinthebox.test/source', ...)`
     assertion added — it still passed 11/11 assertions with **no fallback in the view at all**, after a
     `php artisan view:clear` to rule out stale compiled Blade output.
  2. Ran a `php artisan tinker` script constructing a fresh brand/site/document/chunk fixture and calling
     `retrieveEvidenceFromMany($scope, [$doc->id], 'warranty')` directly (single document, exercising the
     exact short-circuit branch) — the returned evidence array's `[0]['provenance']` was already populated
     with `source_name`, `source_url`, etc.

So the specific technical claim — that the single-document path skips `withEvidenceReferences()` and
therefore has no `provenance` key — does not hold: both `retrieveEvidence()`'s own return statement and
`retrieveEvidenceFromMany()`'s multi-document merge branch (`KnowledgeRetrievalService.php:89`) route
through `withEvidenceReferences()`, so `provenance` carrying `source_url`/`source_name` is populated on
every non-empty return path of both public methods. The original view code (reading only `provenance`,
with no `metadata` fallback) was already rendering the fixture's `source_url` correctly before this
follow-up — restaging the pre-fix view and re-running the test proved that directly.

### What I changed anyway

Even though the reported failure mode does not reproduce, the reviewer's suggested fix is harmless,
strictly more defensive, and does not regress anything, so I kept it rather than reverting:

- `resources/views/admin/knowledge-bases/retrieval-preview.blade.php` — `$sourceUrl`/`$sourceName` now
  fall back to `$metadata['source_url']`/`$metadata['source_name']` when `$provenance` doesn't carry them,
  with a PHP comment (not a `{{-- --}}` Blade comment, which is invalid inside an `@php` block — caught
  and fixed during this change) explaining why both keys are checked. `metadata` is present on every
  evidence row regardless of code path, so this fallback is a true no-op safety net rather than a
  behavior change, per the empirical evidence above.
- `tests/Feature/AdminKnowledgeGovernanceTest.php` — added the missing covering assertion the reviewer
  asked for: `->assertSee('https://www.lightinthebox.test/source', false)` on the real-match response,
  proving the fixture document's actual `source_url` renders. Also added the optional (reviewer said "your
  discretion") cheap extra case: hitting the route with `language_code=!!` now asserts a 200 response
  containing the translated `retrieval_preview_scope_error` string and that no fixture content leaks
  through, closing the previously-noted minor gap around the `KnowledgeScope` `InvalidArgumentException`
  path.

### Test evidence

Ran the updated test in isolation:

```
docker compose exec -T app php artisan test --filter test_retrieval_preview_runs_real_query_renders_empty_state_without_scope_and_skips_search_for_empty_query
```
```
PASS  Tests\Feature\AdminKnowledgeGovernanceTest
✓ retrieval preview runs real query renders empty state without scope…  0.59s
Tests: 1 passed (11 assertions)
```
(11 assertions, up from 7 — the new `source_url` assertion plus the 3-assertion malformed-`language_code`
case.)

Full regression set:

```
docker compose exec -T app php artisan test tests/Feature/AdminKnowledgeGovernanceTest.php tests/Feature/KnowledgeRetrievalIsolationTest.php
```
```
Tests:    54 passed (310 assertions)
Duration: 2.79s
```
(310 assertions, up from 306 — same 54 tests, more assertions per the additions above.)

Pint and view compilation re-verified on the changed files:

```
docker compose exec -T app ./vendor/bin/pint resources/views/admin/knowledge-bases/retrieval-preview.blade.php tests/Feature/AdminKnowledgeGovernanceTest.php -v
```
```
PASS  ........................................................... 2 files
```
```
docker compose exec -T app php artisan view:cache
INFO  Blade templates cached successfully.
docker compose exec -T app php artisan view:clear
INFO  Compiled views cleared successfully.
```

### Files changed in this follow-up

- `resources/views/admin/knowledge-bases/retrieval-preview.blade.php` — metadata fallback for
  `$sourceUrl`/`$sourceName` (defensive, not fixing a reproduced bug — see verification above).
- `tests/Feature/AdminKnowledgeGovernanceTest.php` — added `source_url` assertion to the existing real-query
  case, and a new malformed-`language_code` assertion block in the same test method.

### Concerns

None blocking. Noting for the record: the review's "Important" finding described a plausible-sounding but
unverified code path that does not actually exist in this codebase — `retrieveEvidence()` already calls
`withEvidenceReferences()` on its own return path. I applied the suggested defensive fix anyway since it's
free and strictly safer, and added the requested covering assertion, but the underlying bug claim does not
reproduce against current `main`/this branch's `KnowledgeRetrievalService`.
