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
