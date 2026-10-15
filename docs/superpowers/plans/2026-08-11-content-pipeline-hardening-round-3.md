# Content Pipeline Hardening Round 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the final three Important findings around review-terminal audit history, nested sensitive values, and free-text CTA detection.

**Architecture:** Treat `ready_for_review` as terminal for all audit children except one database-validated adoption-step shape. Normalize sensitive values recursively at compilation and encrypted-envelope boundaries so every scalar leaf and meaningful canonical composite is available to the existing persistence redactor. Keep CTA enforcement conservative by requiring an imperative commerce verb plus an action/urgency complement while sharing that vocabulary with structured link and HTML checks.

**Tech Stack:** Laravel 12, PHP 8.3, PostgreSQL, SQLite, CommonMark, PHPUnit, Pint, Vite.

## Global Constraints

- Use RED/GREEN TDD for each finding.
- Keep PostgreSQL and SQLite trigger behavior equivalent.
- Do not modify, stage, or commit the pre-existing `docker-compose.yml` edit.
- Preserve the legitimate atomic adoption workflow.

---

### Task 1: Terminal Review Audit Guards

**Files:**
- Modify: `database/migrations/2026_08_11_000100_enforce_content_generation_audit_immutability.php`
- Modify: `tests/Feature/ContentStrategySchemaTest.php`
- Verify: `tests/Feature/GenerationAdoptionServiceTest.php`

**Interfaces:**
- Produces: database INSERT guards that reject every `ready_for_review` module, evidence, quality-report, and ordinary-step append.
- Produces: one exception for a completed `adoption` step with null provider fields, complete adoption hashes/actor/article metadata, and timestamps.

- [x] Add raw-SQL tests that reject all ordinary review-terminal child rows and malformed adoption steps while accepting the exact adoption shape.
- [x] Run the raw tests on SQLite and confirm they fail because `ready_for_review` is currently active.
- [x] Implement equivalent PostgreSQL and SQLite adoption-aware INSERT guards.
- [x] Run schema and adoption tests on both databases.

### Task 2: Nested Sensitive Value Closure

**Files:**
- Modify: `app/Domain/PromptComposition/PromptCompiler.php`
- Modify: `app/Domain/ContentGeneration/SensitiveContentRedactor.php`
- Modify: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Modify: `tests/Unit/PromptCompilerTest.php`
- Modify: `tests/Feature/ContentGenerationCoordinatorTest.php`

**Interfaces:**
- Produces: recursive scalar-leaf and non-empty canonical-composite extraction as a length-sorted unique `list<string>`.

- [x] Add compiler and coordinator regressions using a sensitive associative object containing nested lists, strings, numbers, and canonical sub-objects.
- [x] Verify a leaf echo reaches persisted/downstream text before the fix.
- [x] Implement recursive extraction in the compiler and normalize legacy/nested encrypted envelopes through `SensitiveContentRedactor`.
- [x] Verify every later provider Prompt and every plaintext run/step/report column is leaf-free.

### Task 3: Free-Text CTA Contract

**Files:**
- Modify: `app/Domain/ContentGeneration/DeterministicQualityGate.php`
- Modify: `tests/Unit/DeterministicQualityGateTest.php`

**Interfaces:**
- Produces: structured and prose CTA recognition for purchase/order/shop/secure/reserve/subscribe/sign-up actions combined with immediacy, ownership, urgency, or stock language.

- [x] Add the reviewer sentence and common action/urgency variants as failing probes.
- [x] Add benign explanatory uses of the same words as low-false-positive controls.
- [x] Extend the shared CTA action contract used by prose, Markdown labels, and HTML content.
- [x] Run deterministic-quality tests to GREEN.

### Task 4: Verification and Commit

**Files:**
- Modify: `docs/reports/task12-round1-hardening.md`
- Modify: `.superpowers/sdd/2026-08-10-content-strategy-prompt-composition-plan/task-12-report.md`

- [x] Run focused SQLite tests and real PostgreSQL schema/adoption/coordinator tests.
- [x] Run exact-file Pint, Vite production build, and `git diff --check`.
- [x] Update the hardening reports and mark this plan complete.
- [x] Stage only round-3 files, verify `docker-compose.yml` is unstaged, and commit the final three Important fixes.
