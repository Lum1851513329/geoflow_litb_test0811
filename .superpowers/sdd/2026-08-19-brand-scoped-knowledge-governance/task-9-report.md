# Task 9 Report — Admin Governance and Directory Navigation

Date: 2026-08-20

## Outcome

Implemented the governed admin knowledge workflow without running a formal import or mutating a live database.

- Added brand/site switchers and brand-scoped hierarchical directory navigation.
- Added SQL-backed combined filters for brand, site, directory (including descendants), language, authority, risk, review, and lifecycle.
- Added a focused form request that requires brand/site/directory ownership and provenance, rejects cross-brand site/directory combinations, and validates governance fields.
- Enforced active policy/customer-transaction/logistics provenance requirements, including descendants, plus fail-closed high-risk unreviewed research eligibility.
- Preserved create, multi-file upload, single-file upload, edit, detail update, chunk scheduling, and backward-compatible route names.
- Persisted ownership, provenance, canonical/version, eligibility, source-file, and governance metadata.
- Added duplicate/version badges and governance metadata to list/detail pages, with eager-loaded relations and aggregate/count subqueries.
- Fail-closed detail content edits for legacy records without valid governed ownership and invalidate normalized hashes when content changes.
- Added English and retained Chinese UI translations; other locales continue to use the existing fallback behavior.

## TDD evidence

Initial focused RED:

    FAIL  Tests\Feature\AdminKnowledgeGovernanceTest
    Tests: 7 failed (19 assertions)

Additional regression RED/GREEN cycles covered:

- Active policy descendant without URL/retrieved date was initially accepted, then rejected.
- Legacy single-file upload initially required an explicit file type, then passed through inferred type behavior.
- Detail content update initially retained a stale normalized hash, then cleared it.

Focused GREEN:

    PASS  Tests\Feature\AdminKnowledgeGovernanceTest
    Tests: 7 passed (92 assertions)

Final affected verification:

    Tests: 94 passed (838 assertions)
    Duration: 70.77s

Suites:

- tests/Feature/AdminKnowledgeGovernanceTest.php
- tests/Feature/AdminMaterialsPagesTest.php
- tests/Feature/KnowledgeSourceParserSafetyTest.php
- tests/Feature/AdminDashboardQuickStartTest.php
- tests/Feature/AdminEnterpriseKnowledgeTest.php
- tests/Feature/KnowledgeChunkAsyncSyncTest.php
- tests/Feature/KnowledgeGovernanceSchemaTest.php

Quality checks:

- Pint: pass on all changed PHP files.
- PHP lint: pass on controller, request, translations, and affected tests.
- Blade compilation: view:cache pass, followed by view:clear.
- git diff --check: pass.

## Compatibility and scope

Directly affected legacy materials tests now create explicitly governed fixtures/payloads so their original upload, chunking, validation, and queue-failure behavior remains covered. Unrelated preset seeding, Docker, and HANDOFF.md changes were deliberately left unstaged and untouched.

## Review fix round 1 — 2026-08-20

Addressed every round-one review finding through isolated RED/GREEN cycles:

- Research is identified by the research-reference directory family, including descendants, or by mixed_research authority. Every research document requires citations; only unreviewed high-risk research is blocked from generation.
- Admin form and detail edits preserve the complete imported asset provenance payload, including namespace, document and import identity, hashes, warnings, and original ingestion method. Append-only operator_edits entries record the admin, operation, and timestamp.
- Single-file source_file_hash is the SHA-256 of raw bytes. Multi-file hashing sorts raw-byte SHA-256 values and hashes the versioned geoflow-source-files-v1 aggregate, making it independent of generated paths, original names, and upload order. Hash failures abort validation and clean staged files.
- Create, form edit, and detail edit normalize content through KnowledgeSourceParser, retain the computed normalized_content_hash, preflight active brand/language collisions, and translate database unique-index races into a content validation error.
- KnowledgeScope now exposes its canonical BCP47 parser for admin validation and storage. EN_us round-trips as en-US through retrieval, and malformed tags rejected by retrieval scope are rejected by the admin form.
- The governed list now paginates 20 records per page with query filters preserved, directory navigation retained, and explicit stale-source and overdue-review warnings.

Round-one focused GREEN:

    Tests: 18 passed (201 assertions)

Round-one broad GREEN:

    Tests: 137 passed (1033 assertions)
    Duration: 9.46s

Broad suites:

- tests/Feature/AdminKnowledgeGovernanceTest.php
- tests/Feature/AdminMaterialsPagesTest.php
- tests/Feature/KnowledgeSourceParserSafetyTest.php
- tests/Feature/KnowledgeRetrievalIsolationTest.php
- tests/Feature/KnowledgeGovernanceSchemaTest.php
- tests/Feature/AdminDashboardQuickStartTest.php
- tests/Feature/AdminEnterpriseKnowledgeTest.php
- tests/Feature/KnowledgeChunkAsyncSyncTest.php

Pint, PHP lint, Blade compilation, and git diff --check all passed after the changes.

## Review fix round 2 — 2026-08-20

- Extracted the importer’s governed Markdown canonicalization into `GovernedKnowledgeContentNormalizer` and made both the package validator and admin create/edit paths use that single implementation. Admin identity now matches imported NFC content, canonical line endings, format-only cleanup, preserved internal spacing, and the single terminal-newline contract.
- Added cross-ingestion collision coverage, imported-document no-op edit coverage, and an explicit internal-spacing distinction regression. The admin no longer uses `KnowledgeSourceParser`’s whitespace-collapsing identity behavior.
- Wrapped both form and detail editor writes in transactions, reloaded each document with `lockForUpdate`, and performed collision checks plus provenance history append against that locked current row. A deterministic stale-read interleaving regression proves an intervening operator event is not lost on either route.
- Removed the request’s narrower language regex/length rule so storage relies on the same `KnowledgeScope` BCP47 canonicalizer as retrieval. Private-use-only `X_Foo` now stores and retrieves as `x-foo`, while malformed tags remain rejected.
- Added a no-wrapper fast path to the shared normalizer so existing maximum-size uploads remain within the PHP memory budget.

Round-two focused RED evidence:

    Tests: 6 failed, 16 passed (198 assertions)

The failures demonstrated importer/admin hash drift, internal-whitespace collapse, imported no-op identity drift, missed edit collisions, and rejection of private-use-only language tags. The deterministic stale-interleaving test separately failed by retaining only `admin_form_update` and losing the injected prior event.

Round-two focused GREEN:

    Tests: 22 passed (219 assertions)

Round-two importer GREEN:

    Tests: 35 passed (292 assertions)

Round-two broad GREEN:

    Tests: 176 passed (1343 assertions)
    Duration: 9.26s

Broad suites:

- tests/Feature/AdminKnowledgeGovernanceTest.php
- tests/Feature/AdminMaterialsPagesTest.php
- tests/Feature/KnowledgeSourceParserSafetyTest.php
- tests/Feature/KnowledgeRetrievalIsolationTest.php
- tests/Feature/KnowledgeGovernanceSchemaTest.php
- tests/Feature/AdminDashboardQuickStartTest.php
- tests/Feature/AdminEnterpriseKnowledgeTest.php
- tests/Feature/KnowledgeChunkAsyncSyncTest.php
- tests/Feature/ImportGovernedKnowledgeCommandTest.php
