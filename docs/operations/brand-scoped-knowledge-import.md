# Brand-Scoped Knowledge Governance — Formal Migration Runbook

Date: 2026-08-20 (Asia/Shanghai)
Branch: `feature/keyword-intelligence`
Plan: `docs/superpowers/plans/2026-08-19-brand-scoped-knowledge-governance.md`
Design: `docs/superpowers/specs/2026-08-19-brand-scoped-knowledge-governance-design.md`
SDD ledger: `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/progress.md`

This document records the exact commands, evidence and results of Task 11 — the first task
allowed to mutate the formal (dev) database — plus two defects discovered and fixed along the way,
and Task 12's final whole-branch review, which found and fixed a **Critical** corpus-classification
defect that had excluded the actual flagship policy documents from the corpus Task 11 originally
imported. **§12 onward supersedes the document counts/state described in §1-§11** (which are kept
for audit-trail purposes, describing the real sequence of events) — read §12 for the corrected,
final state of the formal corpus and database.

## 1. Baseline (before any mutation)

- Full PHP suite: `docker compose exec -T app php artisan test --compact`
  → 2035 passed, 7 failed (pre-existing, matches the SDD ledger's documented baseline exactly),
  2 skipped, 15,934 assertions, 91.84s. No regressions from Tasks 1–10.
- Full Python suite: `PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tools/knowledge_corpus/tests -v`
  → 51/51 passed.
- `php artisan migrate:status` → 0 pending (dev container auto-migrates on boot).

## 2. Real corpus rebuild and inspection

```
PYTHONDONTWRITEBYTECODE=1 python3 tools/knowledge_corpus/build.py \
  --source /Users/litb/Documents/litb_geo/litb_zhishiku \
  --output /Users/litb/Documents/litb_geo/litb_zhishiku/knowledge_cleanup_work/output \
  --brand lightinthebox \
  --site lightinthebox-main
```

First run reproduced Task 6's recorded hashes exactly (154 source files unchanged, 5 active
English documents, 144 exclusions, 0 near-review rows) — confirming the source corpus is
unmodified. This run's `exclusion_audit.jsonl` count (144) turned out to include a defect (see
§4). After the fix, the corrected, final corpus state is:

- Source-set hash: `aaa571308fb22d3a549d6d3afb5d779386160a6a2769cfffee19bf60934703f7`
- Manifest hash: `07f3e5213d3b5b2194832fb0ccd78c3db7f7386c41f2b1407459669b5e2f52f8`
- Duplicate audit hash: `b1374ccb330eb990220e82f57be965ad590b96bef6ee2e2ccc728d3a499c8ef6`
- Exclusion audit hash (post-fix): `214d2d4612dfe21fdd16d46fb3502020606caf899e94bf5b7013e429e9d25e29`
- Empty near-review hash: `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
- Counts: 154 source files hashed, 5 active/manifest documents, 71 duplicate records, **78**
  excluded sources (`pt=11, zh=19, und=48`), 0 near-review records.
- All builder self-validation flags `true`, including `source_hashes_unchanged`.

## 3. Governed import dry-run

```
docker exec geoflow-app rm -rf /tmp/knowledge_cleanup_work_output
docker cp /Users/litb/Documents/litb_geo/litb_zhishiku/knowledge_cleanup_work/output geoflow-app:/tmp/knowledge_cleanup_work_output
docker compose exec -T app php artisan geoflow:knowledge:import-governed /tmp/knowledge_cleanup_work_output/manifest.jsonl --dry-run
```

(The corpus output lives outside the app container's bind mount — `litb_zhishiku` is a sibling
directory to the GEOFlow repo — so it is copied into the container with `docker cp` before each
import invocation; the original, canonical output under `knowledge_cleanup_work/output` is never
modified by this step.)

First attempt against the pre-fix corpus failed:
`governed_import.exclusion_row_67.identity_collision` (see §4). After the fix and a corpus
rebuild, the dry-run passed cleanly:

```json
{"status":"validated","batch_id":null,"imported_count":5,"duplicate_count":71,
 "source_checksum":"aaa571308fb22d3a549d6d3afb5d779386160a6a2769cfffee19bf60934703f7", ...}
