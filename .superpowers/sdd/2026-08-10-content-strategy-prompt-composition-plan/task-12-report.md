# Task 12 report

## Result

- Added an idempotent `PromptCompositionSeeder` and wired it into `DatabaseSeeder` after the legacy Prompt seed data is installed.
- Seeded six mandatory system modules for truth/evidence, GEO citability, SEO structure, language/market, Markdown output, and quality checks, plus one shared `legacy_content` module.
- Copied the four built-in English/Chinese trust/ranking Prompt scenarios into four production presets without changing any legacy Prompt ID, content, variables, or type. Each preset binds exact immutable versions of all six system modules and the corresponding compatible legacy copy.
- Preserved provenance on every legacy copy with the source Prompt ID, name, and original SHA-256 digest. Legacy Mustache aliases are normalized only in the copied module version so all four presets resolve and compile through the modular compiler.
- Added security coverage for quoted/escaped external-reference injection, sensitive-variable redaction, super-admin-only full compiled Prompt access, mass-assignment protection for immutable lineage and snapshots, and central outbound URL blocking.
- Added Simplified Chinese and English changelog entries for the complete content-strategy and modular-Prompt release.
- Restored the intentional English fallback for the new content-strategy and Prompt administration groups in `pt_BR`, while retaining the Portuguese content-strategy navigation label.
- Made the PostgreSQL integrity-trigger functions replaceable. Laravel's `migrate:fresh` drops the owning tables but leaves schema-global functions behind, so the prior `CREATE FUNCTION` statements failed on the second disposable-database cycle; `CREATE OR REPLACE FUNCTION` preserves the same trigger behavior and makes repeated fresh migrations safe.

## Test-driven implementation evidence

- Initial RED: `docker compose exec app php artisan test tests/Feature/PromptCompositionSeederTest.php tests/Feature/ContentPipelineSecurityTest.php` — 3 expected seeder/integration failures and 5 security passes / 36 assertions before the seeder existed.
- Compiler-compatibility RED: `docker compose exec app php artisan test --filter=test_seeded_legacy_presets_resolve_and_compile_for_all_four_scenarios` — expected failure from unresolved legacy Mustache aliases before copied content normalization was added.
- Final Task 12 tests: `docker compose exec app php artisan test tests/Feature/PromptCompositionSeederTest.php tests/Feature/ContentPipelineSecurityTest.php` — 9 passed / 88 assertions.

## Verification

- Pipeline focus: `docker compose exec app php artisan test --filter='ContentStrategy|PromptModule|PromptCompiler|ContentGeneration|GenerationAdoption|ContentPipeline'` — 167 passed / 630 assertions.
- Legacy-path focus: `docker compose exec app php artisan test --filter='KeywordImport|ArticleAssistant|WorkerExecution|ArticleReview|ArticleRisk|Distribution|ManualPublication'` — 311 passed / 1,967 assertions. This includes the disabled-feature compatibility path and the established generation/adoption lineage and immutable-version protections.
- Portuguese locale regression: `docker compose exec app php artisan test tests/Unit/AdminPtBrLocaleCoverageTest.php` — 3 passed / 332 assertions.
- Frontend: `npm run build` — pass; Vite transformed 64 modules and completed the production build.
- Formatting: targeted container Pint `--test` — pass for all eight changed PHP files.
- Diff hygiene: `git diff --check` — pass.
- PostgreSQL status: `docker compose exec app php artisan migrate:status` — every migration reported as run without mutating the main database.
- Disposable PostgreSQL verification: two consecutive `migrate:fresh --seed --force` runs passed after the function fix, followed by two consecutive `db:seed --class=Database\\Seeders\\PromptCompositionSeeder --force` runs. The disposable database was then dropped.

## Full-suite baseline and final state

- Pre-task baseline: `docker compose exec app php artisan test` — 5 failed, 2 skipped, 1,697 passed / 12,804 assertions. The failures were the four categories below plus `AdminPtBrLocaleCoverageTest`.
- Final full backend: `docker compose exec app php artisan test` — 4 failed, 2 skipped, 1,707 passed / 12,892 assertions. The Task 12 locale regression is fixed; the four remaining failures exactly match pre-task failures:
  - `OpenSourceReleaseScriptsTest::test_release_sync_refreshes_a_clean_official_target_and_preserves_git_state` — container lacks `rsync`.
  - `AdminSiteThemeReplicationTest::test_delete_drafts_uses_the_same_replication_lock_as_package_creation` — existing draft-file filesystem assertion.
  - `AdminSystemUpdatesPageTest::test_full_release_plan_detects_deleted_tracked_files` — existing deleted-count assertion.
  - `ImageLibrarySecurityTest::test_case_insensitive_alias_can_finalize_a_registry_created_with_other_casing` — existing host/container case-alias filesystem assertion.
- A targeted rerun of those four test files reproduced the same 4 failures with 202 passes / 1,268 assertions. None of their test or implementation files differs from `HEAD` in this task.

## Scope and concerns

- The pre-existing `docker-compose.yml` `--no-reload` edit was preserved byte-for-byte and excluded from this task's commit.
- No remaining Task 12 implementation concern was identified. The repository-wide suite is not fully green only because of the four reproduced baseline/environment failures listed above.

