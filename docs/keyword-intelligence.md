# Keyword Intelligence Operations Guide

Keyword Intelligence extends the existing keyword libraries without replacing their routes or payloads. It adds audited workbook imports, a product taxonomy, rule-based topic clusters, and versioned opportunity scores. Operators use it under **Admin → Materials → Keyword Intelligence**.

## Before you start

- Apply all migrations before opening the screens: `php artisan migrate`.
- Run a queue worker (or Horizon). Confirmed imports dispatch `ImportKeywordWorkbookJob`; a web process does not import the workbook inline.
- Run Laravel Scheduler continuously (`php artisan schedule:work`, or the scheduler service from Docker Compose). It removes expired preview files hourly.
- Keep `APP_KEY` stable and secret. Preview tokens are encrypted with that key and cannot survive a key rotation.
- Keep the `local` filesystem disk private. Keyword workbooks are never public assets.

The importer accepts `.xlsx` only. Limits for file size, ZIP expansion, worksheets, rows, columns and cells are in `config/keyword_intelligence.php`. Increasing them raises memory and decompression risk; change them only after capacity testing.

## Preview, confirm, and private files

Preview and confirm are deliberately separate actions:

1. **Preview** uploads the workbook to `storage/app/private/keyword-import-previews/<uuid>.xlsx`, checks the workbook container and shape, suggests a source-column mapping, and displays bounded samples and rejection counts. It does not create keywords, metrics, batches, taxonomy records, clusters, scores, or queue jobs.
2. The server returns an encrypted preview token containing the generated path, SHA-256, source type, original display name, and a 30-minute expiry. The token is not a filesystem path and cannot be edited to select another file.
3. **Confirm** resolves the token, verifies its expiry, source type, generated path, existence, and SHA-256, then copies the file to `storage/app/private/keyword-imports/<uuid>.xlsx`. The original client filename is audit metadata only. Confirm creates the batch and dispatches the import job.
4. A successful confirmation removes the preview copy. Invalid or expired previews are rejected; the hourly `geoflow:prune-keyword-import-previews` schedule also removes preview files older than 30 minutes. The command can be run manually.

Confirmed batch files remain private so a failed or audited batch can be reproduced. GEOFlow does not currently auto-delete these source files; retention and backup policy for `storage/app/private/keyword-imports` must therefore be defined by the deployment owner. Do not delete a file for a batch that may be retried or audited.

Do not place the two LITB production workbooks in this repository. Compatibility tests read their external absolute paths only when explicitly enabled; only small synthetic fixtures are committed.

## Batch lifecycle and recovery

The operator-visible lifecycle is:

```text
uploaded → processing → completed
                     ↘ failed
completed/failed → reverting → reverted
```

The queued job is unique by batch ID for 15 minutes, retries up to three times, and has a five-minute execution timeout. Import uses deterministic rows, database constraints, and stored file hashes to support safe retry. Progress is stored on the batch and broadcast on the private `admin.keyword-imports.{batchId}` channel; the admin status endpoint remains the authoritative polling fallback.

- `uploaded`: confirmed and queued, not yet claimed.
- `processing`: claimed by a worker; processed/imported/duplicate/rejected counters advance.
- `completed`: all available rows were processed. Rejected rows remain in the audit table.
- `failed`: inspect `error_summary`, verify the private source file and worker logs, then retry through the queue tooling or revert. Do not edit batch hashes, mappings, or paths in the database.
- `reverting`: the transactional cleanup is in progress.
- `reverted`: batch-owned metrics and safely deletable keywords were removed; audit rows were retained.

The same workbook SHA-256 plus the same canonical import configuration cannot create a second batch. To import the same file legitimately, the mapping, target library, source type, locale/market metadata, or period must differ.

## Revert and immutable row audit

Revert never deletes `keyword_import_rows`. The rows are the durable link between the original sheet/row number, filtered source payload, normalized keyword, result, and any error.

- A keyword created by the batch is deleted only when it has no metric from another source/batch, cluster role, taxonomy membership, keyword/canonical relation, title reference, or article reference.
- Rows for a safely removed keyword are marked `reverted`.
- Rows whose keyword is still shared are marked `retained_shared`, and the batch increments `retained_shared_keyword_count`.
- Duplicate and rejected rows are also retained and marked `reverted` when the batch is reverted.
- Metrics owned by the reverted batch are deleted; metrics from other batches are untouched.