```

Legacy migration's own `--dry-run` (`plan()`) requires a real `KnowledgeImportBatch` id, which
does not exist until the governed import actually commits (confirmed in
`GovernedKnowledgeImporter.php`: dry-run mode never persists a batch row). It was therefore run
immediately after the real import commit (§6), before the legacy migration's own commit — see §7.

## 4. Defect 1 — corpus builder double-recorded exact-duplicate TXT mirrors as their own exclusions

**Root cause:** `build_corpus()` in `tools/knowledge_corpus/build.py` called
`_build_exclusion_audit(documents, brand, site)` using the pre-dedup 154-file list instead of
`canonical_documents` (the 83-file list that already survived exact-duplicate resolution). Every
non-English TXT mirror that `resolve_exact()` had already correctly recorded in
`duplicate_audit.jsonl` (as an exact duplicate of its MD sibling) also got independently
classified and given its own `exclusion_audit.jsonl` row. Because `document_id` is content-derived
and an MD/TXT-mirror pair is byte-identical, the two rows shared the same `document_id`, which the
importer's identity-uniqueness validator (`KnowledgeImportValidator::addIdentity()`) correctly
rejected. All 66 `source_kind: txt_mirror` exclusion rows in the real corpus exhibited this.

**Fix:** commit `c956a53` — pass `canonical_documents` instead of `documents`. New regression test
proves real target-resolution consistency (not just row counts). Full Python suite: 52/52.
Real-corpus rebuild (run twice, byte-identical): `excluded_sources` 144→78, `duplicate_records`/
`active_documents`/`manifest_documents` unchanged, all validation flags `true`, all 154 source
file hashes outside `knowledge_cleanup_work/` unchanged. Fresh independent review: Approved, 0
findings (also independently verified the pre-existing test's "4→3" count correction was itself a
legitimate bug-consistent fix, and that no exclusion coverage was lost for genuinely-excluded MD
canonicals).

## 5. Defect 2 — migration-history drift in this dev database

**Root cause:** `database/migrations/2026_08_19_000400_harden_governed_knowledge_import_batches.php`
has two `Schema::table` blocks in `up()`. Only the second (a `knowledge_import_batches` unique
constraint) had applied in this specific dev database; the first (three `knowledge_import_rows`
columns — `dispatch_claim_token`, `dispatch_claimed_at`, `dispatch_attempt_count` — plus an index)
had not, proving the file was edited (that block added, presumably during a Task 7 review round)
after this dev database had already run an earlier version of it. Laravel tracks migrations by
filename only and never re-runs on content change. Confirmed via `information_schema.columns`
against every knowledge-governance Eloquent model's `$fillable` that this was the only such drift
anywhere in the schema. Surfaced as a real-import crash:
`SQLSTATE[42703]: column "dispatch_claimed_at" does not exist` during chunk-dispatch, after the
import's document/batch/row commit had already succeeded (5 documents, batch 1, 76 rows).

**Fix:** new additive, idempotent migration `2026_08_20_000100_reconcile_knowledge_import_rows_dispatch_columns`
(commit `b8beaa4`, `Schema::hasColumn()`/index-existence guarded so it safely no-ops in any
environment where `2026_08_19_000400...`'s current content already applied in full — CI, fresh
installs, other developers' machines). Fix round 1 (commit `dd41772`) made `down()` an intentional
no-op after review found the original `down()` would collide with `2026_08_19_000400...`'s own
`down()` and break an ordinary `migrate` → `migrate:rollback` cycle in every non-drifted
environment; new regression test reproduces the actual same-batch rollback collision. Scoped
re-review: Approved, 0 open findings.

The migration applied to this dev database automatically: the `geoflow-queue` container's
entrypoint runs `php artisan migrate --force` on every restart, and it restarted (ordinary
container lifecycle, unrelated to this work) shortly after the migration file was committed to the
shared bind-mounted volume. Verified directly via `psql \d knowledge_import_rows` that the
resulting columns/index match the intended definitions exactly.

## 6. Verified backup

```
mkdir -p backups
docker exec geoflow-postgres pg_dump -U geo_user -d geo_flow -Fc -f /tmp/geo_flow_backup.dump
docker cp geoflow-postgres:/tmp/geo_flow_backup.dump backups/geo_flow_before_governed_knowledge_20260820_094019.dump
docker exec geoflow-postgres pg_restore --list <copied-back-into-container>
```

- Path: `backups/geo_flow_before_governed_knowledge_20260820_094019.dump` (gitignored — a full DB
  dump must never be committed; `/backups` was added to `.gitignore`)
- Size: 11,208,302 bytes
- SHA-256: `d665e6dc42805bf39cb030813fc7c1acc380e9e1df9d492a9a2b00263f08eb10`
- `pg_restore --list`: exit 0, 1082 TOC entries, 97 `TABLE DATA` entries (confirmed data, not
  schema-only — e.g. `knowledge_bases` data present)
- Taken *before* the formal import committed (batch 1's document/row insert is not covered by
  Defect 2's schema fix timing — see §5; the backup captures state as of 156 knowledge bases,
  1,254 chunks, matching the documented pre-existing baseline exactly)

## 7. Real migration + formal import + legacy migration

```
docker compose exec -T app php artisan geoflow:knowledge:import-governed \
  /tmp/knowledge_cleanup_work_output/manifest.jsonl --keyword-library=42
