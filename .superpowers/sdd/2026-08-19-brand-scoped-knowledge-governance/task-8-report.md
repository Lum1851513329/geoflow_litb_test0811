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

## Review fix round 1

### Changed files

- `app/Services/GeoFlow/KnowledgeGovernance/KnowledgeScope.php`
- `app/Services/GeoFlow/KnowledgeRetrievalService.php`
- `tests/Feature/KnowledgeRetrievalIsolationTest.php`
- `tests/Feature/KnowledgeRetrievalServiceTest.php`
- `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/task-8-report.md`

### Before / RED evidence

- The new retrieval and scope regressions initially produced 11 failures with 15 passing tests and 54 assertions.
- Forged chunk metadata could replace document source, freshness, risk and review fields; it could both disguise an unreviewed high-risk document and control returned citation provenance.
- Documents with the same normalized title/section but different canonical identities were collapsed, while newer research could win a true canonical-version conflict over first-party policy.
- `KnowledgeScope` retained untrimmed/non-canonical language input and accepted malformed tags other than an empty string.

### Implementation

- Retrieval now accepts only the parser-owned block-index fields from chunk metadata. Every document-level ownership, lifecycle, eligibility, risk, review, authority, freshness, source and citation identity is sourced from the scoped SQL-selected document.
- Conflict collapse now requires matching brand/site/language canonical identity and topic plus distinct document records. Unrelated same-title documents survive independently.
- Canonical-version selection now uses the same governance-first comparator as final ranking, so reviewed first-party policy outranks fresher research; equally governed versions still resolve by freshness.
- `KnowledgeScope` now follows the existing content-brief language pattern: trim, normalize underscore separators and canonicalize language/script/region casing, then validate the project BCP 47-compatible tag shape.
- The former per-chunk effective-date conflict test now models versions as separate documents sharing a canonical identity, keeping document governance authoritative.

### Verification

- Focused retrieval suite: 26 tests and 92 assertions passed after the fix and strengthened provenance coverage.
- Retrieval, article assistant, worker, task and content-generation regression suites: 169 tests and 758 assertions passed.
- Pint passed for the four review-owned PHP files.
- PHP syntax checks passed for the four review-owned PHP files.
- `git diff --check` passed.

### Caveats

- The existing SQLite/pgvector caveat remains unchanged; no formal database import, corpus mutation or external write was performed.
- Unrelated pre-existing preset seeding, Docker Compose and `HANDOFF.md` changes remain outside this review-fix scope.

## Review fix round 2

### Changed files

- `app/Services/GeoFlow/KnowledgeGovernance/KnowledgeScope.php`
- `app/Services/GeoFlow/KnowledgeRetrievalService.php`
- `tests/Feature/KnowledgeRetrievalIsolationTest.php`
- `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/task-8-report.md`

### Before / RED evidence

- The sibling-chunk and language-boundary regressions initially produced 11 failures with 20 passing tests and 76 assertions.
- When a two-chunk policy document competed with one newer research version, conflict resolution returned only one policy chunk instead of retaining both relevant chunks from the winning document.
- Extension payloads such as `ca`/`nu` were incorrectly region-cased, private-use-only tags were rejected, and incomplete or duplicate extension singletons were accepted.

### Implementation

- Conflict resolution now groups candidates by document record, compares each document through its strongest representative with the governance-first ranking policy, and retains every distinct relevant chunk belonging to the winning document.
- Exact duplicate winning chunks are suppressed by title, section and content identity. The retained candidates continue through the existing global comparator, preserving deterministic score/chunk rank and citation ordering.
- Language normalization now parses primary language, extlang, script, region, variants, extensions and private-use in context. Extension/private-use subtags normalize lowercase, while script and region retain the stored title/uppercase convention.
- Incomplete singletons, missing private-use payloads, duplicate extension singleton keys and malformed extension transitions fail closed.

### Verification

- Focused retrieval suite: 39 tests and 109 assertions passed.
- Retrieval, article assistant, worker, task and content-generation regression suites: 182 tests and 775 assertions passed.
- Pint passed for the three review-owned PHP files.
- PHP syntax checks passed for the three review-owned PHP files.
- `git diff --check` passed.

### Caveats

- Neither PHP `intl`/`Locale` nor an installed BCP 47 normalization library is available in the app container, so `KnowledgeScope` implements the required project-compatible grammar directly.
- The existing SQLite/pgvector caveat remains unchanged; no formal database import, corpus mutation or external write was performed.
- Unrelated pre-existing preset seeding, Docker Compose and `HANDOFF.md` changes remain outside this review-fix scope.

## Review fix round 3

### Changed files

- `app/Services/GeoFlow/KnowledgeRetrievalService.php`
- `tests/Feature/KnowledgeRetrievalIsolationTest.php`
- `tests/Feature/KnowledgeRetrievalServiceTest.php`
- `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/task-8-report.md`

### Before / RED evidence

- The cross-section canonical-version regression initially failed with one failure, 39 passing tests and 110 assertions.
- A two-section policy version and a two-section research version returned all four chunks when their titles/section paths differed, allowing research-only sections to escape conflict resolution.
- The existing canonical single-document fixture remained passing, isolating the defect to cross-document version grouping.

### Implementation

- Conflict candidates now group once by scoped canonical document identity: brand, site, language and canonical document key. Chunk title and section path no longer participate in version identity.
- The governance-first document winner chosen for that canonical identity applies to every candidate section. All losing-document chunks are removed, including sections with no title/path counterpart in the winner.
- All distinct winning-document chunks continue through the existing deterministic global rank and citation assignment.
- The complementary-chunk regression now gives its single document an explicit canonical key, protecting the no-version behavior while exercising the same grouping path.

### Verification

- Focused retrieval suite: 40 tests and 114 assertions passed.
- Retrieval, article assistant, worker, task and content-generation regression suites: 183 tests and 780 assertions passed.
- Pint passed for the three review-owned PHP files.
- PHP syntax checks passed for the three review-owned PHP files.
- `git diff --check` passed.

### Caveats

- The existing SQLite/pgvector caveat remains unchanged; no formal database import, corpus mutation or external write was performed.
- Unrelated pre-existing preset seeding, Docker Compose and `HANDOFF.md` changes remain outside this review-fix scope.
