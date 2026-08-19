# Brand-Scoped Knowledge Governance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and migrate a governed, deduplicated, brand/site-isolated LightInTheBox knowledge corpus connected to keyword library ID 42.

**Architecture:** Add explicit brand, site, directory, governance and import-audit records around the existing `knowledge_bases` and `knowledge_chunks` pipeline. A deterministic Python builder normalizes, classifies, deduplicates and packages the read-only corpus; a Laravel importer validates staging and commits atomically. Retrieval applies ownership scope before ranking.

**Tech Stack:** PHP 8.2+, Laravel, PostgreSQL/pgvector, Blade, PHPUnit, Python 3 standard library, Markdown and JSONL.

**Spec:** `docs/superpowers/specs/2026-08-19-brand-scoped-knowledge-governance-design.md`

## Global Constraints

- Never modify source files under `/Users/litb/Documents/litb_geo/litb_zhishiku`; generated output belongs in `knowledge_cleanup_work/output`.
- Every active document has exactly one brand, site, primary directory and language.
- Retrieval filters brand/site before lexical or vector scoring.
- Active policy/customer-service evidence requires source URL and retrieved date.
- High-risk unreviewed research is citation-required and not verified evidence.
- The initial formal corpus is English-only; accurately detected non-English/undetermined sources remain in exclusion audit output and are not imported.
- Exact duplicates merge deterministically; uncertain near duplicates remain audited and separate.
- Keyword associations target formal keyword library ID 42 without copying keywords into prose.
- Existing task/article references remain valid through migration.
- Mutation requires transaction rollback or a verified backup and rollback mapping.
- Every task follows failing test, minimal implementation, focused pass and commit.

---

### Task 1: Ownership Schema and Models

**Files:**
- Create: `database/migrations/2026_08_19_000100_create_knowledge_ownership_tables.php`
- Create: `app/Models/KnowledgeBrand.php`
- Create: `app/Models/KnowledgeSite.php`
- Create: `app/Models/KnowledgeDirectory.php`
- Modify: `app/Models/KnowledgeBase.php`
- Test: `tests/Feature/KnowledgeOwnershipSchemaTest.php`

**Interfaces:**
- Produces: `KnowledgeBrand::sites()`, `KnowledgeSite::brand()`, `KnowledgeDirectory::{brand,parent,children}()`, `KnowledgeBase::{brand,site,primaryDirectory}()`.
- Unique scopes: brand key; `(brand_id, site key)`; `(brand_id, directory path)`.

- [ ] Write a failing test that creates a LightInTheBox brand/site/directory/document and rejects a document whose site or directory belongs to another brand via `QueryException`.
- [ ] Run `php artisan test tests/Feature/KnowledgeOwnershipSchemaTest.php`; expect missing-table/column failures.
- [ ] Create tables and nullable ownership columns, plus composite foreign keys enforcing matching `brand_id` for site and directory.
- [ ] Add fillables, casts and relationships without placing traversal/import logic in `KnowledgeBase`.
- [ ] Run `php artisan test tests/Feature/KnowledgeOwnershipSchemaTest.php tests/Feature/AdminEnterpriseKnowledgeTest.php tests/Feature/KnowledgeChunkAsyncSyncTest.php`; expect PASS.
- [ ] Commit with `git commit -m "feat: add brand-scoped knowledge ownership"`.

### Task 2: Governance, Versions and Import Audit

**Files:**
- Create: `database/migrations/2026_08_19_000200_add_knowledge_document_governance.php`
- Create: `app/Models/KnowledgeDuplicate.php`
- Create: `app/Models/KnowledgeImportBatch.php`
- Create: `app/Models/KnowledgeImportRow.php`
- Modify: `app/Models/KnowledgeBase.php`
- Test: `tests/Feature/KnowledgeGovernanceSchemaTest.php`

