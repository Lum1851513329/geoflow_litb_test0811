# Handoff: Task/Content-Strategy Pipeline Merge

Written 2026-08-18 by Claude, handing off to Codex. This document is self-contained — read this first, before touching code.

## 1. What this branch does

GEOFlow (Laravel 11 SEO/GEO content platform) had two parallel content-generation pipelines:

- **Legacy `Task` pipeline**: autonomous scheduler, draft/publish pacing (`publish_interval`, `article_limit`, `loop_count`), mature admin UI — but single-shot AI generation with no quality gate.
- **`ContentBrief` pipeline** (`ContentBrief` → `ContentStrategyVersion` → `ContentBlueprintVersion` → `ContentGenerationRun`): deterministic quality gate (`DeterministicQualityGate` + `SemanticQualityReviewer`), mandatory prompt-module governance (`MandatoryPromptModuleGuard`), human-gated adoption — but only manually/batch triggered, no autonomous scheduling.

This branch merges them: a `Task` can now be set to `generation_engine = 'quality_gated'`, in which case it flows through the ContentBrief pipeline automatically while keeping the Task's scheduling/pacing/admin UI. A new spreadsheet-driven bulk import creates many such Tasks at once, routed through the same `Task`-creation path as manual creation (not a bypass).

Full design rationale: `docs/superpowers/specs/2026-08-14-task-content-strategy-merge-design.md`
Full implementation plan (17 tasks): `docs/superpowers/plans/2026-08-14-task-content-strategy-merge.md`

Both were written by a prior Claude session using a structured brainstorm → plan → subagent-driven-implementation → whole-branch-review process. The `.superpowers/sdd/` working directory (ledger, per-task briefs/reports, review packages) is **gitignored and has been deleted** now that the work is complete — the full rationale trail lives in the plan doc and in commit messages, not in any surviving file.

## 2. Current state — DONE, PR open, needs manual smoke test

