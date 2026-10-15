# Brand-Agnostic Semrush and External Evidence Routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in the `keyword-intelligence` worktree.

**Goal:** Make Semrush imports brand-agnostic and make generic Semrush topics use external evidence by default without pulling unrelated brand knowledge.

**Architecture:** Keep the existing upload-preview-confirm and bulk-task pipeline, but replace the hard-coded `SemrushTopicGapConverter` rules with a DB-backed `SemrushBrandProfileResolver` and a generic topic classifier. Persist the derived knowledge mode and evidence settings in the existing task/brief fields, extending the accepted enum and validation paths only where required. Keep local knowledge selection brand-scoped and let `ContentGenerationCoordinator` choose general versus official external source scope from a frozen run snapshot.

**Tech Stack:** Laravel/PHP 8.x, Eloquent, PHPUnit/Pest tests already used by GEOFlow, PhpSpreadsheet, PostgreSQL JSON fields, existing external-evidence planner/provider.

**Spec:** `.planning/2026-10-14-geoflow-claude-handover-audit/approved-design-spec.md`

## Global Constraints

- Implement only in `/Users/litb/Documents/litb_geo/GEOFlow/.worktrees/keyword-intelligence`.
- Preserve all existing uncommitted Claude changes; do not reset or overwrite unrelated files.
- Do not rewrite historical Tasks or generation runs.
- Do not embed LightInTheBox category, preset, competitor, or rejection constants in the generic converter.
- Explicit user selection `external_evidence_policy=off` must remain honored.
- Unknown or ambiguous brand/site/category/preset mappings must be quarantined or rejected with an actionable reason, never guessed.
- Preview and confirm must use the same brand-profile snapshot.

## Review Focus

- A generic topic such as “how to choose running shoes” must resolve to `knowledge_mode=general`, `on_insufficient`, and general sources; test in Task 2 and Task 4.
- A LightInTheBox policy topic must remain brand-scoped and local-first; test in Task 2 and Task 5.
- An AceGolf product topic must resolve with AceGolf's site/profile, not LITB categories or presets; test in Task 1 and Task 3.
- A missing or ambiguous profile mapping must remain visible in preview and block confirmation; test in Task 3.
- A preview created before a profile change must confirm with the original snapshot; test in Task 3.

### Task 1: Add profile and topic-resolution services

**Files:**
- Create: `app/Services/GeoFlow/Semrush/SemrushBrandProfileResolver.php`
- Create: `app/Services/GeoFlow/Semrush/SemrushTopicClassifier.php`
- Modify: `app/Services/GeoFlow/Semrush/SemrushTopicGapConverter.php`
- Test: `tests/Unit/Semrush/SemrushBrandProfileResolverTest.php`
- Test: `tests/Unit/Semrush/SemrushTopicClassifierTest.php`
- Test: `tests/Unit/Semrush/SemrushTopicGapConverterTest.php`

**Interfaces:**
- `SemrushBrandProfileResolver::resolve(int $brandId, ?int $siteId = null): array` returns `brand`, `site`, `directories`, `presets`, `official_domains`, `general_domains`, `aliases`, and a stable `snapshot_hash`.
- `SemrushTopicClassifier::classify(array $row, array $brandProfile): array` returns `knowledge_mode` (`brand|general|mixed`), `content_type`, `category_hint`, `directory_required`, and `reason_codes`.
- `SemrushTopicGapConverter::convert(string $csvPath, int $limit, int $brandId, ?int $siteId = null): array` returns accepted rows carrying `knowledge_mode`, `content_type`, `category`, `directory`, `preset_key`, `external_evidence_policy`, `external_general_domains`, and profile metadata, plus quarantined rows with reasons.

- [ ] **Step 1: Write failing profile-resolution tests**

```php
it('resolves different profiles without LITB constants', function () {
    $litb = app(SemrushBrandProfileResolver::class)->resolve(1);
    $acegolf = app(SemrushBrandProfileResolver::class)->resolve(4);

    expect($litb['brand']['key'])->toBe('lightinthebox')
        ->and($acegolf['brand']['key'])->toBe('acegolf')
        ->and($acegolf['site']['canonical_host'])->not->toBe('www.lightinthebox.com');
});
```