**Interfaces:**
- Adds `canonical_document_key`, `language_code`, `authority_level`, `retrieved_at`, `review_due_at`, `version_label`, `lifecycle_status`, `generation_eligible`, `citation_required`, `normalized_content_hash`, `source_file_path`, and `source_file_hash`.
- Audit rows record staged payload, outcome, canonical target, duplicate kind/score/reason and rollback metadata.

- [ ] Write failing tests for active normalized-hash uniqueness by brand/language, version coexistence when superseded, and retained duplicate audit rows.
- [ ] Run `php artisan test tests/Feature/KnowledgeGovernanceSchemaTest.php`; expect schema failures.
- [ ] Add governance fields, casts, partial active-content unique index, import batch/row tables and duplicate table.
- [ ] Run `php artisan test tests/Feature/KnowledgeGovernanceSchemaTest.php tests/Feature/LitbContentAssetSchemaTest.php`; expect PASS.
- [ ] Commit with `git commit -m "feat: add knowledge governance and import audit"`.

### Task 3: Keyword Taxonomy Associations

**Files:**
- Create: `database/migrations/2026_08_19_000300_create_knowledge_keyword_associations.php`
- Create: `app/Models/KnowledgeKeywordNode.php`
- Create: `app/Models/KnowledgeKeywordCluster.php`
- Create: `app/Services/GeoFlow/KnowledgeGovernance/KnowledgeKeywordAssociationService.php`
- Modify: `app/Models/KnowledgeBase.php`
- Test: `tests/Feature/KnowledgeKeywordAssociationTest.php`

**Interfaces:**
- Produces `associate(KnowledgeBase $document, array $nodeIds, array $clusterIds, int $libraryId): void`.
- Pivots contain confidence, source and primary flag; service rejects targets outside library 42 for LightInTheBox.

- [ ] Write failing tests accepting node/cluster targets in library 42 and rejecting targets from another library without partial inserts.
- [ ] Run `php artisan test tests/Feature/KnowledgeKeywordAssociationTest.php`; expect failure.
- [ ] Implement pivot tables, reverse indexes, model relationships and transactional association validation.
- [ ] Run `php artisan test tests/Feature/KnowledgeKeywordAssociationTest.php tests/Feature/KeywordClusterServiceTest.php`; expect PASS.
- [ ] Commit with `git commit -m "feat: link knowledge to governed keyword taxonomy"`.

### Task 4: Deterministic Normalization and Classification

**Files:**
- Create: `tools/knowledge_corpus/__init__.py`
- Create: `tools/knowledge_corpus/normalize.py`
- Create: `tools/knowledge_corpus/classify.py`
- Create: `tools/knowledge_corpus/taxonomy.json`
- Test: `tools/knowledge_corpus/tests/test_normalize.py`
- Test: `tools/knowledge_corpus/tests/test_classify.py`

**Interfaces:**
- `normalize_document(raw: str) -> NormalizedDocument(markdown, normalized_hash, title, source_urls, warnings)`.
- `classify_document(relative_path: str, document: NormalizedDocument) -> Classification(brand_key, site_key, directory_path, language_code, authority_level, risk_level, review_status, generation_eligible, citation_required)`.

- [ ] Write a failing normalization test for Unicode/line endings, Chinese-style inline links, empty headings, isolated backslashes, repeated blank lines and idempotent hashes.
- [ ] Write table-driven failing classification tests for company, returns, delivery, product category, new arrivals, investor relations and mixed research; Portuguese content must resolve to `pt`.
- [ ] Run `python3 -m unittest discover -s tools/knowledge_corpus/tests -v`; expect import failures.
- [ ] Implement conservative normalization without claim rewriting or translation; extract provenance while retaining a provenance block.
- [ ] Implement path-first routing and content language signals; ambiguous records receive warnings instead of guessed classifications.
- [ ] Run the complete Python unit suite twice and verify stable hashes; expect PASS.
- [ ] Commit with `git commit -m "feat: normalize and classify governed knowledge"`.

