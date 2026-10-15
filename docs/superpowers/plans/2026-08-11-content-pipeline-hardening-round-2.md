# Content Pipeline Hardening Round 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the second-round reliability, audit-integrity, secret-redaction, deterministic-quality, Unicode-token, and localization review findings.

**Architecture:** Generation requests enqueue a non-unique worker onto Laravel's database queue inside the same database transaction as run reservation and record an immutable dispatch marker; a scheduled reconciler repairs legacy/stale unmarked runs. Database triggers enforce state-aware append-only history in PostgreSQL and SQLite. Provider-derived data crosses one recursive redaction boundary before any persistence, while deterministic checks use parsed Markdown/HTML destinations and explicit CTA markers plus semantic review.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent/database queue, PostgreSQL, SQLite, PHPUnit, Blade/Vite.

## Global Constraints

- Use strict RED/GREEN TDD for every behavior change.
- Preserve the pre-existing `docker-compose.yml` worktree edit and never stage it.
- Verify both PostgreSQL and SQLite migrations/trigger behavior.
- Do not persist sensitive values in any plaintext run, step, report, or adopted-output column.

---

### Task 1: Transactional Dispatch and Automatic Recovery

**Files:**
- Create: `app/Domain/ContentGeneration/ContentGenerationDispatcher.php`
- Create: `app/Console/Commands/RecoverContentGenerationDispatchesCommand.php`
- Create: `database/migrations/2026_08_11_000200_add_content_generation_dispatch_marker.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationRequestService.php`
- Modify: `app/Http/Controllers/Admin/ContentGenerationController.php`
- Modify: `app/Jobs/GenerateStrategyContent.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`

**Interfaces:**
- Produces: `ContentGenerationDispatcher::dispatch(ContentGenerationRun): bool`, transactionally inserting one database-queue job and setting `dispatch_enqueued_at`.
- Produces: `geoflow:recover-content-generation-dispatches` for stale/unmarked pending or queued runs.

- [x] Add a failing integration test that acquires the real Laravel unique lock, observes a throwing transport push, advances the clock, runs recovery without another HTTP request, and proves exactly one database job exists after repeated reconciliation.
- [x] Add failing tests proving a new reservation and its database queue row commit atomically and replay does not enqueue a duplicate.
- [x] Remove `ShouldBeUnique`, add the dispatch marker, move database dispatch into the reservation transaction, and add scheduled recovery.
- [x] Run the dispatch-focused tests to GREEN.

### Task 2: State-Aware Audit INSERT Guards

**Files:**
- Modify: `database/migrations/2026_08_11_000100_enforce_content_generation_audit_immutability.php`
- Test: `tests/Feature/ContentStrategySchemaTest.php`

**Interfaces:**
- Produces: PostgreSQL/SQLite INSERT guards for published bindings and terminal run child rows.

- [x] Add raw-SQL failing tests that allow child inserts during preparation/activity and reject module/evidence/step/report inserts after terminal status, plus reject bindings appended to production/deprecated parents.
- [x] Add PostgreSQL-specific coverage proving draft binding UPDATE returns `NEW` and draft DELETE returns `OLD`.
- [x] Implement equivalent state-aware PostgreSQL and SQLite triggers and update teardown.
- [x] Run forward, rollback, and reapply migration tests on both databases to GREEN.

### Task 3: Recursive Provider-Output Redaction

**Files:**
- Create: `app/Domain/ContentGeneration/SensitiveContentRedactor.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Modify: `app/Domain/ContentGeneration/GenerationAdoptionService.php`
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`
- Test: `tests/Feature/ContentPipelineSecurityTest.php`

**Interfaces:**
- Produces: recursive redaction for strings and JSON-compatible arrays using the run's encrypted sensitive-value envelope.

- [x] Add a failing malicious-provider test that echoes a secret through initial output, semantic JSON, revision Prompt/output, exception text, run raw/adopted output, every step column, and every quality-report JSON column.
- [x] Implement one persistence-boundary redactor and pass sensitive values through initial, semantic, revision, finish, and adoption paths.
- [x] Assert every plaintext database text/JSON column is secret-free while the provider still receives the secret.
- [x] Run security/coordinator tests to GREEN.

### Task 4: Parsed Link and Structured CTA Enforcement

**Files:**
- Modify: `app/Domain/ContentGeneration/DeterministicQualityGate.php`
- Test: `tests/Unit/DeterministicQualityGateTest.php`

**Interfaces:**
- Produces: parsed destination extraction for Markdown links/autolinks/reference links and HTML URI attributes; explicit CTA-marker detection plus conservative phrase fallback.

- [x] Add failing reviewer probes for protocol-relative and non-HTTP external schemes in Markdown/HTML.
- [x] Add failing CTA probes for structured markers and imperative CTA variants, plus benign prose/relative-anchor low-false-positive cases.
- [x] Implement destination parsing and externality classification; implement structured CTA gating with conservative prose matching.
- [x] Run deterministic gate tests to GREEN.

### Task 5: Unicode-Calibrated Token Estimation

**Files:**
- Modify: `app/Domain/PromptComposition/PromptCompiler.php`
- Test: `tests/Unit/PromptCompilerTest.php`

**Interfaces:**
- Produces: Unicode-aware conservative fallback estimate based on grapheme/character classes, never UTF-8 byte length.

- [x] Replace the byte-count assertion with failing Latin/CJK/emoji estimates and an 8,000-Han-character boundary test.
- [x] Implement a calibrated fallback: Han/kana/hangul and symbols count conservatively, Latin word/punctuation spans use a four-character approximation.
- [x] Run compiler tests to GREEN.

### Task 6: Localization, Verification, and Commit

**Files:**
- Modify: `lang/en/admin.php`
- Modify: `lang/zh_CN/admin.php`
- Modify: `.superpowers/sdd/2026-08-10-content-strategy-prompt-composition-plan/task-12-report.md`
- Modify: `docs/reports/task12-round1-hardening.md`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`

- [x] Add failing locale assertions for section heading and purpose validation labels.
- [x] Add English and Simplified Chinese keys and run locale-focused tests.
- [x] Run focused SQLite suite, PostgreSQL suite, migration rollback/reapply, exact-file Pint, `npm run build`, and `git diff --check`.
- [x] Update reports, stage all task-owned files except `docker-compose.yml`, verify the index, and commit `fix: close content pipeline hardening gaps`.