- [ ] **Step 2: Run the focused tests and verify they fail because the services do not exist**

Run: `docker compose exec -T app php artisan test tests/Unit/Semrush/SemrushBrandProfileResolverTest.php tests/Unit/Semrush/SemrushTopicClassifierTest.php tests/Unit/Semrush/SemrushTopicGapConverterTest.php`

Expected: FAIL with missing-class or missing-method errors.

- [ ] **Step 3: Implement profile loading and snapshotting**

Load `KnowledgeBrand`, its selected/unique `KnowledgeSite`, brand-owned `KnowledgeDirectory` records, and production `PromptPreset` blueprints. Build mappings from existing configured names/keys; never use LITB literals. Include configured official/general domains and a normalized profile hash in the returned array. Throw a validation exception for unknown brands or ambiguous sites.

- [ ] **Step 4: Implement topic classification**

Use normalized Semrush `topic`, `primary_keyword`, intents, and known brand aliases. Classify explicit brand names, policy/service terms, and product terms as `brand`; educational/how-to, shopping advice, sizing, market, and industry terms without a brand entity as `general`; rows containing both general decision language and brand facts as `mixed`. Return deterministic reason codes for preview display.

- [ ] **Step 5: Replace converter constants with profile/classifier routing**

Keep CSV schema detection, grouping, scoring, and duplicate behavior. Remove the LITB-only `CATEGORY_RULES`, `CATEGORY_PRESET_MAP`, and regex blocklists from the generic path. Use profile aliases/exclusions where configured, generic safety exclusions only for clearly unusable content, classifier content-type routing, then profile mappings, then intent fallback. Include `quarantined` rows instead of silently assigning an unknown category or preset.

- [ ] **Step 6: Run focused tests and commit**

Run the command from Step 2. Expected: PASS for LightInTheBox, AceGolf, generic mode, and quarantine cases. Commit: `git add app/Services/GeoFlow/Semrush tests/Unit/Semrush && git commit -m "feat: make semrush conversion brand agnostic"`.

### Task 2: Support general/mixed knowledge modes in governance and evidence preparation

**Files:**
- Modify: `app/Services/GeoFlow/KnowledgeGovernance/GenerationKnowledgeScopeResolver.php`
- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationRequestService.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Modify: `app/Domain/ContentGeneration/GenerationEvidenceSnapshotter.php`
- Modify: `app/Services/GeoFlow/TaskLifecycleService.php`
- Modify: `app/Http/Controllers/Admin/TaskController.php`
- Modify: `app/Http/Requests/Admin/StoreContentBriefRequest.php`
- Modify: `app/Http/Requests/Admin/ConfirmSemrushImportRequest.php`
- Test: `tests/Unit/KnowledgeGovernance/GenerationKnowledgeScopeResolverTest.php`
- Test: `tests/Feature/ContentGeneration/ExternalEvidenceRoutingTest.php`

**Interfaces:**
- `knowledge_mode` accepts existing `legacy_manual`, `brand_auto`, plus `general` and `mixed`.
- A general/mixed scope must not require a brand ID for local retrieval; brand facts inside mixed evidence remain explicitly scoped in the evidence request.
- `ContentGenerationCoordinator` must derive `source_scope=general` for general claims and `source_scope=official` only for brand claims.

- [ ] **Step 1: Add failing tests for mode validation and source scope**

```php
it('allows general mode without a brand scope', function () {
    $normalized = app(GenerationKnowledgeScopeResolver::class)->normalize([
        'knowledge_mode' => 'general',
    ]);
    expect($normalized['knowledge_mode'])->toBe('general');
});

it('routes generic external claims to general scope', function () {
    // Arrange a run snapshot with knowledge_mode=general and on_insufficient.
    // Assert the planner request contains source_scope=general and no LITB official-only restriction.
});
```

- [ ] **Step 2: Run focused tests and verify the new expectations fail**

Run: `docker compose exec -T app php artisan test tests/Unit/KnowledgeGovernance/GenerationKnowledgeScopeResolverTest.php tests/Feature/ContentGeneration/ExternalEvidenceRoutingTest.php`

Expected: FAIL because only `legacy_manual` and `brand_auto` are accepted and source scope is currently derived from `brand_auto` versus non-`brand_auto` only.

