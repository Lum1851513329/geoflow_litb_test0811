# Task 11 review round 1 report

## Result

- Added explicit `ContentBrief` and `ContentGenerationRun` policies. Active ordinary admins can access only briefs they created and descendant generation runs; super admins can access all briefs/runs. Index queries, route middleware, controllers, FormRequests, generation requests, and adoption enforce the same policy.
- Added one shared `PromptPresetSelector` for UI and POST selection. New runs require an enabled preset's exact `current_version_id`, a canary/production lifecycle, and exact matching-rule compatibility with the brief context.
- Added `ContentGenerationRequestService` to bind idempotency keys to the actor and the immutable brief/strategy/blueprint/preset/model fingerprint. Exact replay returns the original run without redispatch, conflicting replay returns an `idempotency_key` validation error, and global unique-key insert races use transaction/unique-exception/requery handling instead of surfacing a 500.
- Resolver failures are reported internally and rendered with generic translated copy. Evidence URLs become links only for validated HTTP/HTTPS URLs; unsafe schemes remain escaped plain text.
- Added English and Simplified Chinese labels for previous-run status, quality report type/status, and revision step type/status, with raw fallback only for unknown future values.
- Expanded `AdminContentBriefWorkflowTest` with cross-operator/super-admin authorization, current/matching/lifecycle preset validation, replay/config/actor/global-key idempotency, generic resolver errors, URL/XSS safety, and enum translation/fallback coverage.

## Verification

- RED baseline: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php` — 8 intended failures across authorization, preset validation, idempotency conflicts, resolver message exposure, and translations.
- Lifecycle mutation RED: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php --filter=test_generation_rejects_a_current_preset_outside_production_like_lifecycles` — failed when `test` was temporarily treated as eligible; mutation reverted.
- Replay-after-configuration-change RED: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php --filter=test_exact_replay_returns_the_original_run_after_operational_configuration_changes` — failed on stale preset/inactive model validation before the replay path was added.
- Focused GREEN: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php` — 19 passed / 206 assertions at that checkpoint; the later replay regression is included in the final aggregate run.
- Requested aggregate: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php tests/Feature/ContentGenerationCoordinatorTest.php tests/Feature/GenerationAdoptionServiceTest.php tests/Feature/ContentPipelineCompatibilityTest.php` — 61 passed / 419 assertions.
- Host Pint attempt: `vendor/bin/pint --dirty --format agent` — unavailable because host PHP is not installed (`env: php: No such file or directory`).
- Container Pint: `docker compose exec app vendor/bin/pint --dirty --format agent` — pass.
- Frontend: `npm run build` — pass; Vite transformed 64 modules and completed the production build.
- Diff hygiene: `git diff --check` — pass.

## Scope and concerns

- The pre-existing `docker-compose.yml` change was preserved and excluded from this task's commit.
- No schema change was needed: the immutable run lineage columns plus `created_by_admin_id` are the persisted fingerprint inputs, and the existing global unique `idempotency_key` constraint is the race arbiter.
- No remaining Task 11 review-round concern identified.

## Review round 2 — reservation TOCTOU

- Added deterministic tests that fully validate a `RequestContentGenerationRequest`, mutate the selected model or required module, and then invoke the controller reservation path. Both mutations now return field-addressable validation errors and leave the run table and generation queue empty.
- The reservation transaction now follows the Prompt services' lock order: preset, preset version, ordered bindings, modules ordered by ID, then module versions ordered by module/ID. It re-runs `PromptModuleResolver` while those locks are held so current module enablement, applicability, and bound-version lifecycle state are checked immediately before insertion.
- The selected `AiModel` is locked after the prompt graph, then rechecked as active and non-embedding. The locked IDs, rather than the earlier request values, are used for insertion.
- RED: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php --filter='test_reservation_rejects_a_model_disabled_after_request_validation|test_reservation_rejects_a_required_module_disabled_after_request_validation'` — 2 expected failures because both post-validation mutations were accepted.
- Focused GREEN: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php` — 22 passed / 231 assertions.
- Requested aggregate: `docker compose exec app php artisan test --compact tests/Feature/AdminContentBriefWorkflowTest.php tests/Feature/ContentGenerationCoordinatorTest.php tests/Feature/ContentPipelineCompatibilityTest.php` — 55 passed / 398 assertions.
- Pint: `docker compose exec app vendor/bin/pint --dirty --format agent` — pass.
- Frontend: `npm run build` — pass; Vite transformed 64 modules and completed the production build.
- The pre-existing `docker-compose.yml` edit remains preserved and excluded.
