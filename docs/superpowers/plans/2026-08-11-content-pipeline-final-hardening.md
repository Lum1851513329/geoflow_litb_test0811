# Content Pipeline Final Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the final security, reliability, evidence, authorization, immutable-audit, quality-gate, and token-budget gaps in the phase-one content pipeline.

**Architecture:** Keep reservation, immutable evidence capture, and queue intent in one database transaction; retries use the existing idempotency key and re-dispatch only recoverable pending/queued runs. Split model-call Prompt text from the redacted audit Prompt, enforce mandatory system modules at preset publication and run reservation, and add a forward migration containing equivalent PostgreSQL and SQLite audit guards.

**Tech Stack:** PHP 8.3, Laravel 12, Eloquent policies/FormRequests, PostgreSQL and SQLite triggers, Blade/Tailwind, Laravel queues/events, PHPUnit.

## Global Constraints

- Preserve the legacy Task → Prompt → Worker → Article path and all three feature flags.
- Never fabricate facts: accepted evidence is either a snapshot of a reviewed knowledge chunk or explicit operator-submitted evidence attributed to that operator.
- Never persist sensitive variable plaintext in Prompt, step, error, module, evidence, or variable audit columns.
- Existing immutable version and generation lineage rows remain undeletable.
- Do not alter, stage, or commit the user's `docker-compose.yml` change.

---

### Task 1: Transactional evidence reservation and dispatch recovery

**Files:**
- Create: `app/Domain/ContentGeneration/GenerationEvidenceSnapshotter.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationRequestService.php`
- Modify: `app/Http/Requests/Admin/RequestContentGenerationRequest.php`
- Modify: `app/Http/Controllers/Admin/ContentGenerationController.php`
- Modify: `resources/views/admin/content-briefs/show.blade.php`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`

**Interfaces:**
- `GenerationEvidenceSnapshotter::snapshot(ContentGenerationRun $run, array $knowledgeChunkIds, array $explicitEvidence, Admin $actor): void` locks reviewed knowledge bases/chunks and inserts immutable run-evidence rows.
- `ContentGenerationRequestService::reserve(...)` returns `{run, created, dispatch_required}`; a matching pending/queued replay returns `dispatch_required=true`.

- [x] Add failing HTTP tests for reviewed-chunk and explicit evidence snapshots, required fact coverage, dispatch failure followed by same-key retry, and default seeded preset reaching `ready_for_review` with real evidence.
- [x] Run the focused tests and verify failures are caused by missing evidence inputs/re-dispatch.
- [x] Implement validation, snapshotting inside reservation, recoverable replay, and controller dispatch handling.
- [x] Run the focused tests to green.

### Task 2: Object-level Prompt authorization

**Files:**
- Modify: `app/Policies/PromptModulePolicy.php`
- Modify: `app/Policies/PromptPresetPolicy.php`
- Modify: `app/Http/Controllers/Admin/PromptModuleController.php`
- Modify: `app/Http/Controllers/Admin/PromptPresetController.php`
- Modify: Prompt administration views/requests as needed
- Test: `tests/Feature/AdminPromptModulesTest.php`

**Interfaces:**
- Non-super administrators may create user objects and mutate only rows where `owned_by_admin_id` equals their ID.
- `publishVersion` and system Prompt body/diff access require `Admin::canManageProtectedWorkflows()`.

- [x] Add failing cross-owner module/preset, production publish, and system-body disclosure tests.
- [x] Run RED, implement policy/query/view enforcement, and rerun GREEN.

### Task 3: Mandatory system-module release guard

**Files:**
- Create: `app/Domain/PromptComposition/MandatoryPromptModuleGuard.php`
- Modify: `app/Domain/PromptComposition/PromptPresetService.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationRequestService.php`
- Test: `tests/Unit/PromptModuleResolverTest.php`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`

**Interfaces:**
- `assertPresetComplete(PromptPresetVersion $version, ?array $context = null): void` requires every enabled applicable mandatory system module to have an exact production binding.