- [ ] **Step 3: Extend normalization and lifecycle validation**

Allow `general` and `mixed`; keep brand/site/directory validation mandatory only for `brand_auto` and for the brand-fact portion of `mixed`. Ensure task and brief snapshots preserve the mode. Do not force `general` through `GenerationKnowledgeScopeResolver::resolve()` or `BrandKnowledgeEvidenceSelector`.

- [ ] **Step 4: Update evidence preparation**

For `general`, skip brand-local retrieval and use general external evidence when policy is `on_insufficient`. For `mixed`, retain local brand retrieval only for the explicit brand scope and request general sources for general claims. Freeze the effective mode and domain lists in the run snapshot.

- [ ] **Step 5: Run regression tests and commit**

Run: `docker compose exec -T app php artisan test tests/Unit/EvidenceCitationRendererTest.php tests/Feature/ContentGeneration/ExternalEvidenceRoutingTest.php tests/Unit/KnowledgeGovernance/GenerationKnowledgeScopeResolverTest.php`

Expected: PASS. Commit: `git add app tests && git commit -m "feat: route generic topics through general evidence"`.

### Task 3: Integrate brand profiles into Semrush preview/confirm

**Files:**
- Modify: `app/Services/GeoFlow/Semrush/SemrushImportPreviewSession.php`
- Modify: `app/Http/Controllers/Admin/SemrushImportController.php`
- Modify: `app/Http/Requests/Admin/PreviewSemrushImportRequest.php`
- Modify: `app/Http/Requests/Admin/ConfirmSemrushImportRequest.php`
- Modify: `resources/views/admin/bulk-content-import/index.blade.php`
- Modify: `lang/en/admin.php`
- Modify: `lang/zh_CN/admin.php`
- Test: `tests/Feature/Admin/SemrushImportControllerTest.php`

**Interfaces:**
- Preview request requires `knowledge_brand_id`; optional `knowledge_site_id` is validated against that brand.
- Preview token payload includes `brand_profile_snapshot_hash` and normalized derived options.
- Confirm rejects a stale or mismatched snapshot and never re-converts using a different brand profile.

- [ ] **Step 1: Write failing preview/confirm tests**

Test cases:

1. AceGolf CSV preview produces AceGolf category/preset/site values.
2. A generic topic preview shows `general` and `on_insufficient`.
3. An unknown/ambiguous mapping appears in `quarantined` and cannot be kept for confirmation.
4. Changing the profile after preview causes confirm to reject the stale snapshot.

- [ ] **Step 2: Run the feature tests and verify failure**

Run: `docker compose exec -T app php artisan test tests/Feature/Admin/SemrushImportControllerTest.php`

Expected: FAIL until the token, controller, and view carry profile/mode data.

- [ ] **Step 3: Pass brand/site/profile options into the converter**

Inject `SemrushBrandProfileResolver` into the preview session, normalize the selected brand/site once, and pass IDs to `SemrushTopicGapConverter::convert()`. Store the profile hash and effective mode defaults in the encrypted token.

- [ ] **Step 4: Build the generated spreadsheet with per-row mode/evidence values**

Add the fields supported by `BulkContentImportParser` for knowledge mode, evidence policy, and general domains. If the current spreadsheet schema cannot represent a per-row value, extend the parser and template together rather than collapsing all rows to one mode.

- [ ] **Step 5: Update UI and translations**

Show profile-derived mode, category, preset, evidence policy, and quarantine reason in the preview. Default the evidence-policy control to `on_insufficient`, while preserving an explicit `off` option. Do not display LightInTheBox-specific copy for other brands.

- [ ] **Step 6: Run feature tests and commit**

Run the command from Step 2. Expected: PASS. Commit: `git add app resources/views lang tests && git commit -m "feat: make semrush preview brand aware"`.

### Task 4: Make bulk import and task creation preserve derived row settings

**Files:**
- Modify: `app/Domain/ContentGeneration/BulkContentImportParser.php`
- Modify: `app/Domain/ContentGeneration/BulkContentImportService.php`
- Modify: `app/Services/GeoFlow/TaskLifecycleService.php`
- Modify: `app/Domain/ContentStrategy/ContentBriefQuickCreateService.php`
- Modify: `app/Models/Task.php`
- Modify: `app/Models/ContentBrief.php`
- Test: `tests/Feature/Admin/BulkContentImportServiceTest.php`