## Fix round 1 — provenance, reserved keys, and snapshot identity

- Legacy scenarios now use the installed preset binding's stored `legacy_prompt_id` after first installation. Renaming a source Prompt therefore does not create a new snapshot, and deleting it preserves the already-installed legacy module version without aborting. A fresh installation missing any required source aborts during the locked preflight before seed mutation.
- The whole seed remains one transaction and now preflights every reserved module and preset key before writes. Existing rows must be system-owned, match the stable seed identity, and carry either the current provenance digest or the exact Task 12 schema-1 provenance. Administrator-owned and provenance-free system lookalikes fail atomically without acquiring versions or current pointers.
- Seeded module-version identity is a canonical digest of content, variable contract, output contract, parameter defaults, and the versioned seed-provenance digest. This permits content, contract, and defaults evolution to allocate a new immutable version instead of reusing content alone.
- Preset-version identity now includes matching rules, generation policy, seed provenance, and the exact ordered list of bound module-version IDs, positions, and required flags. Any binding evolution creates a coherent new preset version and advances its current pointer.
- A changed legacy Prompt creates the corresponding new legacy module version and preset version in the same transaction. The new legacy version is immediately bound by the new current preset, while the prior immutable module/preset pair remains linked as history.

### Fix-round TDD evidence

- Review RED: `docker compose exec app php artisan test tests/Feature/PromptCompositionSeederTest.php` — 7 expected failures across renamed/deleted source lookup, administrator-owned reserved collisions, changed legacy content, module contract/default identity, and preset binding identity; 5 existing tests passed.
- Provenance RED: `docker compose exec app php artisan test tests/Feature/PromptCompositionSeederTest.php --filter='system_looking_reserved'` — 2 expected failures because system-looking rows without seed provenance were initially accepted.
- Fix-round Task 12 tests: `docker compose exec app php artisan test tests/Feature/PromptCompositionSeederTest.php tests/Feature/ContentPipelineSecurityTest.php` — 19 passed / 137 assertions.

### Fix-round verification

- Pipeline focus: 167 passed / 630 assertions.
- Portuguese locale coverage: 3 passed / 332 assertions.
- Disposable PostgreSQL: after supplying the repository's documented one-time `GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true` gate, two consecutive `migrate:fresh --seed --force` runs and two consecutive standalone composition-seeder runs passed. Final counts remained 7 modules, 10 module versions, 4 presets, 4 preset versions, and 28 bindings; the disposable database was dropped.
- Targeted Pint `--test`, `git diff --check`, and `npm run build` passed; Vite transformed 64 modules.
- The unrelated `docker-compose.yml` edit remains unchanged and excluded.

## Fix round 2 — current-pointer ownership and snapshot digest semantics

- Unchanged reseeds no longer write `current_version_id`. Seed current pointers advance only when a new seed version was created and the locked current is either empty or an active production version from the same seed lineage.
- Administrator-created module and preset versions, plus deprecated/archived/non-production seed selections, are never replaced by reseeding. This applies both to ordinary unchanged reruns and to runs that create a newer seed snapshot.
- Restored `content_sha256` to the strict SHA-256 of Prompt body content. Added immutable `snapshot_sha256` as the canonical identity of content, variable contract, output contract, parameter defaults, and embedded seed provenance.
- Added an upgrade migration that removes the old content-only uniqueness constraint, retains a non-unique content-digest index, backfills/corrects both digests, adds per-module snapshot uniqueness, and recreates PostgreSQL/SQLite sequence, immutability, and delete guards with snapshot protection. Rollback fails closed if same-content evolved versions make the former constraint impossible to restore.
- The Eloquent model and module service now derive snapshot identity consistently; lifecycle activity logs use the full snapshot digest while the administration UI can continue showing the strict content digest.

### Fix-round 2 TDD evidence

- Current-pointer RED: administrator-published module and preset currents were reset from IDs 11/5 to existing seed IDs during unchanged reseed; a changed source likewise replaced the administrator module current with the new seed version.
- Digest/schema RED: seeded `content_sha256` differed from `hash(content)`, `snapshot_sha256` was absent, and lifecycle logging had no independent snapshot digest.
- Final Task 12/schema/service aggregate: 39 passed / 240 assertions.
- Pipeline focus: 168 passed / 639 assertions.
- Portuguese locale coverage: 3 passed / 332 assertions.

### Fix-round 2 PostgreSQL and build verification

- Upgrade database: migrated through the new snapshot migration, rolled that migration back, inserted a pre-upgrade row with a round-1 composite value in `content_sha256`, and migrated forward. The migration restored the strict content digest, backfilled snapshot identity, and accepted a second immutable version with identical content and a distinct contract/snapshot.
- Fresh database: two consecutive `migrate:fresh --seed --force` runs and two standalone composition reseeds passed. Final counts remained 7 modules, 10 module versions, 4 presets, 4 preset versions, and 28 bindings; every content digest matched the row content and every snapshot digest was populated. Both disposable databases were dropped.
- Targeted Pint `--test`, `git diff --check`, and `npm run build` passed; Vite transformed 64 modules.
- The unrelated `docker-compose.yml` edit remains unchanged and excluded.
