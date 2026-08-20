# Task 8 Report — Mandatory Brand-Safe Retrieval

## Changed files

- `app/Services/GeoFlow/KnowledgeGovernance/KnowledgeScope.php`
- `app/Services/GeoFlow/KnowledgeRetrievalService.php`
- `app/Services/GeoFlow/WorkerExecutionService.php`
- `app/Http/Controllers/Admin/ArticleEditorAssistantController.php`
- `tests/Feature/KnowledgeRetrievalIsolationTest.php`
- `tests/Feature/KnowledgeRetrievalServiceTest.php`
- `tests/Feature/AdminArticleAssistantTest.php`
- `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/task-8-report.md`

## Before / RED evidence

- Baseline before Task 8 changes: retrieval plus article assistant passed with 23 tests and 102 assertions.
- New isolation test initially failed three times because `KnowledgeScope` did not exist.
- After adding the minimal scope value object, the same tests failed three times because retrieval still accepted unscoped arguments.
- Existing retrieval service tests then failed eight times on the new mandatory-scope contract until their documents and calls were explicitly governed.
- Article assistant tests exposed five unscoped production-caller failures (HTTP 500 caused by the old call signature).
- Worker fail-closed regression exposed the remaining unscoped worker call.
- With directory/cluster constraints deliberately withheld, two optional-scope tests proved both foreign candidate sets leaked; restoring the SQL constraints made both pass.
- A forged/stale chunk metadata regression initially returned a foreign `brand_id`; protecting document-owned governance metadata made it pass.

## Implementation

- Added a validated immutable `KnowledgeScope` carrying mandatory brand ID, site ID and language code, plus optional directory and keyword-cluster IDs.
- Made every public retrieval/context method require `KnowledgeScope`.
- Applied brand, site, language, active lifecycle, generation eligibility, optional directory and optional keyword-cluster constraints to the document/chunk candidate queries before vector or lexical scoring.
- Scoped the pgvector candidate query through the same governed chunk query.
- Added citation IDs and structured provenance to returned evidence.
- Prevented chunk metadata from overwriting SQL-verified ownership, lifecycle, eligibility and authority.
- Ranked active reviewed first-party policy evidence above unreviewed research, then applied freshness and retrieval score ordering.
- Updated article-assistant and worker callers to derive explicit scope only from complete governed ownership. Unowned, partial, mixed-brand/site/language, inactive or ineligible worker selections fail closed.
- Updated article-assistant and retrieval fixtures to use governed ownership and added explicit unowned-caller rejection coverage.

## Verification

- Focused isolation/retrieval/article assistant: 31 tests, 124 assertions passed.
- Retrieval, article assistant and available worker/task/content generation suites: 94 tests, 451 assertions passed.
- Task-owned PHP files formatted with Pint `--format agent`; only import ordering required a fix.
- `git diff --check` passed.
- `rg -n 'KnowledgeRetrievalService|->retrieve(Context|Evidence)' app --glob '*.php'` confirms both production callers supply scope.
- No formal database import, corpus mutation or external write was performed.

## Caveats

- PHPUnit forces SQLite `:memory:`. Scope selection, leakage, lifecycle, eligibility, language, directory and cluster SQL behavior are covered there; the pgvector-specific expression is not executed against a live PostgreSQL vector column in this task.
- `tests/Feature/WorkerPromptRecipeExecutionTest.php` referenced by an older plan no longer exists in this branch; all available worker/task/content generation suites relevant to the modified callers were run instead.
- The host has no `php` executable, so all PHP/Pint verification used the running project `app` container as documented in `HANDOFF.md`.
- Unrelated pre-existing changes in preset seeding, Docker Compose and `HANDOFF.md` were preserved and excluded from the Task 8 commit.
