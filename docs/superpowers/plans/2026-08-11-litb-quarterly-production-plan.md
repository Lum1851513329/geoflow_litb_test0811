# LITB Quarterly Article Production Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate, quality-check, locally archive, and synchronize the confirmed LITB quarterly briefs as traceable English Article drafts.

**Architecture:** Add a batch coordinator around the existing content generation request, dispatcher, quality gate, evidence snapshot, and adoption domains. A deterministic archive writer mirrors each run into WordPress-ready Markdown, evidence manifests, and quality JSON; resumable batch state prevents duplicate model calls.

**Tech Stack:** PHP 8.2+, Laravel queues, PostgreSQL, Markdown/YAML, PHPUnit, Docker Compose

## Global Constraints

- This plan starts only after the foundation plan has imported and validated exactly 90 reserved briefs.
- Use reviewed KnowledgeBase evidence and the exact Prompt preset/module versions captured by each generation run.
- Never invent current products, prices, inventory, delivery promises, policies, financial data, news, quotations, sources, statistics, or first-hand experience.
- New-arrivals briefs without current product evidence remain `needs_evidence` and are not generated as publishable articles.
- Generated results remain drafts until an authorized reviewer adopts and publishes them.
- Preserve immutable run, evidence, module, strategy, and blueprint snapshots.
- Archive paths are stable by brief asset key, not database ID.
- Existing `docker-compose.yml` modifications remain untouched.

---

## File Structure

- `app/Services/GeoFlow/LitbAssets/LitbArticleArchiveWriter.php`: atomic Markdown, evidence, and quality output.
- `app/Services/GeoFlow/LitbAssets/LitbQuarterlyGenerationCoordinator.php`: selects eligible briefs and requests generation idempotently.
- `app/Console/Commands/GenerateLitbQuarterlyContentCommand.php`: dry-run, batch, resume, and brief filters.
- `app/Listeners/ArchiveLitbContentGeneration.php`: archives terminal LITB generation runs.
- `content/litb-quarterly-2026-q3/`: ignored runtime archive with committed README and schemas.

### Task 1: Define and Validate the WordPress-Ready Archive Contract

**Files:**
- Create: `app/Services/GeoFlow/LitbAssets/LitbArticleArchiveWriter.php`
- Create: `resources/content/litb-quarterly-2026-q3/archive-schema.json`
- Create: `content/litb-quarterly-2026-q3/README.md`
- Modify: `.gitignore`
- Test: `tests/Unit/LitbArticleArchiveWriterTest.php`

**Interfaces:**
- Produces: `LitbArticleArchiveWriter::write(ContentGenerationRun $run): LitbArchiveResult`.
- Produces: `LitbArchiveResult` paths for article Markdown, evidence JSON, and quality JSON.

- [ ] **Step 1: Write a failing archive contract test**

```php
$result = $writer->write($this->completedRun());
$markdown = file_get_contents($result->articlePath);
$this->assertStringContainsString('title:', $markdown);
$this->assertStringContainsString('slug:', $markdown);
$this->assertStringContainsString('seo_title:', $markdown);
$this->assertStringContainsString('generation_run_id:', $markdown);
$this->assertJson(file_get_contents($result->evidencePath));
$this->assertJson(file_get_contents($result->qualityPath));
```

- [ ] **Step 2: Run the test and verify failure**

Run: `php artisan test tests/Unit/LitbArticleArchiveWriterTest.php`
Expected: FAIL because the writer does not exist.

- [ ] **Step 3: Implement atomic, path-safe archive writes**

```php
$slug = Str::slug((string) $run->contentBrief->asset_key);
$target = $this->root.'/articles/en/'.$slug.'.md';
$temporary = $target.'.'.Str::uuid().'.tmp';
File::ensureDirectoryExists(dirname($target));
File::put($temporary, $this->renderFrontMatter($run)."\n---\n\n".$run->final_content);
File::move($temporary, $target);
```

Reject empty asset keys, traversal characters, non-terminal runs, missing final content, and evidence/quality serialization failures before replacing an existing archive.

- [ ] **Step 4: Run archive tests**

