# GEOFlow LightInTheBox Knowledge Governance — Claude Code Handoff

Date: 2026-08-20 (Asia/Shanghai)  
Branch: `feature/keyword-intelligence`  
Worktree: `/Users/litb/Documents/litb_geo/GEOFlow/.worktrees/keyword-intelligence`  
Current committed HEAD: `f46a3c3 fix: align governed knowledge identity and edit history`

## 1. Objective and fixed product decisions

Build a brand-scoped, directory-governed knowledge system for GEO/SEO content generation. LightInTheBox is the first brand/site. Future independent brands/sites must never share retrieval candidates or content-generation evidence.

Fixed decisions:

- Initial formal corpus is English-only. Portuguese, Chinese and undetermined files remain accurately classified and auditable, but are excluded from the initial database import.
- Every knowledge document belongs to exactly one complete `(brand_id, site_id, directory_id)` ownership tuple, or remains a legacy/unowned row that must fail closed in governed retrieval/edit paths.
- Retrieval requires explicit brand, site and language scope. It may never fall back to another brand/site.
- Original corpus is read-only. Generated corpus artifacts live under `knowledge_cleanup_work/output`.
- Formal database mutation is deferred to Task 11 and requires a verified PostgreSQL backup first.
- Keyword library 42 is the formal LightInTheBox taxonomy: 14,719 keywords, 7,322 nodes and 7,024 language clusters.
- Preserve all unrelated user changes listed in section 6.

## 2. Authoritative documents

- Design: `docs/superpowers/specs/2026-08-19-brand-scoped-knowledge-governance-design.md`
- Implementation plan: `docs/superpowers/plans/2026-08-19-brand-scoped-knowledge-governance.md`
- SDD ledger: `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/progress.md`
- Per-task reports: `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/task-*-report.md`
- Earlier environment handoff: `HANDOFF.md` (untracked user/existing file; do not overwrite)

## 3. Completed work

### Task 1 — ownership schema/models

Commits: `ad2240a`, `f082281`. 49 tests / 221 assertions. Review approved.

- Brand/site/directory ownership and complete-tuple database constraint.
- Partial ownership is rejected; legacy all-null ownership remains representable for migration only.

### Task 2 — governance/version/import audit

Commits: `410d7df`, `01ef243`, `a147e68`. 20 tests / 37 assertions. Review approved.

- Lifecycle, provenance, authority/risk/review fields.
- Scoped import batches/rows/duplicate audit with populated-table-safe backfill.

### Task 3 — keyword associations

Commits: `b7c295a`, `216045f`. 28 tests / 117 assertions. Review approved.

- Atomic document-to-keyword-node/cluster synchronization.
- Fail-closed library ownership; explicit replacement/deletion/idempotence semantics.

### Task 4 — normalization/classification

Commits: `41d5388`, `d801710`. 12 Python tests passed twice. Review approved.

- Deterministic path-first taxonomy classification.
- Conservative `en/pt/zh/und` detection.
- Safe HTTP(S) wrapper-link normalization and taxonomy guards.

### Task 5 — exact/near dedupe and semantic chunking

Commits: `7427ce3`, `03e2a06`, `c34d6ad`. 32 Python tests plus 38 PHP tests / 201 assertions. Review approved.

- Superset-safe near merges, brand+language exact-dedupe scope.
- Heading-aware balanced chunks, CJK/mixed-script handling and provenance propagation.

### Task 6 — auditable corpus builder

Commits: `6c26268`, `355cf72`, `3ead783`. 50 Python tests. Review approved.

Real corpus output:

`/Users/litb/Documents/litb_geo/litb_zhishiku/knowledge_cleanup_work/output`

Stable results from two identical builds:

- 154 source files rehashed unchanged: 83 Markdown + 71 TXT mirrors/duplicates.
- 5 active English formal documents.
- 144 exclusions: `pt=22`, `zh=34`, `und=88`.
- 0 near-review rows.
- Source-set hash: `aaa571308fb22d3a549d6d3afb5d779386160a6a2769cfffee19bf60934703f7`
- Manifest hash: `07f3e5213d3b5b2194832fb0ccd78c3db7f7386c41f2b1407459669b5e2f52f8`
- Duplicate audit hash: `b1374ccb330eb990220e82f57be965ad590b96bef6ee2e2ccc728d3a499c8ef6`
- Exclusion audit hash: `d3b2df78e838d980d1b81117e1373d3184d48d951b227a4784f5f8738db1688c`
- Empty near-review hash: `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`

### Task 7 — governed importer

Commits: `02bdee7`, `6fe298d`, `13280fd`, `1f5725c`. 100 tests / 563 assertions. Final review approved.

- Validates front matter, canonical body hashes, IDs and all audit artifacts before writes.
- Recomputes the Task 6 source-set hash.
- Final-state-aware lifecycle preflight and lock-inside revalidation close concurrent-package TOCTOU.
- Permits exclusion/duplicate source overlap only when identity/hash are exactly consistent.
- Atomic leased chunk dispatch, durable retries, legacy batch reconciliation and near-review validation.
- No live/formal import has been run.