**Interfaces:**
- Parsed rows expose `knowledgeMode`, `knowledgeBrandId`, `knowledgeSiteId`, `knowledgeDirectoryId`, `externalEvidencePolicy`, and `externalGeneralDomains`.
- Groups with different knowledge modes or evidence settings must not be silently merged into one Task.

- [ ] **Step 1: Write failing grouping tests**

Create two rows for the same site/preset/category, one `general` and one `brand`, and assert the importer creates two groups/tasks with distinct settings. Assert a generic row defaults to `on_insufficient` when the Semrush path provides no explicit override.

- [ ] **Step 2: Run the focused test and verify failure**

Run: `docker compose exec -T app php artisan test tests/Feature/Admin/BulkContentImportServiceTest.php`

Expected: FAIL because the existing group key and service arguments do not include the new per-row mode/evidence fields.

- [ ] **Step 3: Extend parser/group key and service arguments**

Parse mode and evidence fields, normalize domains, include them in `BulkContentImportRow::groupKey()`, and pass the first row's immutable settings into task/brief creation only after group consistency is verified.

- [ ] **Step 4: Preserve backwards compatibility**

Rows from the normal bulk template without mode/evidence fields remain `legacy_manual` with the existing default policy. Existing manual imports must not become external-search tasks automatically.

- [ ] **Step 5: Run regression tests and commit**

Run: `docker compose exec -T app php artisan test tests/Feature/Admin/BulkContentImportServiceTest.php tests/Feature/Admin/TaskControllerTest.php`

Expected: PASS. Commit: `git add app tests && git commit -m "fix: preserve semrush evidence settings in tasks"`.

### Task 5: End-to-end verification and operational documentation

**Files:**
- Create: `docs/operations/brand-agnostic-semrush-import.md`
- Modify: `.planning/2026-10-14-geoflow-claude-handover-audit/progress.md`
- Modify: `.planning/2026-10-14-geoflow-claude-handover-audit/findings.md`
- Test: `tests/Feature/Admin/SemrushImportControllerTest.php`

- [ ] **Step 1: Run the complete focused suite**

Run: `docker compose exec -T app php artisan test tests/Unit/Semrush tests/Feature/Admin/SemrushImportControllerTest.php tests/Feature/Admin/BulkContentImportServiceTest.php tests/Feature/ContentGeneration/ExternalEvidenceRoutingTest.php tests/Unit/EvidenceCitationRendererTest.php`

Expected: all new and touched tests pass; any pre-existing failures are listed separately with their baseline.

- [ ] **Step 2: Run static checks**

Run: `find app/Services/GeoFlow/Semrush app/Http/Controllers/Admin app/Domain/ContentGeneration -name '*.php' -print0 | xargs -0 -n1 php -l`

Expected: no syntax errors.

- [ ] **Step 3: Perform a read-only smoke import**

Use one generic Semrush row, one LightInTheBox policy row, and one AceGolf product row in the admin preview. Verify the preview cards, then stop before confirmation unless the user explicitly requests task creation. Confirm no LITB-only strings appear in the AceGolf preview.

- [ ] **Step 4: Document operations**

Document required brand profile fields, how to configure official/general domains, how `brand/general/mixed` is selected, how to override evidence policy, and how quarantined rows are resolved.

- [ ] **Step 5: Final review and commit**

Run `git diff --check` and `git status --short`; inspect every changed file for accidental edits outside this feature. Commit: `git add docs .planning tests app resources/views lang && git commit -m "docs: document brand agnostic semrush operations"`.

## Plan self-review

- Every approved-spec requirement maps to at least one task: profile-driven conversion (1/3), general/mixed modes (2/4), external defaults (2/3/4), deterministic snapshot (1/3), quarantine behavior (1/3), and verification (5).
- No step depends on an undefined later method; the new service signatures are declared in Task 1 and reused consistently.
- The plan contains no destructive migration or historical-data rewrite.
- The main risk is the existing bulk spreadsheet schema: Task 3 explicitly requires extending parser/template together if per-row values cannot be represented.
- The plan's uncovered failure modes are listed in Review Focus and each is pinned to a test-owning task.