Run: `php artisan test tests/Unit/LitbArticleArchiveWriterTest.php`
Expected: PASS, including safe rerun replacement and failure preserving the previous valid file.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/LitbAssets/LitbArticleArchiveWriter.php resources/content/litb-quarterly-2026-q3/archive-schema.json content/litb-quarterly-2026-q3/README.md .gitignore tests/Unit/LitbArticleArchiveWriterTest.php
git commit -m "feat: archive LITB articles for WordPress"
```

### Task 2: Select Evidence-Ready Briefs and Prevent Duplicate Generation

**Files:**
- Create: `app/Services/GeoFlow/LitbAssets/LitbQuarterlyGenerationCoordinator.php`
- Test: `tests/Feature/LitbQuarterlyGenerationCoordinatorTest.php`

**Interfaces:**
- Produces: `preview(array $filters): LitbGenerationBatchPreview`.
- Produces: `dispatch(array $filters, int $adminId): LitbGenerationBatchResult`.
- Consumes: `ContentGenerationRequestService::reserve(ContentBrief $brief, array $data, Admin $actor): array`; the request service invokes `ContentGenerationDispatcher::dispatch(ContentGenerationRun $run): bool`.

- [ ] **Step 1: Write failing eligibility and idempotency tests**

```php
$preview = $coordinator->preview(['quarter_key' => 'litb-quarterly-2026-q3', 'limit' => 10]);
$this->assertCount(9, $preview->eligible);
$this->assertSame('needs_evidence', $preview->skipped[0]['reason']);
$first = $coordinator->dispatch($filters, $admin->id);
$second = $coordinator->dispatch($filters, $admin->id);
$this->assertSame($first->runIds, $second->existingRunIds);
```

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test tests/Feature/LitbQuarterlyGenerationCoordinatorTest.php`
Expected: FAIL because the coordinator does not exist.

- [ ] **Step 3: Implement deterministic eligibility and request keys**

```php
$requestKey = hash('sha256', implode('|', [
    $brief->asset_key,
    $strategy->id,
    $blueprint->id,
    $presetVersion->id,
    $model->id,
]));
```

Eligibility requires a confirmed strategy, confirmed blueprint, published matching preset, active model, reviewed evidence for every required group, and no terminal adopted run for the same request key.

- [ ] **Step 4: Run coordinator and generation regressions**

Run: `php artisan test tests/Feature/LitbQuarterlyGenerationCoordinatorTest.php tests/Feature/ContentGenerationCoordinatorTest.php tests/Feature/AdminContentBriefWorkflowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/LitbAssets/LitbQuarterlyGenerationCoordinator.php tests/Feature/LitbQuarterlyGenerationCoordinatorTest.php
git commit -m "feat: coordinate LITB quarterly generation"
```

### Task 3: Add Resumable Batch Generation Command

**Files:**
- Create: `app/Console/Commands/GenerateLitbQuarterlyContentCommand.php`
- No registration edit is required: Laravel auto-discovers command classes under `app/Console/Commands`.
- Test: `tests/Feature/GenerateLitbQuarterlyContentCommandTest.php`

**Interfaces:**
- Produces command `geoflow:litb-content:generate {--dry-run} {--limit=10} {--pillar=} {--brief=} {--model=} {--resume}`.
- Consumes: `LitbQuarterlyGenerationCoordinator`.

- [ ] **Step 1: Write failing dry-run, filter, and resume tests**

```php
$this->artisan('geoflow:litb-content:generate', ['--dry-run' => true, '--pillar' => 'faq', '--limit' => 3])
    ->expectsOutputToContain('Eligible: 3')
    ->assertSuccessful();
$this->assertDatabaseCount('content_generation_runs', 0);
```

- [ ] **Step 2: Run the command test and verify failure**

Run: `php artisan test tests/Feature/GenerateLitbQuarterlyContentCommandTest.php`
Expected: FAIL because the command is unavailable.

- [ ] **Step 3: Implement bounded batches and structured summary**

```php
protected $signature = 'geoflow:litb-content:generate {--dry-run} {--limit=10} {--pillar=} {--brief=} {--model=} {--resume}';
```

Reject limits outside `1..25`; print eligible, needs-evidence, already-running, completed, and failed counts; return failure only for invalid configuration or dispatch failure, not for evidence-skipped briefs.

- [ ] **Step 4: Run command and queue-related tests**

Run: `php artisan test tests/Feature/GenerateLitbQuarterlyContentCommandTest.php tests/Feature/ContentGenerationCoordinatorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/GenerateLitbQuarterlyContentCommand.php tests/Feature/GenerateLitbQuarterlyContentCommandTest.php
git commit -m "feat: generate LITB quarterly content in batches"
```

### Task 4: Archive Every Terminal LITB Generation

**Files:**
- Create: `app/Listeners/ArchiveLitbContentGeneration.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ArchiveLitbContentGenerationTest.php`

**Interfaces:**
- Consumes: `ContentGenerationCompleted` and `ContentGenerationFailed` events.
- Produces: terminal archive files for completed runs and diagnostic quality/evidence manifests for failed LITB runs.

- [ ] **Step 1: Write failing listener scope and retry tests**

```php
event(new ContentGenerationCompleted($litbRun->id));
$this->assertFileExists($this->articlePath($litbRun));
event(new ContentGenerationCompleted($nonLitbRun->id));
$this->assertFileDoesNotExist($this->articlePath($nonLitbRun));
```

- [ ] **Step 2: Run listener tests and verify failure**

Run: `php artisan test tests/Feature/ArchiveLitbContentGenerationTest.php`
Expected: FAIL because no listener writes the archive.

- [ ] **Step 3: Implement queued, idempotent listener behavior**

```php
if (!Str::startsWith((string) $run->contentBrief?->asset_key, 'litb-quarterly-2026-q3.')) {
    return;
}
$this->writer->write($run);
```