### Task 5: Duplicate Resolver and Semantic Chunk Preview

**Files:**
- Create: `tools/knowledge_corpus/deduplicate.py`
- Create: `tools/knowledge_corpus/chunk.py`
- Test: `tools/knowledge_corpus/tests/test_deduplicate.py`
- Test: `tools/knowledge_corpus/tests/test_chunk.py`
- Modify: `app/Services/GeoFlow/KnowledgeChunkSyncService.php`
- Test: `tests/Feature/KnowledgeChunkGovernanceMetadataTest.php`

**Interfaces:**
- `resolve_exact(documents) -> (canonical_documents, duplicate_records)` follows Markdown, provenance completeness, date and lexical-path precedence.
- `find_near_duplicates(documents, threshold=0.92)` limits candidates by brand/language/directory family.
- `build_chunks(document, target_min=350, target_max=700)` returns heading-path chunks with governance metadata.

- [ ] Write failing exact-duplicate tests proving Markdown wins TXT mirrors and every discarded source receives `duplicate_of` plus reason.
- [ ] Write failing near-duplicate tests proving uncertain 0.92+ pairs remain separate, while strict subset mirrors merge only with unambiguous provenance precedence.
- [ ] Write failing chunk tests for heading boundaries, 350–700 token targets, paragraph/list splits and document isolation.
- [ ] Write a failing PHP test asserting chunk `metadata_json` includes brand, site, language, authority, lifecycle and source URL.
- [ ] Implement paragraph fingerprints, token-set similarity, chunk preview and PHP metadata propagation.
- [ ] Run `python3 -m unittest discover -s tools/knowledge_corpus/tests -v` and `php artisan test tests/Feature/KnowledgeChunkGovernanceMetadataTest.php tests/Feature/KnowledgeChunkAsyncSyncTest.php tests/Feature/KnowledgeChunkEmbeddingSyncTest.php`; expect PASS.
- [ ] Commit with `git commit -m "feat: deduplicate and semantically chunk knowledge"`.

### Task 6: Reproducible Corpus Builder

**Files:**
- Create: `tools/knowledge_corpus/build.py`
- Test: `tools/knowledge_corpus/tests/test_build.py`
- Runtime output: `/Users/litb/Documents/litb_geo/litb_zhishiku/knowledge_cleanup_work/output/{manifest.jsonl,duplicate_audit.jsonl,near_duplicate_review.jsonl,run_summary.json,cleaned/**.md}`

**Interfaces:**
- CLI: `python3 tools/knowledge_corpus/build.py --source <dir> --output <dir> --brand lightinthebox --site lightinthebox-main`.
- Stable document IDs derive from brand/site/canonical key; build is atomic and nonzero on required-metadata failure, divergent hash collision or path escape.

- [ ] Write a failing fixture test containing Markdown/TXT mirrors, policy, category and research files; assert source hashes remain unchanged, mirror count collapses to one and manifests agree.
- [ ] Run `python3 -m unittest tools.knowledge_corpus.tests.test_build -v`; expect failure.
- [ ] Implement temporary-directory build, full validation and atomic rename.
- [ ] Limit real-corpus discovery to `LightInTheBox_PT_Official_KB` and `officialknowege/LITB_Reports`; explicitly exclude TXT mirrors from canonical discovery after duplicate auditing, keyword-library directories, `.DS_Store` and `knowledge_cleanup_work`.
- [ ] Include only documents classified as `en` in the formal manifest; write `pt`, `zh`, and `und` records to an exclusion audit with source path, detected language and reason, and never relabel them as English.
- [ ] Group shared canonical URL/document keys as versions, select the newest reviewed version as active and retain older versions as `superseded` in the manifest.
- [ ] Run the builder against the real source corpus and record canonical count, duplicate count, near-review count and directory/language/authority distributions.
- [ ] Validate zero active exact-hash duplicates, no unknown owner, unchanged source hashes, policy eligibility rules and valid duplicate targets.
- [ ] Commit code only with `git commit -m "feat: build auditable governed knowledge corpus"`.