- **Branch**: `feature/keyword-intelligence`, HEAD at commit `4bdc7cc`
- **Worktree**: `/Users/litb/Documents/litb_geo/GEOFlow/.worktrees/keyword-intelligence` (this file's location)
- **PR**: https://github.com/Lum1851513329/geoflow_litb_test0811/pull/1 (`feature/keyword-intelligence` → `main`, both on the `litb-github` remote)
- **Automated tests**: green against established baseline — `7 failed, 2 skipped, 1905 passed`. The 7 failures are **pre-existing and unrelated** to this branch (confirmed identical before/after every change in this plan): `AdminPtBrLocaleCoverageTest`, `OpenSourceReleaseScriptsTest`, `AdminSiteThemeReplicationTest`, `AdminSystemUpdatesPageTest`, `ContentPipelineCompatibilityTest`, `ImageLibrarySecurityTest`, `PromptCompositionSeederTest`. Do not try to fix these as part of this branch's scope.
- **Not yet done**: manual smoke test in a real admin UI session. This is the one item to pick up.

### Remotes (this repo has two, don't confuse them)

- `origin` = `https://github.com/yaojingang/GEOFlow.git` — the real upstream project, has its own `main`.
- `litb-github` = `git@github.com:Lum1851513329/geoflow_litb_test0811.git` — the user's personal working repo. It had **no `main` branch** until this session created one by pushing `origin/main` into it (`git push litb-github refs/remotes/origin/main:refs/heads/main`). The PR above targets this `main`, not `origin`'s.

## 3. REQUIRED before any manual testing — this dev DB is not migrated/seeded yet

Checked directly against the running dev DB (Docker container `geoflow-app`, mounted from this worktree) at handoff time:

```
$ php artisan migrate:status | grep Pending
2026_08_15_000400_seed_geoflow_system_admin ........................ Pending
2026_08_15_000500_add_core_question_and_reference_urls_to_titles ... Pending
```

The permanent `geoflow-system` admin account (required by the quality-gated generation path — `WorkerExecutionService` throws `任务未配置质量门禁内容模板`-adjacent errors without it) does **not exist yet** in this DB. And:

```
$ php artisan tinker --execute="... PromptPreset with blueprint_template ..."
0 / 15
```

Zero of the 15 presets have `blueprint_template` populated — the backfill command hasn't been run against this persistent dev DB. **Run order matters** (this is C4 from the final review — running these out of order breaks the *existing* pre-this-plan pipeline, not just the new one):

```bash
cd /Users/litb/Documents/litb_geo/GEOFlow/.worktrees/keyword-intelligence
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan geoflow:content-strategy:seed-title-crafting-module
docker compose exec -T app php artisan geoflow:content-strategy:seed-preset-blueprint-templates
```

The last command now has error resilience (per C4's fix): it reports a per-preset summary and returns non-zero if any preset failed, without aborting the rest.

Feature flags are **already on** in `.env`:
```
GEOFLOW_CONTENT_STRATEGY_ENABLED=true
GEOFLOW_MODULAR_PROMPTS_ENABLED=true
GEOFLOW_GENERATION_QUALITY_GATE_ENABLED=true
```

## 4. Environment / how to run it

- Docker Compose, already running (`geoflow-app`, `geoflow-postgres`, `geoflow-redis`), started from this worktree's own `docker-compose.yml`.
- Admin panel: **http://127.0.0.1:18082/geo_admin/login**
  - Login: `admin` / `admin@example.com`, password from `.env`'s `GEOFLOW_ADMIN_PASSWORD` (currently `password` in this dev env).
- Run any artisan/test command via `docker compose exec -T app php artisan ...` from inside the worktree directory.
- Test suite: `docker compose exec -T app php artisan test`
- `docker-compose.yml` has a pre-existing, uncommitted local modification (`git status` shows it modified) — **do not commit it**, it predates this session and isn't part of this branch's scope.

## 5. Suggested manual smoke test path

1. Complete the migrate + seed steps in §3 first.
2. Log into the admin panel.
3. Go to `/geo_admin/prompt-presets`, confirm at least one preset shows a populated blueprint template after the backfill.
4. Go to `/geo_admin/tasks/create`, create a Task with `generation_engine = quality_gated`, pick a preset, a knowledge base, `is_loop` on, small `article_limit`/`draft_limit` (e.g. 2) for fast iteration.
5. Either wait for the scheduler or manually invoke the pieces:
   - Generation dispatch: however the existing `WorkerExecutionService`/queue worker is normally triggered in this dev setup (check `routes/console.php` schedule entries, or run the relevant artisan command directly).
   - Adoption: `docker compose exec -T app php artisan geoflow:content-strategy:adopt-task-driven-runs` (also scheduled `everyMinute()` in `routes/console.php`).
6. Confirm: a `ContentGenerationRun` is created and reaches `ready_for_review`, then gets adopted into an `Article` with the AI-extracted title (H1 stripped), correct evidence/references section, and (if `need_review=0`) auto-approved via the risk gate.
7. Try the bulk import flow: `/geo_admin/bulk-content-import`, download the template, fill a row or two, upload, confirm it creates grouped Tasks correctly.

## 6. Known deferred issues (not blocking, not yet fixed — fair game to pick up)

From the final whole-branch review + its fix wave + a scoped re-review of that fix wave (all completed and merged). These are genuine but non-blocking findings, explicitly deferred rather than fixed:

- **C1**: A Task with knowledge bases bound but zero *reviewed* chunks silently generates with empty evidence — no log line. Worth a `Log::warning` in `WorkerExecutionService::resolveReviewedKnowledgeChunkIds()`.
- **C1**: Evidence volume can be up to 30 chunks/run (6 per knowledge base × up to 5 knowledge bases) — no aggregate cap, and the docblock overstates equivalence to `LitbQuarterlyGenerationCoordinator::reviewedEvidenceFor()`'s single-source cap.
- **C2/C3**: `AdoptTaskDrivenGenerationRunsCommand::recordTaskGeneration()` / `approveWhenReviewNotRequired()` run outside `adopt()`'s DB transaction and take no row lock on `Task` (unlike every other `next_publish_at` writer, which uses `lockForUpdate()`). A concurrent worker tick and adopt-command run could lose one of two updates.
- **C2/C3**: Manual adoption via `POST /content-generations/{run}/adopt` (the pre-existing human flow) skips both the counter-increment and the `need_review` reapplication — `created_count` undercounts and a `need_review=0` Task's manually-adopted article stays `pending` forever. Two adoption paths now have different Task-side semantics; worth reconciling into a shared observer/service.
- **C4**: `SeedPresetBlueprintTemplatesCommand::backfillPreset()`'s `createVersion()` + `publish()` aren't in one transaction — a failed `publish()` leaks an orphaned draft `PromptPresetVersion` row on each retry (cosmetic; preset selection filters on `lifecycle_status=production`).
- **I2**: `AdoptTaskDrivenGenerationRunsCommand`'s query doesn't eager-load `presetVersion`, costing one avoidable query per run in the batch (up to `--limit`, default 100/500).
- **I5**: A present-but-null `generation_engine` in a Task update payload now hard-rejects (`生成引擎无效`) instead of silently defaulting to `legacy`. Unreachable from the UI (the `<select>` has no empty option), only matters to a hypothetical direct API caller. Arguably the better behavior; noted so it's a conscious choice.
- A separate, larger, explicitly out-of-scope problem: `PromptCompositionSeeder` (6 generic modules, bound only to the 4 `legacy_geo_*` presets) and `LitbPromptLibrarySeeder` (11 `litb.*` modules, bound only to LITB presets) have **disjoint mandatory-module sets** — neither seeder's output alone satisfies `MandatoryPromptModuleGuard::assertComplete()` for the other seeder's presets. This dev DB currently passes only because of a manual out-of-band DB patch made before this plan started. Reconciling these two seeders is a real, separate undertaking — do not attempt to fold it into small fixes.

## 7. Key files (if you need to navigate quickly)

- `app/Services/GeoFlow/WorkerExecutionService.php` — `generateQualityGatedDraft()` (the new branch point), `getGenerationBlockReason()` (draft-pool + parked-run ceiling checks).
- `app/Console/Commands/AdoptTaskDrivenGenerationRunsCommand.php` — auto-adopts `ready_for_review` runs, scheduled every minute in `routes/console.php`.
- `app/Domain/ContentStrategy/ContentBriefQuickCreateService.php` — simplified Brief/Strategy/Blueprint creation, used by both the quick-create admin form and the Task-driven path.
- `app/Console/Commands/SeedTitleCraftingPromptModuleCommand.php` + `SeedPresetBlueprintTemplatesCommand.php` — the two seed commands from §3 (run order matters).
- `app/Domain/ContentGeneration/BulkContentImportParser.php`, `BulkContentImportService.php`, `BulkContentImportTemplateBuilder.php` — the spreadsheet bulk-import feature.
- `app/Domain/ContentGeneration/DeterministicQualityGate.php`, `GeneratedArticleParser.php` — quality gate + AI title extraction.

## 8. If Codex wants to fix any of §6's deferred items

Each one is small and independently fixable — no need to reopen the whole plan. Normal PR flow: branch off `feature/keyword-intelligence`, fix, test, PR back into it. Nothing in §6 is load-bearing/blocking; they're improvements, not defects that break the merged feature.