Use the run ID as queue uniqueness key; report archive failure in logs without changing a successful model run into a failed generation run.

- [ ] **Step 4: Run listener, event, and archive tests**

Run: `php artisan test tests/Feature/ArchiveLitbContentGenerationTest.php tests/Unit/LitbArticleArchiveWriterTest.php tests/Feature/ContentGenerationCoordinatorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Listeners/ArchiveLitbContentGeneration.php app/Providers/AppServiceProvider.php tests/Feature/ArchiveLitbContentGenerationTest.php
git commit -m "feat: archive LITB generation results"
```

### Task 5: Validate Archive-to-Database Integrity

**Files:**
- Create: `app/Console/Commands/AuditLitbQuarterlyContentCommand.php`
- Test: `tests/Feature/AuditLitbQuarterlyContentCommandTest.php`
- Modify: `docs/litb-content-assets.md`

**Interfaces:**
- Produces command `geoflow:litb-content:audit {--strict}`.
- Verifies brief asset key, run ID, article ID, evidence hashes, module version IDs, content hash, quality status, and archive paths.

- [ ] **Step 1: Write a failing mismatch audit test**

```php
File::put($articlePath, str_replace($run->output_sha256, str_repeat('0', 64), File::get($articlePath)));
$this->artisan('geoflow:litb-content:audit', ['--strict' => true])
    ->expectsOutputToContain('content_hash_mismatch')
    ->assertFailed();
```

- [ ] **Step 2: Run audit tests and verify failure**

Run: `php artisan test tests/Feature/AuditLitbQuarterlyContentCommandTest.php`
Expected: FAIL because the audit command does not exist.

- [ ] **Step 3: Implement deterministic audit rules**

Read YAML front matter without evaluating PHP or object tags. Strict mode fails on missing files, hash mismatches, missing evidence, unknown module versions, or an archive marked passed while its run did not pass quality checks.

- [ ] **Step 4: Run audit and archive tests**

Run: `php artisan test tests/Feature/AuditLitbQuarterlyContentCommandTest.php tests/Feature/ArchiveLitbContentGenerationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/AuditLitbQuarterlyContentCommand.php tests/Feature/AuditLitbQuarterlyContentCommandTest.php docs/litb-content-assets.md
git commit -m "feat: audit LITB content archive integrity"
```

### Task 6: Produce the Quarter in Controlled Batches

**Files:**
- Runtime output: `content/litb-quarterly-2026-q3/articles/en/*.md`
- Runtime output: `content/litb-quarterly-2026-q3/evidence-manifests/*.json`
- Runtime output: `content/litb-quarterly-2026-q3/quality-reports/*.json`
- Runtime output: `content/litb-quarterly-2026-q3/calendar/production-status.json`

**Interfaces:**
- Consumes: batch generation and audit commands.
- Produces: evidence-backed local archives and corresponding GEOFlow generation/Article drafts.

- [ ] **Step 1: Preview all eligible work**

Run: `docker compose exec -T app php artisan geoflow:litb-content:generate --dry-run --limit=25`
Expected: summary separates eligible, needs-evidence, completed, and invalid briefs.

- [ ] **Step 2: Generate the first five low-risk FAQ/company briefs**

Run: `docker compose exec -T app php artisan geoflow:litb-content:generate --pillar=faq --limit=5`
Expected: five queued or existing run IDs, with no duplicate IDs on immediate `--resume`.

- [ ] **Step 3: Inspect first-batch quality before scaling**

Run: `docker compose exec -T app php artisan geoflow:litb-content:audit --strict`
Expected: PASS for completed archives; incomplete queued runs are reported separately rather than as corrupt.

- [ ] **Step 4: Continue in batches of at most 25**

Run repeatedly: `docker compose exec -T app php artisan geoflow:litb-content:generate --resume --limit=25`
Expected: each run schedules only eligible uncompleted briefs. Stop scheduling only if the model provider is unavailable or quality failure rate exceeds 20% in the latest batch; diagnose and correct configuration or Prompt quality before resuming.

- [ ] **Step 5: Drain queues and audit final state**

Run: `docker compose ps && docker compose logs --tail=300 queue knowledge-queue && docker compose exec -T app php artisan geoflow:litb-content:audit --strict`
Expected: every completed run has matching archive files; needs-evidence briefs remain explicit and no failed item is reported as publishable.

- [ ] **Step 6: Verify test suite and summarize production totals**

Run: `docker compose exec -T app php artisan test tests/Unit/LitbArticleArchiveWriterTest.php tests/Feature/LitbQuarterlyGenerationCoordinatorTest.php tests/Feature/GenerateLitbQuarterlyContentCommandTest.php tests/Feature/ArchiveLitbContentGenerationTest.php tests/Feature/AuditLitbQuarterlyContentCommandTest.php`
Expected: PASS. Report total briefs, eligible, generated, passed, needs revision, needs evidence, failed, adopted Article drafts, and local archives.