This behavior is intentional. Never “clean up” audit rows manually to make counts look smaller.

## Taxonomy invariants

A keyword taxonomy is a tree of stable materialized paths. All mutations must use `KeywordTaxonomyService`, not direct SQL.

- A node and its parent must belong to the same taxonomy.
- A node cannot be its own parent or move below one of its descendants.
- Depth cannot exceed the configured maximum.
- Moving a node updates its path, depth, and every descendant path atomically.
- Historical paths containing SQL wildcard characters are escaped during subtree operations.
- Nodes with children or protected business references cannot be deleted.
- A keyword may belong to more than one node, but only the requested assignment is marked primary for that operation.

Domain failures use stable keys such as `keyword_taxonomy.*`; the admin controller translates them to localized validation messages.

## Cluster candidates and confirmation

Candidate generation is deterministic and read-only. It groups active keywords conservatively by taxonomy node, language, market, brand relation, compatible intent, and normalized stem. The admin page issues a signed/encrypted candidate token; confirmation rebuilds and validates the candidate server-side rather than trusting submitted member roles.

Membership roles are `primary`, `secondary`, `question`, `variant`, and `brand_supporting`. A confirmed cluster is active and records `confirmed_by` and `confirmed_at`. Human-confirmed clusters are protected from silent replacement.

An active cluster claims its primary keyword within one language and market scope through `primary_scope_key`. Only one active cluster may hold that claim. A keyword that is already a primary is **blocked as another candidate's primary**, but it may still appear as a supporting member where the grouping rules allow it. Draft or archived clusters do not hold the active claim (`primary_scope_key` is `null`). Database uniqueness is the final concurrency guard.

## Opportunity scores

Scores are snapshots, not permanent keyword truth. Choose a start and end date; the service selects overlapping metrics for all cluster members, constrained to compatible language and market.

The total is calculated from demand, click performance, ranking opportunity, commercial relevance, content gap, and freshness, minus missing/stale-data penalties. Branded and non-brand clusters use different versioned weight profiles from `config/keyword_intelligence.php`. Components are reconciled in integer cents, so displayed components minus penalties exactly equal the displayed total.

Each result stores:

- score version and period;
- total, components, and penalties;
- input metric rows and aggregate snapshot;
- the weights used at calculation time.

Recomputing the same cluster/version/period replaces that snapshot and updates the cluster's cached `latest_opportunity_score`. Changing the configured scoring version creates a distinct historical record.

## Security and configuration boundaries

- Preview never implies confirmation; confirmation and revert require authenticated admin POST requests and CSRF protection.
- Mapping keys are workbook headers and values are a fixed allow-list. Unknown columns can be retained in filtered source payloads but are not silently treated as metrics.
- Formula cells are read as stored values; GEOFlow does not evaluate workbook formulas.
- The ZIP preflight limits archive entries, expanded bytes, per-entry bytes, compression ratio, sheets, rows, columns, and total data cells.
- Locale and market values are normalized and validated. Missing source metrics remain `null`, never a fabricated zero.
- Logs and progress events identify batches and failures; do not add raw workbook rows, credentials, tokens, or private file contents to logs.
- Scoring configuration is application code/configuration. Record a new `opportunity_scoring.version` when changing scoring semantics or weights.

## Deployment and verification

For Docker deployments, use the application image that includes PhpSpreadsheet's required PHP extensions, then run:

```bash
docker compose up -d postgres redis app queue scheduler
docker compose exec app php artisan migrate
docker compose exec app php artisan route:list --path=keyword-intelligence
docker compose exec app php artisan schedule:list
```

For a local test environment, use a disposable database and never point `--env=testing` at production:

```bash
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/AdminKeywordIntelligenceTest.php
php artisan test tests/Feature/KeywordImportServiceTest.php tests/Feature/KeywordImportJobTest.php
```

After deployment, verify that the queue consumes a synthetic import, the scheduler lists `geoflow:prune-keyword-import-previews`, and the admin routes are behind the existing admin authentication middleware.

