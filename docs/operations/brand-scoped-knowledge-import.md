# Brand-Scoped Knowledge Governance — Formal Migration Runbook

Date: 2026-08-20 (Asia/Shanghai)
Branch: `feature/keyword-intelligence`
Plan: `docs/superpowers/plans/2026-08-19-brand-scoped-knowledge-governance.md`
Design: `docs/superpowers/specs/2026-08-19-brand-scoped-knowledge-governance-design.md`
SDD ledger: `.superpowers/sdd/2026-08-19-brand-scoped-knowledge-governance/progress.md`

This document records the exact commands, evidence and results of Task 11 — the first task
allowed to mutate the formal (dev) database — plus two defects discovered and fixed along the way.

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

## 9. Known non-blocking issues

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