- [x] Add failing publication and reservation tests for a missing mandatory module.
- [x] Implement deterministic applicability and binding checks at both boundaries.
- [x] Run focused tests to green.

### Task 4: Sensitive Prompt call/audit separation

**Files:**
- Modify: `app/Domain/PromptComposition/CompiledPrompt.php`
- Modify: `app/Domain/PromptComposition/PromptCompiler.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Test: `tests/Unit/PromptCompilerTest.php`
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`
- Test: `tests/Feature/ContentPipelineSecurityTest.php`

**Interfaces:**
- `CompiledPrompt::$text` is safe for persistence; `CompiledPrompt::$modelPayload` contains the transient complete Prompt and is never stored.
- Coordinator passes only `modelPayload` to the provider and persists only redacted Prompt/input/error fields.

- [x] Add failing database assertions proving a sensitive literal is absent from all audit columns while the fake provider receives it.
- [x] Implement dual rendering and sanitize all derived revision/semantic interactions.
- [x] Run security/coordinator/compiler tests to green.

### Task 5: Immutable generation audit guards

**Files:**
- Create: `database/migrations/2026_08_11_000000_harden_content_generation_audit.php`
- Modify: rollback integration only where required
- Test: `tests/Feature/ContentStrategySchemaTest.php`
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`

**Interfaces:**
- Published preset bindings are immutable.
- Run modules/evidence/reports cannot update/delete; completed steps cannot update/delete; terminal run raw/adopted outputs cannot change.
- Running steps retain their legal one-time completion transition.

- [x] Add failing raw SQL/Eloquent tests for every table and legal transition tests.
- [x] Add equivalent PostgreSQL/SQLite triggers with reversible migration ordering.
- [x] Run migration down/up and schema/coordinator tests to green.

### Task 6: Complete deterministic hard constraints

**Files:**
- Modify: `app/Domain/ContentGeneration/DeterministicQualityGate.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Test: `tests/Unit/DeterministicQualityGateTest.php`
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`

**Interfaces:**
- Requirements snapshot includes prohibited information, external-link policy, CTA policy, evidence fact keys, language and market.
- Gate emits stable codes for target length, prohibited content, external links, disallowed CTA, uncovered required facts, and market inconsistency.

- [x] Add one failing behavior test per hard constraint plus an E2E rejection test.
- [x] Pass the complete immutable requirements/evidence snapshot from coordinator.
- [x] Implement minimal deterministic checks and run tests to green.

### Task 7: Input/output/context token budgets and BCP 47

**Files:**
- Create: `database/migrations/2026_08_11_000100_add_ai_model_context_budget.php`
- Modify: `app/Models/AiModel.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Modify: `app/Domain/PromptComposition/PromptContext.php`
- Modify: `app/Domain/PromptComposition/PromptCompiler.php`
- Modify: content brief request/service normalization
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`
- Test: `tests/Unit/PromptCompilerTest.php`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`

**Interfaces:**
- `context_window_tokens` is model capacity; `max_tokens` remains output cap; safe input budget is context minus reserved output and safety margin.
- Locale normalization emits canonical language tags such as `zh-Hans-CN` and uppercase market codes.

- [x] Add failing Chinese prompt budget and BCP-47 canonicalization tests.
- [x] Add schema/config and safe budget calculation without reusing output max as input capacity.
- [x] Run focused tests to green.

### Task 8: Run discovery, localization, and final verification

**Files:**
- Modify: content brief/run controllers and views
- Modify: `lang/en/admin.php`, `lang/zh_CN/admin.php`
- Modify: `.superpowers/sdd/2026-08-10-content-strategy-prompt-composition-plan/task-12-report.md`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`

**Interfaces:**
- Brief page paginates runs and supports an issue/question keyword query over quality issue snapshots.
- New validator and domain-facing messages use translation keys.

- [x] Add failing pagination/search/localization assertions and implement them.
- [x] Run high-value focused, pipeline, compatibility, locale, PostgreSQL migration/fresh/reseed, Pint, build, and diff checks.
- [x] Record verification and commit the hardening changes without `docker-compose.yml`.