### Task 7: Transactional Governed Importer

**Files:**
- Create: `app/Console/Commands/ImportGovernedKnowledgeCommand.php`
- Create: `app/Services/GeoFlow/KnowledgeGovernance/GovernedKnowledgeImporter.php`
- Create: `app/Services/GeoFlow/KnowledgeGovernance/KnowledgeImportValidator.php`
- Test: `tests/Feature/ImportGovernedKnowledgeCommandTest.php`

**Interfaces:**
- CLI: `php artisan geoflow:knowledge:import-governed <manifest> --dry-run --keyword-library=42`.
- Upserts LightInTheBox, canonical site and nine root directories; queues chunk sync only after commit.

- [ ] Write failing tests proving dry-run writes nothing, first import creates governed rows, rerun is idempotent, and invalid ownership/missing duplicate targets roll back the whole batch.
- [ ] Run `php artisan test tests/Feature/ImportGovernedKnowledgeCommandTest.php`; expect failure.
- [ ] Implement pre-transaction manifest validation, config/source hashes, one-transaction commit, import audit rows and post-commit chunk dispatch.
- [ ] Run focused importer and chunk tests; expect PASS.
- [ ] Commit with `git commit -m "feat: import governed knowledge transactionally"`.

### Task 8: Mandatory Brand-Safe Retrieval

**Files:**
- Create: `app/Services/GeoFlow/KnowledgeGovernance/KnowledgeScope.php`
- Modify: `app/Services/GeoFlow/KnowledgeRetrievalService.php`
- Modify: callers found by `rg -n 'KnowledgeRetrievalService' app`
- Test: `tests/Feature/KnowledgeRetrievalIsolationTest.php`
- Modify: `tests/Feature/KnowledgeRetrievalServiceTest.php`

**Interfaces:**
- `KnowledgeScope` carries brand ID, site ID, language code and optional directory/keyword cluster IDs.
- Production retrieval methods require a scope and return evidence with citation/provenance metadata.

- [ ] Write a failing leakage test with a higher-scoring document in another brand and assert it never appears in LightInTheBox results.
- [ ] Add failing tests excluding inactive/ineligible documents and ranking current first-party policy above unreviewed research.
- [ ] Run `php artisan test tests/Feature/KnowledgeRetrievalIsolationTest.php`; confirm current unscoped failure.
- [ ] Apply ownership/lifecycle filters in SQL candidate selection before lexical/vector scoring; update every caller to supply scope.
- [ ] Run retrieval, article assistant and generation tests; expect PASS.
- [ ] Commit with `git commit -m "feat: enforce brand scope in knowledge retrieval"`.

### Task 9: Admin Governance and Directory Navigation

**Files:**
- Modify: `app/Http/Controllers/Admin/KnowledgeBaseController.php`
- Create: `app/Http/Requests/Admin/StoreGovernedKnowledgeRequest.php`
- Modify: `resources/views/admin/knowledge-bases/index.blade.php`
- Modify: `resources/views/admin/knowledge-bases/form.blade.php`
- Modify: `resources/views/admin/knowledge-bases/detail.blade.php`
- Modify: translation files found by `rg -l 'knowledge_bases' lang`
- Test: `tests/Feature/AdminKnowledgeGovernanceTest.php`

**Interfaces:**
- Filters: brand, site, directory, language, authority, risk, review and lifecycle.
- Forms require owner/provenance fields and enforce policy eligibility rules.