### Task 8 — mandatory brand-safe retrieval

Commits: `953cf13`, `46d2500`, `382dd6c`, `0c853e9`. 183 tests / 780 assertions. Final review approved.

- `KnowledgeScope` is mandatory for all production retrieval methods.
- Brand/site/language/lifecycle/eligibility plus optional directory/cluster filters occur in SQL before lexical/vector scoring.
- Document governance/provenance is authoritative; chunk metadata cannot override citations, risk or review status.
- Versions are selected once per scoped canonical document identity. All losing-version chunks are excluded, including losing-only sections; all relevant winner chunks remain.
- All known callers explicitly create scope and fail closed on incomplete ownership.

### Task 9 — admin governance UI

Base commits: `f650460`, `a2ddbc9`, `f46a3c3`.

Implemented:

- Brand/site switchers, governed directory tree, combined filters, 20/page pagination.
- Filters for directory, language, authority, risk, review and lifecycle.
- Strict cross-owner validation and required provenance fields.
- Research citation/generation safety, duplicate/version badges, detail governance metadata and stale/overdue warnings.
- Stable raw-byte source hashes and deterministic multi-file aggregate hashes.
- Admin/import content hashes were moved toward a shared normalizer.
- Imported provenance is preserved and operator edits are appended under transaction/row lock.
- Shared BCP-47 canonicalization including private-use tags.

Last committed verification: focused admin 22/219; importer 35/292; broad affected 176/1,343; Pint, PHP lint, Blade compilation and diff checks passed.

Task 9 is **not yet review-approved**. See current work in section 4.

## 4. Current in-progress work — resume here first

Task 9 review fix round 3 is in progress and intentionally uncommitted. A reviewer found:

1. Python and PHP wrapper-URL validation differed for malformed ports such as `https://example.com:bad/a`, producing different canonical content/hashes for the same raw text.
2. The concurrency regression manually copied an injected provenance event into the hydrated model, so it did not prove that `lockForUpdate()` plus reload prevents lost audit events.

The active implementer was instructed to:

- Add shared canonicalization vectors consumed by Python and PHP.
- Align both runtimes fail-closed for malformed ports/hosts while preserving safe HTTP(S) normalization.
- Cover NFC, CRLF, internal spaces, exactly one terminal newline, valid/invalid wrappers, malformed ports and userinfo/host edges.
- Replace the flawed concurrency regression with a test that does not repair the tested model; verify lock/reload behavior for both edit routes.
- Run Python corpus, PHP importer/admin and broad affected suites.
- Append `task-9-report.md` and create a scoped fix commit only after all checks pass.

Currently modified/untracked Task 9 round-3 files (inspect; do not discard):

- `app/Services/GeoFlow/KnowledgeGovernance/GovernedKnowledgeContentNormalizer.php`
- `tests/Feature/AdminKnowledgeGovernanceTest.php`
- `tests/Feature/AdminKnowledgeGovernanceConcurrencyTest.php` (new)
- `tests/Unit/GovernedKnowledgeContentNormalizerTest.php` (new)
- `tools/knowledge_corpus/normalize.py`
- `tools/knowledge_corpus/tests/test_normalize.py`
- `tools/knowledge_corpus/tests/fixtures/` (new shared vectors)
- `tools/knowledge_corpus/__pycache__/` (generated, should not be committed; remove/ignore after confirming it contains only bytecode)

Before continuing, run `git diff --` on those files and read the latest `task-9-report.md`. Do not reset them. Finish Task 9 round 3, generate a review package from `f46a3c3` to the new commit, and request a fresh independent review. Fix any Critical/Important findings before marking Task 9 complete.

Latest implementer status at handoff time:

- Shared fixture exists at `tools/knowledge_corpus/tests/fixtures/canonicalization_vectors.json`; both Python and PHP tests consume it.
- Both runtimes now reject malformed ports, userinfo and invalid hostname labels, while accepting safe HTTP(S)/IPv6.
- The old callback-based concurrency test was removed. The replacement uses `DatabaseTruncation`/autocommit, a stale model plus an independently committed prior event, and a lock-aware SQLite grammar spy for both edit routes.
- Mutation check: temporarily removing both controller locks made the test fail as intended (2 observed locks versus 4 expected); locks were restored.
- Evidence so far: shared Python 7/7; full Python corpus 51/51; PHP vector+concurrency 2/2 and 44 assertions before the mutation check.
- Read-only real-corpus audit: 154 artifact source paths, 0 normalized-hash mismatches and 0 wrapper-validity changes. Existing real artifacts are unaffected.
- Exact remaining round-3 work: rerun concurrency after lock restoration; run focused admin/importer; append `task-9-report.md`; run Pint, PHP lint, Python compile, Blade compile and diff checks; run broad PHP suite; stage only scoped files and commit; then obtain fresh independent review.

