# Task 12 Final Hardening — Round 1

Date: 2026-08-11

## Outcome

All Critical and Important review findings were implemented and verified on SQLite and PostgreSQL. Minor BCP-47 normalization, run discovery, and validator localization items were also completed.

## Changes

- Added transactional reviewed-knowledge and explicit-evidence snapshots, evidence-aware request fingerprints, same-key pending/queued re-dispatch, and a default-seed evidence happy path.
- Enforced owner-scoped Prompt editing, protected production publication, and metadata-only access to system Prompt versions for ordinary operators.
- Enforced enabled/applicable mandatory system modules during preset publication, run reservation, and worker preparation.
- Split redacted audit Prompt text from the provider payload. Sensitive values are stored only in a Laravel-encrypted retry envelope and are redacted from step prompts, snapshots, and provider errors.
- Added equivalent PostgreSQL and SQLite append-only guards for published preset bindings, run modules/evidence, quality reports, completed steps, and terminal output; migration rollback restores the preceding run snapshot guard.
- Expanded deterministic quality checks for total target length, prohibited information, external links, per-section CTA rules, required-fact evidence, required mentions, language, and market conventions.
- Separated context-window, input, and output token budgets and froze all budget values in the model snapshot.
- Canonicalized BCP-47-style language tags and uppercase market codes.
- Added reviewed-evidence selection to the generation screen plus paginated run history searchable by ID, status, error, or quality issue text.
- Localized new validation/domain messages in English and Simplified Chinese.

## Verification

- SQLite high-value Task 12 suite: **195 tests / 996 assertions passed** before the last focused compatibility additions; all later focused tests passed.
- PostgreSQL schema + coordinator suite: **36 passed / 226 assertions**, with one expected SQLite-only test skipped.
- PostgreSQL and SQLite pagination/quality-issue search tests passed.
- PostgreSQL and SQLite audit migration rollback/reapply tests passed.
- Default seeded preset with real evidence reached `ready_for_review`.
- `npm run build` passed.
- Pint completed and fixed formatting; `git diff --check` passed.

Full repository result after fixing the task-owned security expectation: **1,739 passed / 13,108 assertions, 2 skipped, 4 known baseline failures**. The remaining failures are unrelated to this phase and reproduce in release/theme/image test infrastructure:

- `Tests\Unit\OpenSourceReleaseScriptsTest` — release sync refreshes fixture
- `Tests\Feature\AdminSiteThemeReplicationTest` — delete drafts fixture
- `Tests\Feature\AdminSystemUpdatesPageTest` — full release plan fixture
- `Tests\Feature\ImageLibrarySecurityTest` — case-insensitive alias cleanup on the host filesystem

The pre-existing `docker-compose.yml` worktree change was not modified, staged, or committed.

## Round 2 hardening

- Replaced best-effort unique-job dispatch with one database transaction that reserves the run, inserts its database-queue job, and records `dispatch_enqueued_at`. A scheduled reconciler repairs stale unmarked runs without a repeated HTTP request and remains idempotent across repeated recovery.
- Added PostgreSQL and SQLite INSERT guards for production/deprecated/archived preset bindings and terminal-run module, evidence, step, and quality rows. Binding UPDATE now checks both old and new parents, while draft UPDATE/DELETE returns remain correct.
- Added a recursive persistence-boundary redactor for provider output, semantic JSON, revision data, metadata, quality reports, run output, and adopted article fields.
- Replaced regex-only external-link checks with CommonMark/HTML destination parsing and added structured plus conservative semantic CTA detection.
- Replaced UTF-8 byte counting with a Unicode-calibrated token estimate and verified the 8,000-Han-character boundary.
- Added localized English and Simplified Chinese section heading/purpose validation labels.

### Round 2 verification

- Focused SQLite suite: **238 passed / 1,162 assertions**.
- Real PostgreSQL, per database-bearing file: schema **14 / 88**, coordinator **30 / 165** with one expected SQLite-only skip, admin workflow **31 / 322**, adoption **9 / 42**.
- Full repository before the final queue-configuration guard: **1,752 passed / 13,192 assertions**, 2 skipped, with the same 4 unrelated environment/filesystem baseline failures listed above; the final guard and its focused regression then passed.
- Targeted Pint completed cleanly, Vite production build transformed 64 modules, and `git diff --check` passed.