- [ ] Write failing feature tests for brand/site switchers, directory tree, combined filters, cross-brand validation, provenance fields and duplicate/version badges.
- [ ] Run `php artisan test tests/Feature/AdminKnowledgeGovernanceTest.php`; expect failure.
- [ ] Implement a focused form request, eager-loaded filtered query and form/detail/list UI without removing existing upload/edit behavior.
- [ ] Run admin governance, materials page and source parser tests; expect PASS.
- [ ] Commit with `git commit -m "feat: manage governed knowledge by brand and directory"`.

### Task 10: Legacy Reference Migration and Cleanup

**Files:**
- Create: `app/Console/Commands/MigrateLegacyKnowledgeCorpusCommand.php`
- Create: `app/Services/GeoFlow/KnowledgeGovernance/LegacyKnowledgeMigrationService.php`
- Test: `tests/Feature/MigrateLegacyKnowledgeCorpusCommandTest.php`

**Interfaces:**
- CLI: `php artisan geoflow:knowledge:migrate-legacy --batch=<id> --dry-run`.
- Produces JSON rollback mapping from legacy IDs to canonical IDs and reference counts; commit mode requires `--backup-verified`.

- [ ] Write failing tests migrating `tasks.knowledge_base_id` and `task_knowledge_bases` references while preserving pivot uniqueness.
- [ ] Add a failing test that discovers other PostgreSQL references and archives an alias instead of deleting a row that cannot be safely reassigned.
- [ ] Run `php artisan test tests/Feature/MigrateLegacyKnowledgeCorpusCommandTest.php`; expect failure.
- [ ] Implement dry-run reports, persisted rollback mapping and transactional reassignment; delete only unreferenced duplicates after mapping exists.
- [ ] Run migration and task/API contract tests; expect PASS.
- [ ] Commit with `git commit -m "feat: migrate legacy knowledge references safely"`.

### Task 11: Formal Corpus Migration and Operational Verification

**Files:**
- Create: `docs/operations/brand-scoped-knowledge-import.md`
- Runtime backup: `backups/geo_flow_before_governed_knowledge_<timestamp>.dump`
- Update external work logs: `knowledge_cleanup_work/{findings.md,progress.md}`

**Interfaces:**
- Runbook records exact build, dry-run, backup, import, migration, queue, verification and restore commands.

- [ ] Run all PHP and Python tests before data mutation; record baseline and pre-existing failures.
- [ ] Rebuild the real corpus and inspect all manifest distributions and ineligible/review queues.
- [ ] Run governed import and legacy migration in `--dry-run` mode; save counts.
- [ ] Create `pg_dump -Fc` backup and verify it with `pg_restore --list`; stop if either fails.
- [ ] Run migrations, formal import with keyword library 42, and legacy migration with `--backup-verified`.
- [ ] Process chunk jobs and query invariants: one LightInTheBox brand/site, nine roots, no active exact duplicates, no broken references, no cross-brand retrieval, valid library-42 links and ready eligible chunks.
- [ ] Smoke-test `/geo_admin/knowledge-bases`: navigate tree, filter Portuguese policy, inspect provenance/chunks/keywords and run one scoped retrieval preview.
- [ ] Write final counts, backup path, batch ID, rollback mapping and known review items in the runbook.
- [ ] Commit with `git commit -m "docs: add governed knowledge migration runbook"`.

### Task 12: Final Review and Handoff

**Files:**
- Review all files and artifacts from Tasks 1–11.

**Interfaces:**
- Produces a review-ready branch and evidence-backed completion report.

- [ ] Map every specification acceptance criterion to a passing test or recorded operational check.
- [ ] Run PHP/Python suites, `git diff --check`, migration status, corpus validator and post-import SQL invariants.
- [ ] Confirm no credentials are committed, original source hashes remain unchanged, importer is scoped/idempotent and destructive cleanup requires verified backup.
- [ ] Use `superpowers:requesting-code-review`, fix blocking findings and rerun affected tests.
- [ ] Record commits, test counts, pre-existing failures, DB counts, backup path, browser smoke result and deferred non-blocking issues in the handoff.