## 5. Remaining ordered tasks

### Task 10 — safe legacy reference migration

Create:

- `app/Console/Commands/MigrateLegacyKnowledgeCorpusCommand.php`
- `app/Services/GeoFlow/KnowledgeGovernance/LegacyKnowledgeMigrationService.php`
- `tests/Feature/MigrateLegacyKnowledgeCorpusCommandTest.php`

Command: `php artisan geoflow:knowledge:migrate-legacy --batch=<id> --dry-run`.

Requirements:

- Reassign `tasks.knowledge_base_id`, `task_knowledge_bases` and every discovered PostgreSQL reference.
- Preserve pivot uniqueness.
- Produce a JSON rollback mapping with legacy/canonical IDs and reference counts.
- Archive aliases that cannot be safely reassigned; delete only unreferenced duplicates after mapping exists.
- Commit mode requires `--backup-verified`.
- Expected commit: `feat: migrate legacy knowledge references safely`.

### Task 11 — formal migration and operational verification

This is the first task allowed to mutate the formal database. Required order:

1. Run complete PHP/Python baseline.
2. Rebuild and inspect real corpus.
3. Run governed import and legacy migration with `--dry-run` and save counts.
4. Create `pg_dump -Fc` backup.
5. Verify backup with `pg_restore --list`; stop on any failure.
6. Run migrations, formal English import linked to keyword library 42, then legacy migration with `--backup-verified`.
7. Process chunk jobs and verify invariants: one LightInTheBox owner, nine roots, no active exact duplicates, no broken references, no cross-brand retrieval, valid library-42 links and ready eligible chunks.
8. Browser-smoke `/geo_admin/knowledge-bases`: tree, filters, provenance/chunks/keywords and scoped retrieval preview.
9. Record backup path, batch ID, mapping and counts in `docs/operations/brand-scoped-knowledge-import.md`.

Do not skip the verified backup gate.

### Task 12 — final review and handoff

- Map every design acceptance criterion to a test/operational check.
- Run full PHP/Python suites, corpus validator, migration status, SQL invariants and `git diff --check`.
- Confirm no credentials committed and original corpus hashes unchanged.
- Request final independent branch review and fix blocking findings.
- Record final DB counts, backup, browser smoke, known non-blocking issues and all commits.

## 6. Unrelated user-owned changes — never overwrite or stage accidentally

These predated/currently sit outside the knowledge-governance task:

- `app/Console/Commands/SeedPresetBlueprintTemplatesCommand.php`
- `tests/Feature/SeedPresetBlueprintTemplatesCommandTest.php`
- `docker-compose.yml`
- `HANDOFF.md` (untracked existing handoff)

Always stage explicit Task files, inspect `git diff --cached`, and leave these untouched.

## 7. Existing database/runtime facts

- PostgreSQL Docker service/container: `geoflow-postgres` (historically had recovery quirks; inspect before mutation).
- Existing legacy knowledge counts observed before this work: 156 knowledge bases, 1,254 chunks, 72 exact duplicate groups.
- Formal governed migrations/import have not been applied/run in the live development database during Tasks 1–9.
- Task 7 noted migration `2026_08_19_000400...` had not yet been formally applied; editing that migration was safe only under that condition. Recheck `php artisan migrate:status` before formal work.
- The real corpus and source files must remain read-only; Task 6 verified all 154 source hashes unchanged.

## 8. Suggested immediate Claude Code commands/checks

From the worktree:

```bash
cd /Users/litb/Documents/litb_geo/GEOFlow/.worktrees/keyword-intelligence
git status --short
git diff -- app/Services/GeoFlow/KnowledgeGovernance/GovernedKnowledgeContentNormalizer.php tools/knowledge_corpus/normalize.py tests/Feature/AdminKnowledgeGovernanceTest.php tests/Feature/AdminKnowledgeGovernanceConcurrencyTest.php tests/Unit/GovernedKnowledgeContentNormalizerTest.php tools/knowledge_corpus/tests/test_normalize.py tools/knowledge_corpus/tests/fixtures
git log --oneline -25
```

Then finish Task 9 round 3 using the exact test commands recorded in `task-9-report.md` or the project Docker test convention. Remove only generated `__pycache__` after verifying it is generated bytecode; do not use broad cleanup/reset commands.

After a scoped commit, generate/read a diff from `f46a3c3` to the new HEAD and obtain a fresh review. Only then update the ledger from Task 9 pending to complete and begin Task 10.

## 9. Workflow expectations

- One implementer task at a time; fresh reviewer after each task/fix.
- Tests first and record RED/GREEN evidence.
- Up to five fix/review rounds for blocking findings.
- Never claim completion from the implementer’s report alone; require an independent review with no Critical/Important findings.
- Preserve audit rows on rollback/reversal; never perform destructive cleanup without a verified backup and mapping.