```

Result: `{"status":"completed","batch_id":1,"imported_count":5,"duplicate_count":71,
"source_checksum":"aaa571308fb22d3a549d6d3afb5d779386160a6a2769cfffee19bf60934703f7",...}`

Batch 1: `status=completed`, `imported_count=5`, `duplicate_count=71`, `processed_count=76`,
`total_count=76`, `completed_at=2026-08-20 17:57:01`.

Chunk processing required starting a queue worker that was not running
(`docker compose up -d knowledge-queue` — a dedicated worker listening on the `knowledge` Redis
queue; the general `geoflow-queue` worker does not listen on that queue). All 5 documents reached
`chunk_sync_status=ready`; 23 chunks generated total, 0 errors.

```
docker compose exec -T app php artisan geoflow:knowledge:migrate-legacy --batch=1 --dry-run
docker compose exec -T app php artisan geoflow:knowledge:migrate-legacy --batch=1 --backup-verified
```

Both dry-run and commit returned identical results: `mapped_count=0, unmapped_count=0,
deleted_count=0, archived_count=0`. This matches Task 10's own documented prediction exactly: none
of the ~156 pre-existing legacy `knowledge_bases` rows have `source_file_hash` populated (that
column postdates them), so there is no confident identity match against the newly imported batch.
No legacy rows were touched — this is expected, correct, safe behavior, not a defect.

## 8. Post-migration invariants (SQL, read-only)

| Invariant | Result |
|---|---|
| One LightInTheBox brand/site | `knowledge_brands`: 1 row (`lightinthebox`); `knowledge_sites`: 1 row (`lightinthebox-main`, `www.lightinthebox.com`) |
| Nine roots | `knowledge_directories` with `parent_id IS NULL`: exactly 9, matching `KnowledgeImportValidator::ROOT_DIRECTORIES` |
| No active exact duplicates | 0 rows grouped by `(normalized_content_hash)` among active/owned documents with count > 1 |
| No broken references | 0 `knowledge_import_rows.canonical_document_id` or `knowledge_duplicates.canonical_document_id` pointing at a nonexistent `knowledge_bases.id` |
| Valid library-42 links | 0 keyword-node/cluster links exist for the 5 new documents (see §9 — known gap, not a validity violation: the real manifest never carried `keyword_node_ids`/`keyword_cluster_ids`, so there is nothing to validate yet, and nothing invalid) |
| Ready eligible chunks | All 5 documents (ids 157–161): `chunk_sync_status=ready`, chunk counts 3/5/2/7/6 = 23 total |
| No cross-brand retrieval | Structurally guaranteed — only one brand exists in the database; full retrieval-scope enforcement already covered by Task 8's 183 tests. Practical confirmation deferred to the user's browser smoke test (§9). |

## 9. Known non-blocking issues (as of Task 11 — see §12 for Task 12's additional findings)

- **No keyword-42 associations yet for the 5 new documents.** No task in the 1–10 sequence built
  an automated content-to-keyword-library-42 matcher — Task 3 built the association *storage and
  validation* service (fail-closed library ownership, sync semantics), and Task 7's importer
  passes through whatever `keyword_node_ids`/`keyword_cluster_ids` the manifest carries, but the
  Python corpus builder never populates those fields. The real `manifest.jsonl` has `null` for
  both fields on all 5 documents. This is a scope boundary, not a defect: associations can be set
  per-document via the Task 9 admin UI, or a future task could add automated matching. Recorded
  here so it is not mistaken for silently-broken behavior.
- **Browser smoke test not performed by an automated agent.** No browser-automation tooling
  (Playwright/chromium-cli) is installed in this environment, and the seeded system admin account
  (`2026_08_15_000400_seed_geoflow_system_admin`) has a random, non-retrievable password meant for
  internal `role=system` attribution, not interactive login. The user opted to perform this check
  themselves at `http://localhost:18082/geo_admin/knowledge-bases` rather than have Playwright
  installed. **This runbook should be updated with the user's confirmation once performed** —
  navigate the directory tree, filter to Portuguese policy documents (0 expected among the 5
  active English documents — this is a case worth checking the filter behaves correctly against
  the *excluded* PT rows since none are actively browsable, or filter/inspect any of the 5 active
  English documents' provenance/chunks), and run one scoped retrieval preview.
- **7 pre-existing PHP test failures**, unrelated to this branch (see §1), unchanged from the
  documented baseline: `AdminSystemUpdatesPageTest`, `ContentPipelineCompatibilityTest`,
  `ImageLibrarySecurityTest`, `PromptCompositionSeederTest` (and others per the compact reporter's
  count).

## 10. Final counts

- `knowledge_bases`: 161 (156 pre-existing legacy + 5 new governed)
- `knowledge_chunks`: 1,277 (1,254 pre-existing + 23 new)
- `knowledge_import_batches`: 1 (batch 1, completed)
- `knowledge_import_rows`: 76 (5 manifest + 71 duplicate)
- Legacy migration: 0 mapped, 0 unmapped, 0 deleted, 0 archived (expected — see §7)
- Backup: `backups/geo_flow_before_governed_knowledge_20260820_094019.dump`,
  SHA-256 `d665e6dc42805bf39cb030813fc7c1acc380e9e1df9d492a9a2b00263f08eb10`
- Commits this task: `c956a53`, `b8beaa4`, `dd41772` (defect fixes), this file (runbook)

## 11. Restore procedure (if ever needed)

```
docker cp backups/geo_flow_before_governed_knowledge_20260820_094019.dump geoflow-postgres:/tmp/restore.dump
docker exec geoflow-postgres pg_restore -U geo_user -d geo_flow --clean --if-exists /tmp/restore.dump
docker exec geoflow-postgres rm -f /tmp/restore.dump
```

Restores to the exact pre-migration state (156 knowledge bases, 1,254 chunks, no governance schema
data beyond what existed before this task — note the ownership/governance *schema* itself was
already migrated before this backup was taken, per §1, so a restore reverts *data*, not schema).
This restore point predates BOTH batch 1 (§7) and batch 2 (§12) — it is still the correct backup to
use for a full rollback of all governed-knowledge data added by this branch.

## 12. Task 12 final review — Critical defect and corrected re-import

Task 12 dispatched a full whole-branch review (base `bb68370`, head at the time `06d2e3c`, on the
most capable available model) covering all 42 commits, cross-task integration, the design's §17
acceptance criteria, and production readiness. Full findings are in the SDD ledger's Task 12
section. The most consequential finding:

### Critical — the imported corpus was missing its own flagship content

`tools/knowledge_corpus/classify.py`'s language detector used a bare absolute Han-character count
(`>= 2`) to flag "zh" as a language candidate, tied with equal weight against English/Portuguese
marker scores that run into the hundreds. The real Privacy Policy, Terms of Use and IP Policy
documents are genuinely English but use a Chinese-language inline-link-annotation convention
throughout (`（链接：url）`, "link:") — this tripped the bug and silently excluded them (and other
genuine English documents) as `"und"` (ambiguous), landing them in `exclusion_audit.jsonl` instead
of the manifest. The corpus batch 1 imported (§7) contained only 5 low-value documents — none of
the actual first-party policy content this governance system exists to import.

Resolved across three fix rounds, each independently reviewed, with two of the three rounds
catching real defects the round before had introduced or missed:

1. **Round 1** (`acf07b9`): stripped the link-annotation residue before language detection; added a
   proportional zh-candidacy rule. Real impact: 5→15 documents, `policy-compliance` 0→4, all 3
   named policy documents correctly admitted as `en`, all 5 previously-active documents unchanged.
2. **Round-1 review**: independently rebuilt the corpus, confirmed the impact reproduces exactly —
   but proved the proportional rule was inert (0/154 real documents affected by it) and
   dimensionally unsound (tolerance scaled with document length; demonstrated ~115-150 stray Han
   characters could be appended to the real Terms of Use/Privacy Policy and still pass as `en`).
   Also found a real near-duplicate pair newly admitted with zero review signal.
3. **Round 2** (`76c42f4`): replaced the proportional rule with a fixed absolute ceiling
   (`_MAX_RESIDUAL_HAN = 10`) so tolerance never scales with length; added a body-only
   near-duplicate signal routing matches to `near_duplicate_review.jsonl` for human review, never
   auto-merged.
4. **Round-2 review**: exhaustive truth-table verification of the new boolean logic; independently
   measured near-dup threshold sensitivity across all 35 real candidate pairs (target pair 1.000,
   next-closest 0.577 — wide margin). Found a new integration bug: the emitted near-dup review
   record used a `reason` string and `similarity` value that the PHP importer's validator
   (`KnowledgeImportValidator::validateNearReviews()`) would reject outright — making the corrected
   corpus **un-importable**. Neither test suite caught this because neither exercises the real
   cross-language contract.
5. **Round 3** (`33c7b03`): reused the existing allowlisted `reason`; reported the body-overlap
   score (not token-Jaccard) as `similarity`; raised the trigger threshold to exactly match the PHP
   floor (0.92) so any emitted record satisfies the contract by construction. Verified end-to-end,
   not just in isolation: ran the actual PHP importer's `--dry-run` against the real corrected
   corpus — clean. A negative control (same dry-run against the pre-fix output) reproduced the
   original rejection error, proving the fix load-bearing.
6. **Round-3 review**: independently re-ran the same end-to-end dry-run from scratch, reproduced
   the identical clean result. Approved, no open findings.

### Corrected corpus state

- Source-set hash: `aaa571308fb22d3a549d6d3afb5d779386160a6a2769cfffee19bf60934703f7` (unchanged —
  source files were never touched by any of this)
- Counts: 154 source files hashed, **15** active/manifest documents (was 5), 71 duplicate records,
  63 excluded sources, **1** near-review record (was 0 — the disclosed near-duplicate pair, routed
  to review, not merged)
- Directory distribution: `product-category`=8, `policy-compliance`=4, `brand-company`=2,
  `customer-transaction`=1
- All builder validation flags `true`, including `source_hashes_unchanged`; two consecutive builds
  byte-identical at every fix round

### Real re-import

```
docker exec geoflow-app rm -rf /tmp/knowledge_cleanup_work_output
docker cp /Users/litb/Documents/litb_geo/litb_zhishiku/knowledge_cleanup_work/output geoflow-app:/tmp/knowledge_cleanup_work_output
docker compose exec -T app php artisan geoflow:knowledge:import-governed /tmp/knowledge_cleanup_work_output/manifest.jsonl --keyword-library=42
```

Result: `{"status":"completed","batch_id":2,"imported_count":15,"duplicate_count":71,...}`. The
original 5 documents (ids 157-161) were updated in place via `asset_key` matching (no duplication);
10 new documents inserted (ids 163-172). All 15 `chunk_sync_status=ready`, 148 chunks total, 0
errors. Legacy migration re-run for batch 2 (dry-run then `--backup-verified` commit): 0 mapped/0
unmapped again, same reason as batch 1 (§7) — legacy rows still lack `source_file_hash`.

SQL invariants re-confirmed against the new state: 1 brand/site, 9 roots, 0 active exact
duplicates, 0 broken references, all 15 documents ready with chunks, 0 keyword-42 links (unchanged
known gap, see §9).

### Additional Important finding — legacy tasks silently lose knowledge context

Independently confirmed directly against the real database (not merely a code-reading finding):
`WorkerExecutionService::knowledgeScope()` returns `null` for any brand_id-null (legacy) document,
and `resolveKnowledgeContext()` returns `''` immediately on that — before ever reaching the
`fallbackKnowledgeContext()` path that previously supplied raw content. This is Task 8's own
deliberate, already-tested fail-closed design (never leak context across brand boundaries), not a
new defect — but its concrete consequence was never traced through and disclosed until now: exactly
7 real, currently-in-use tasks in the live database (task ids 7, 8, 9, 10, 11, 12, 13 — every task
in the system that references any knowledge base at all references a legacy one) will silently
receive zero knowledge context on their next generation run, with no error or log surfaced anywhere.
Each references a legacy row with 4-13 real chunks that previously fed generation. Task 10's legacy
migration cannot rescue these — matching requires `source_file_hash`, which postdates all 156
legacy rows (confirmed by both batch 1 and batch 2's migration runs each returning 0 mapped).

**This is a live-data remediation decision, not something resolved unilaterally in this task.** The
straightforward fix — assigning brand/site/directory ownership to these 7 specific legacy rows via
the already-built Task 9 admin edit form — is possible but out of this plan's stated scope and
affects real, currently-in-use application data. Flagged to the user; resolution (or an explicit
decision to leave it) should be recorded here once made.

### Additional disclosed gap — near-duplicate review has no admin-UI visibility

The near-duplicate pair (documents 163/165, `3.1 All You Need To Know` / `3.1.0 All You Need To
Know` — genuine first-party English link-index pages at distinct site URLs, not exact duplicates,
correctly routed to human review rather than auto-merged) is safely imported as two separate active
documents, and the underlying `near_duplicate_review.jsonl` record is validated by the importer —
but neither document is flagged anywhere in the admin UI. Grepped `KnowledgeBaseController` and
every knowledge-bases view for any near-duplicate surfacing; found none. The only record that these
two need a human look currently lives in the generated corpus file on disk, not the database or any
admin screen. Not fixed this round (scope discipline) — a future task should either surface
`near_duplicate_review.jsonl` in the admin UI or persist it to a queryable table.

### Deferred, non-blocking — chunk sizing

The Python chunker (`tools/knowledge_corpus/chunk.py`, built in Task 5) implements design §10's
sizing/merge rules but is never called by `build.py` — real chunking uses the pre-existing PHP
`KnowledgeChunkSyncService`, which has governance metadata but not §10's sizing logic. Some live
chunks are as small as 16 characters (a bare heading). §17.7's acceptance criterion (chunk
governance metadata) is fully satisfied; §10's retrieval-quality sizing intent is not. Recorded as
a known gap for a future task.

### Real regression caught and fixed at Task 12: pt_BR admin locale coverage

Full baseline suite rerun (required by Task 12) surfaced a real regression:
`tests/Unit/AdminPtBrLocaleCoverageTest.php` (2 of its 3 methods) failed because
`lang/pt_BR/admin.php` never picked up keys added across Tasks 9, 13, 15 and 16. Runtime was never
broken (the file's `array_replace_recursive($base=English, [...])` structure means untranslated
keys safely fall back to English display), but the enforced coverage test genuinely regressed.
Fixed in commit `06d2e3c`, independently reviewed clean — preserved all pre-existing genuine
Portuguese translations for `knowledge_bases`/`knowledge_detail` (initially assumed absent; the
implementer correctly discovered real existing translations and adapted rather than following the
controller's incomplete initial instruction), added 13 new genuine translations for
`keyword_intelligence.overview.classification.*`, extended the existing intentional-fallback
pattern for `content_center`. Full suite after: **6** failed (not 7) — the pt_BR fix resolved 2 of
the 8 that had surfaced; the remaining 6 are `OpenSourceReleaseScriptsTest`,
`AdminSiteThemeReplicationTest`, `AdminSystemUpdatesPageTest`, `ContentPipelineCompatibilityTest`,
`ImageLibrarySecurityTest`, `PromptCompositionSeederTest` — all pre-existing, unrelated to this
branch. (§9's "7 pre-existing" note below is superseded by this — 6 is the correct current count.)

## 13. Final counts (supersedes §10)

- `knowledge_bases`: 171 (156 pre-existing legacy + 15 governed, batch 2)
- `knowledge_chunks`: 1,402 (1,254 pre-existing + 148 governed)
- `knowledge_import_batches`: 2 (batch 1 superseded in place by batch 2's re-import; both
  `completed`)
- `knowledge_duplicates` for batch 2: 71
- `near_duplicate_review.jsonl`: 1 record (disclosed above, not merged)
- Legacy migration: 0 mapped, 0 unmapped, 0 deleted, 0 archived for both batches (expected)
- Backup: unchanged, `backups/geo_flow_before_governed_knowledge_20260820_094019.dump` (§6) — still
  the correct restore point for a full rollback of all governed-knowledge data
- Full PHP suite: 6 pre-existing failures (not 7 — see above), full Python suite 61/61
- Commits this task: `06d2e3c` (pt_BR fix), `acf07b9`/`76c42f4`/`33c7b03` (language-detection +
  near-dup fixes, 3 rounds), this file (runbook update)
