# Keyword Prompt and Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn a confirmed `KeywordCluster` into reproducible multilingual title and article generation through immutable Prompt modules, validated recipes, generation manifests, and backward-compatible Worker execution.

**Architecture:** Add an immutable module/version and ordered recipe layer beside the existing `prompts` table. A typed `ContentGenerationContext` snapshots cluster, title, locale, knowledge, and task inputs; a compiler validates and orders published module versions, a renderer performs non-executable allow-listed interpolation, and a validator enforces input/output contracts. Every title/body/SEO generation writes a manifest, while tasks without `prompt_recipe_id` continue through the existing `ArticleContentPromptRenderer` and `prompt_id` path.

**Tech Stack:** PHP 8.3+, Laravel 12, Eloquent, Blade, Laravel queues, Laravel AI providers, PHPUnit 11, JSON Schema-like application validation without runtime code evaluation.

## Global Constraints

- Preserve existing `prompts`, `tasks.prompt_id`, title-library, Worker, API, task monitoring, article workflow, review, risk-scan, and publishing behavior.
- `KeywordCluster` is the Phase 1 contract: use `primaryKeyword`, ordered `memberships`, `taxonomyNode`, `language_code`, `market_code`/`market_scope`, `search_intent`, `content_type`, `knowledge_base_id`, `category_id`, and `target_url`.
- An active cluster must have exactly one `primary` membership matching `primary_keyword_id`; generation rejects draft/archived clusters and never repairs them silently.
- Published Prompt module versions are immutable; edits create a new version and existing manifests keep the original version IDs.
- Every active recipe contains all enabled mandatory GEO modules. Ordinary task configuration cannot disable, reorder ahead of, or override their protected parameters.
- Prompt templates use only allow-listed variables and simple conditional blocks; rendering never executes PHP, Blade, shell, JavaScript, or arbitrary object access.
- Required context or output validation failure ends the generation attempt and is recorded in `TaskRun`/manifest; it must not silently become empty text.
- Multilingual generation uses explicit BCP-47-compatible language and optional ISO market context. Do not infer English when a task/cluster declares another language.
- `auto_keywords=0` and `auto_description=0` retain current operator-controlled behavior. When enabled, generation is cluster-aware and auditable.
- Use reversible migrations and nullable foreign keys for compatibility. Do not migrate existing prompts or tasks automatically.
- Follow TDD, run focused legacy tests after each task, and commit each task independently.

---

## File Structure

Prompt identity and immutable versions live in `app/Models`; compilation and rendering live under `app/Services/GeoFlow/Prompts`; generation context, manifests, and SEO output live under `app/Services/GeoFlow/Generation`. The Worker remains the lifecycle/transaction coordinator and delegates context, prompt, metadata, and manifest behavior to these focused services. Admin controllers only validate requests and call services.

### Task 1: Add Prompt Recipe, Generation, and Association Schema

**Files:**
- Create: `database/migrations/2026_08_04_000005_add_prompt_recipe_generation_foundation.php`
- Create: `app/Models/PromptModule.php`
- Create: `app/Models/PromptModuleVersion.php`
- Create: `app/Models/PromptRecipe.php`
- Create: `app/Models/PromptRecipeModule.php`
- Create: `app/Models/GenerationManifest.php`
- Modify: `app/Models/Task.php`
- Modify: `app/Models/Title.php`
- Modify: `app/Models/Article.php`
- Test: `tests/Feature/PromptRecipeSchemaTest.php`

**Interfaces:**
- Produces immutable version relations: `PromptModule::versions()`, `PromptRecipe::moduleEntries()`, `PromptRecipeModule::version()`.
- Produces `Task::promptRecipe()`, `Task::keywordCluster()`, `Title::keywordCluster()`, `Title::primaryKeyword()`, `Title::generationManifest()`, and `Article::generationManifest()`.
- Produces `GenerationManifest` snapshots for targets `title`, `article`, and `seo_metadata`.

- [ ] **Step 1: Write the failing schema test**

Create a legacy task using only `prompt_id`, then create a module/version/recipe and assert nullable compatibility plus foreign-key relations. Assert a recipe entry references a specific version, not only a mutable module.

```php
$entry = $recipe->moduleEntries()->create([
    'module_version_id' => $version->id,
    'sort_order' => 100,
    'is_required' => true,
    'condition_json' => null,
    'parameter_overrides_json' => [],
]);
$this->assertSame($version->id, $entry->version->id);
$this->assertNull($legacyTask->fresh()->prompt_recipe_id);
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Feature/PromptRecipeSchemaTest.php`

Expected: FAIL because the tables/models/columns do not exist.

- [ ] **Step 3: Implement the reversible migration**

Create:

- `prompt_modules`: stable `key`, `name`, group (`mandatory`, `content_type`, `category`, `locale`, `seo`, `structure`, `output`), description, status, and optional `current_version_id` added after the version table exists.
- `prompt_module_versions`: module ID, monotonically increasing `version`, immutable content, `variables_json`, `output_contract_json`, `parameters_json`, content SHA-256, status (`draft`, `published`, `retired`), author and published timestamp; unique `(module_id, version)` and `(module_id, content_sha256)`.
- `prompt_recipes`: stable key/name, purpose, optional language/content type, status, revision, creator/publisher timestamps.
- `prompt_recipe_modules`: recipe ID, exact version ID, sort order, required flag, condition/override JSON; unique recipe/version and recipe/sort order.
- `generation_manifests`: UUID idempotency key, target type/ID, task/run/recipe/cluster/title/article/model IDs, locale/market, context/compiled plan/module version IDs/model request/output/validation JSON, prompt/output hashes, compatibility path, status/error and timestamps.

Add nullable `prompt_recipe_id`, `keyword_cluster_id`, `language_code`, `market_code` to tasks; add nullable `keyword_cluster_id`, `primary_keyword_id`, locale/market/intent and `generation_manifest_id` to titles; add nullable `keyword_cluster_id` and `generation_manifest_id` to articles. Use `nullOnDelete` for optional configuration/history pointers and preserve manifest snapshots when a target is deleted.

- [ ] **Step 4: Implement models, casts, relations, and immutability guard**

Published `PromptModuleVersion` must reject updates/deletes in model events with stable domain errors `prompt_module.published_immutable` and `prompt_module.published_delete_forbidden`. Allow status transition from draft to published only through the publication service introduced in Task 2.

- [ ] **Step 5: Run new and compatibility tests**

Run:

```bash
php artisan test tests/Feature/PromptRecipeSchemaTest.php
php artisan test tests/Feature/AdminTasksPageTest.php tests/Unit/WorkerExecutionServicePromptTest.php tests/Feature/WorkerExecutionSourceTitleTest.php
```

Expected: PASS; old task creation and old source-title association remain unchanged.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_04_000005_add_prompt_recipe_generation_foundation.php app/Models/PromptModule.php app/Models/PromptModuleVersion.php app/Models/PromptRecipe.php app/Models/PromptRecipeModule.php app/Models/GenerationManifest.php app/Models/Task.php app/Models/Title.php app/Models/Article.php tests/Feature/PromptRecipeSchemaTest.php
git commit -m "feat: add prompt recipe generation schema"
```

### Task 2: Add Version Publication and Mandatory GEO Module Policy

**Files:**
- Create: `app/Services/GeoFlow/Prompts/PromptModuleVersionService.php`
- Create: `app/Services/GeoFlow/Prompts/PromptRecipeService.php`
- Create: `config/prompt_recipes.php`
- Create: `database/seeders/PromptRecipeFoundationSeeder.php`
- Test: `tests/Feature/PromptModuleVersionServiceTest.php`
- Test: `tests/Feature/PromptRecipeServiceTest.php`

**Interfaces:**
- Produces `PromptModuleVersionService::createDraft(PromptModule $module, array $data, int $adminId): PromptModuleVersion`.
- Produces `PromptModuleVersionService::publish(PromptModuleVersion $version, int $adminId): PromptModuleVersion`.
- Produces `PromptRecipeService::saveDraft(PromptRecipe $recipe, array $entries): PromptRecipe` and `publish(PromptRecipe $recipe, int $adminId): PromptRecipe`.

- [ ] **Step 1: Write failing publication and policy tests**

Assert publishing freezes content/hash/contracts; editing creates version N+1; recipes cannot include draft versions, duplicate ordering, unknown groups, or protected override keys. Assert publication fails unless these configured module keys are enabled exactly once:

```php
'mandatory_module_keys' => [
    'fact-boundaries',
    'geo-citability',
    'brand-governance',
],
```

The seeded `fact-boundaries` requires evidence-aware claims and uncertainty language, `geo-citability` requires answer-first summaries, attributable facts, headings and concise FAQ-ready passages, and `brand-governance` requires approved brand naming and no unsupported comparisons. They must remain general infrastructure, not LITB-specific copy.

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/PromptModuleVersionServiceTest.php tests/Feature/PromptRecipeServiceTest.php`

- [ ] **Step 3: Implement canonical hashing and publication transactions**

Canonicalize associative JSON recursively before hashing. Under a transaction and row locks, validate variable/output contracts, set `published_at`, update `current_version_id`, and prevent in-place mutation. Recipe publication snapshots exact version IDs and increments recipe revision.

- [ ] **Step 4: Add idempotent foundation seeding**

The seeder creates the three mandatory module identities and first published versions only when the stable key/version is absent. It must not overwrite operator-created content or auto-attach recipes.

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Feature/PromptModuleVersionServiceTest.php tests/Feature/PromptRecipeServiceTest.php
git add app/Services/GeoFlow/Prompts config/prompt_recipes.php database/seeders/PromptRecipeFoundationSeeder.php tests/Feature/PromptModuleVersionServiceTest.php tests/Feature/PromptRecipeServiceTest.php
git commit -m "feat: version prompt modules and recipes"
```

### Task 3: Build Typed Multilingual Generation Context

**Files:**
- Create: `app/Services/GeoFlow/Generation/ContentGenerationContext.php`
- Create: `app/Services/GeoFlow/Generation/ContentGenerationContextFactory.php`
- Test: `tests/Unit/ContentGenerationContextTest.php`
- Test: `tests/Feature/ContentGenerationContextFactoryTest.php`

**Interfaces:**
- Produces immutable `ContentGenerationContext` fields: `title`, `primaryKeyword`, `secondaryKeywords`, `questions`, `searchIntent`, `contentType`, `language`, `market`, `categoryPath`, `knowledge`, `sourceEvidence`, `brandContext`, `targetUrl`, `clusterId`, `titleId`, and `taskId`.
- Produces `ContentGenerationContext::toArray(): array<string,mixed>` with stable snake_case keys matching module contracts.
- Produces `ContentGenerationContextFactory::forTask(Task $task, Title $title, string $knowledge): ContentGenerationContext`.

- [ ] **Step 1: Write failing context tests**

Build an active confirmed cluster with ordered roles. Assert primary comes from `primary_keyword_id`, secondary/question lists follow membership `sort_order`, locale/market and taxonomy path come from the cluster, and the task may narrow neither language nor market to a conflicting value.

```php
$context = $factory->forTask($task, $title, '[K1] verified material');
$this->assertSame($cluster->primaryKeyword->keyword, $context->primaryKeyword);
$this->assertSame(['which dress fits a summer wedding'], $context->questions);
```

Also assert rejection for inactive clusters, title/task cluster mismatch, missing primary role, conflicting locale, and a title whose `primary_keyword_id` does not match the cluster.

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Unit/ContentGenerationContextTest.php tests/Feature/ContentGenerationContextFactoryTest.php`

- [ ] **Step 3: Implement the immutable value object and factory**

The factory loads all relationships in bounded queries, separates evidence IDs from display knowledge, and emits `language` exactly from task override or cluster language when compatible. A null market means global; it does not invent `US`. `brand_context` is application-owned configuration/context, never fabricated purchase experience.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test tests/Unit/ContentGenerationContextTest.php tests/Feature/ContentGenerationContextFactoryTest.php
git add app/Services/GeoFlow/Generation/ContentGenerationContext.php app/Services/GeoFlow/Generation/ContentGenerationContextFactory.php tests/Unit/ContentGenerationContextTest.php tests/Feature/ContentGenerationContextFactoryTest.php
git commit -m "feat: build cluster generation context"
```

### Task 4: Compile, Render, and Validate Prompt Recipes

**Files:**
- Create: `app/Services/GeoFlow/Prompts/CompiledPromptRecipe.php`
- Create: `app/Services/GeoFlow/Prompts/PromptRecipeCompiler.php`
- Create: `app/Services/GeoFlow/Prompts/PromptRecipeRenderer.php`
- Create: `app/Services/GeoFlow/Prompts/PromptRecipeValidator.php`
- Test: `tests/Unit/PromptRecipeCompilerTest.php`
- Test: `tests/Unit/PromptRecipeRendererTest.php`
- Test: `tests/Unit/PromptRecipeValidatorTest.php`

**Interfaces:**
- Produces `PromptRecipeCompiler::compile(PromptRecipe $recipe, ContentGenerationContext $context): CompiledPromptRecipe`.
- Produces `PromptRecipeRenderer::render(CompiledPromptRecipe $plan, ContentGenerationContext $context): string`.
- Produces static `PromptRecipeValidator::validateRecipe(...)` and runtime `validateContext(...)` / `validateOutput(...)` results with stable error codes.

- [ ] **Step 1: Write failing deterministic-order tests**

Assert the compiler produces exactly this group order regardless of insertion order:

```text
mandatory → locale → content_type → category → structure → seo → output
```

Within a group, use `sort_order`, module key, then version ID as deterministic tie-breakers. Conditions may compare only allow-listed scalar context keys with `equals`, `in`, `present`, and `not_present`. Mandatory modules ignore recipe conditions and are always included.

- [ ] **Step 2: Write failing renderer/security tests**

Support escaped scalar interpolation, list formatting, and `{{#if variable}}...{{/if}}`. Reject unknown variables, nested object traversal, unclosed blocks, Blade/PHP directives, and callable/template expressions. Values are text, never recursively interpreted as template source.

- [ ] **Step 3: Write failing validation tests**

Validate required/optional variable type declarations, collisions between incompatible declarations, unknown parameter overrides, absent required runtime values, output shape, language, title/body emptiness, unresolved placeholders, and maximum compiled prompt bytes.

- [ ] **Step 4: Implement minimal pure services**

Compilation and validation perform no model call and no persistence after the recipe/version data has been loaded. `CompiledPromptRecipe` contains recipe ID/revision, ordered module/version IDs and hashes, merged contracts/parameters, warnings, and a compiler version.

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Unit/PromptRecipeCompilerTest.php tests/Unit/PromptRecipeRendererTest.php tests/Unit/PromptRecipeValidatorTest.php
git add app/Services/GeoFlow/Prompts tests/Unit/PromptRecipeCompilerTest.php tests/Unit/PromptRecipeRendererTest.php tests/Unit/PromptRecipeValidatorTest.php
git commit -m "feat: compile and validate prompt recipes"
```

### Task 5: Persist Generation Manifests Safely

**Files:**
- Create: `app/Services/GeoFlow/Generation/GenerationManifestService.php`
- Test: `tests/Feature/GenerationManifestServiceTest.php`

**Interfaces:**
- Produces `GenerationManifestService::start(array $identity, ContentGenerationContext $context, CompiledPromptRecipe $plan, string $renderedPrompt): GenerationManifest`.
- Produces `complete(GenerationManifest $manifest, array $model, array $output, array $validation): GenerationManifest` and `fail(...): GenerationManifest`.

- [ ] **Step 1: Write failing lifecycle/idempotency tests**

Assert a stable UUID idempotency key creates only one running manifest; completion stores prompt/output SHA-256, model identity, exact module version IDs and validation without secrets. Failure stores a bounded error code/message and leaves context/module snapshots intact.

- [ ] **Step 2: Run test and verify RED**

Run: `php artisan test tests/Feature/GenerationManifestServiceTest.php`

- [ ] **Step 3: Implement transactional state transitions**

Allowed transitions are `pending → running → completed|failed`. Redact API keys, authorization headers, preview tokens, private storage paths, and raw provider credentials recursively before persistence. Store content hashes and bounded provider metadata; store final generated fields required for reproducibility, not transport secrets.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test tests/Feature/GenerationManifestServiceTest.php
git add app/Services/GeoFlow/Generation/GenerationManifestService.php tests/Feature/GenerationManifestServiceTest.php
git commit -m "feat: audit generation manifests"
```

### Task 6: Associate Cluster-Based Titles Deterministically

**Files:**
- Modify: `app/Services/GeoFlow/TitleAiGenerationService.php`
- Modify: `app/Http/Controllers/Admin/TitleLibraryController.php`
- Create: `app/Http/Requests/Admin/GenerateClusterTitlesRequest.php`
- Modify: `resources/views/admin/title-libraries/ai-generate.blade.php`
- Test: `tests/Feature/TitleAiGenerationServiceTest.php`
- Test: `tests/Feature/AdminTitleClusterGenerationTest.php`

**Interfaces:**
- Produces `TitleAiGenerationService::generateForCluster(AiModel $model, KeywordCluster $cluster, int $count, string $style, ?PromptRecipe $recipe): array` where every item contains `title`, `primary_keyword_id`, `keyword_cluster_id`, locale/market/intent and manifest identity.

- [ ] **Step 1: Write regression and failing association tests**

Assert the existing `generateTitles()` contract remains operational. For cluster generation, assert every saved title points to the request cluster and exact primary keyword; no `random()`/`array_rand()` selection occurs after the model response. Reject inactive clusters, mismatched recipe locale/content type, and outputs not attributable to the requested generation.

- [ ] **Step 2: Run tests and verify RED for the new path**

Run: `php artisan test tests/Feature/TitleAiGenerationServiceTest.php tests/Feature/AdminTitleClusterGenerationTest.php`

- [ ] **Step 3: Implement structured title generation and persistence**

Compile a title recipe with cluster context, request structured title strings, validate count/language/duplication/length, start/complete one manifest for the request, and persist the manifest/cluster/primary associations in the same transaction. Provider failure saves a failed manifest and no placeholder/clickbait fallback titles.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test tests/Feature/TitleAiGenerationServiceTest.php tests/Feature/AdminTitleClusterGenerationTest.php
git add app/Services/GeoFlow/TitleAiGenerationService.php app/Http/Controllers/Admin/TitleLibraryController.php app/Http/Requests/Admin/GenerateClusterTitlesRequest.php resources/views/admin/title-libraries/ai-generate.blade.php tests/Feature/TitleAiGenerationServiceTest.php tests/Feature/AdminTitleClusterGenerationTest.php
git commit -m "feat: generate titles from keyword clusters"
```

### Task 7: Integrate Recipes and Context into Worker Execution

**Files:**
- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Modify: `app/Services/GeoFlow/TaskLifecycleService.php`
- Modify: `app/Services/GeoFlow/TaskMonitoringQueryService.php`
- Modify: `app/Http/Controllers/Admin/TaskController.php`
- Modify: task create/edit Blade views and locale files used by `TaskController`
- Test: `tests/Feature/WorkerPromptRecipeExecutionTest.php`
- Test: `tests/Feature/AdminTasksPageTest.php`
- Test: `tests/Feature/TaskTransactionSafetyTest.php`
- Test: `tests/Unit/WorkerExecutionServicePromptTest.php`

**Interfaces:**
- Selection priority: non-null `prompt_recipe_id` uses the new compiler; otherwise non-null `prompt_id` uses `ArticleContentPromptRenderer`; otherwise preserve the current fallback and record `compatibility_path=fallback`.
- Worker result metadata adds `keyword_cluster_id`, `generation_manifest_id`, `prompt_recipe_id/revision`, and `compatibility_path` without removing existing keys.

- [ ] **Step 1: Write recipe-path and compatibility tests**

Assert a recipe task builds context from its exact source title/cluster, renders ordered modules, records a manifest, uses the selected model, and persists article cluster/manifest links. Assert old prompt tasks produce byte-compatible prompts and articles; tasks with neither configuration retain fallback behavior plus a warning in TaskRun metadata.

- [ ] **Step 2: Write transaction/failure tests**

Missing required context, recipe validation, provider failure, output validation, stale title-cluster mismatch, and manifest failure must not consume the title or increment task/article counters. TaskRun and manifest record stable failure codes outside or after the rolled-back content transaction.

- [ ] **Step 3: Run tests and verify RED**

Run:

```bash
php artisan test tests/Feature/WorkerPromptRecipeExecutionTest.php tests/Feature/TaskTransactionSafetyTest.php
php artisan test tests/Unit/WorkerExecutionServicePromptTest.php tests/Feature/WorkerExecutionSourceTitleTest.php
```

- [ ] **Step 4: Implement delegated Worker flow**

Keep publishing-first behavior, draft limits, author/category/image selection, risk scanning, workflow transitions, and distribution unchanged. Replace only generation input/prompt/metadata assembly. Resolve selected title under the same task rules; require its cluster to match `task.keyword_cluster_id` for the recipe path.

- [ ] **Step 5: Add validated admin task fields and monitoring output**

Allow nullable active cluster, published recipe, explicit compatible language and market. Show the selected recipe revision and cluster; legacy tasks continue to display their old Prompt.

- [ ] **Step 6: Run tests and commit**

```bash
php artisan test tests/Feature/WorkerPromptRecipeExecutionTest.php tests/Feature/AdminTasksPageTest.php tests/Feature/TaskTransactionSafetyTest.php tests/Unit/WorkerExecutionServicePromptTest.php tests/Feature/WorkerExecutionSourceTitleTest.php
git add app/Services/GeoFlow/WorkerExecutionService.php app/Services/GeoFlow/TaskLifecycleService.php app/Services/GeoFlow/TaskMonitoringQueryService.php app/Http/Controllers/Admin/TaskController.php resources/views/admin lang tests/Feature/WorkerPromptRecipeExecutionTest.php tests/Feature/AdminTasksPageTest.php tests/Feature/TaskTransactionSafetyTest.php tests/Unit/WorkerExecutionServicePromptTest.php
git commit -m "feat: execute recipe-based generation tasks"
```

### Task 8: Generate Audited `auto_keywords` and `auto_description`

**Files:**
- Create: `app/Services/GeoFlow/Generation/SeoMetadataResult.php`
- Create: `app/Services/GeoFlow/Generation/SeoMetadataGenerationService.php`
- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Test: `tests/Unit/SeoMetadataGenerationServiceTest.php`
- Test: `tests/Feature/WorkerSeoMetadataTest.php`

**Interfaces:**
- Produces `SeoMetadataGenerationService::generate(ContentGenerationContext $context, string $articleMarkdown, bool $keywords, bool $description, AiModel $model, ?PromptRecipe $recipe): SeoMetadataResult`.
- Result contains ordered unique keywords with role/source, description, fallback flags, validation, and manifest ID.

- [ ] **Step 1: Write failing keyword tests**

When `auto_keywords=1`, output starts with the cluster primary, then ordered secondary/question/variant values, normalized and deduplicated without collapsing explicit singular/plural relationships. Reject terms outside the cluster/model-validated article scope. When disabled, preserve the existing operator/source keyword value.

- [ ] **Step 2: Write failing description tests**

When `auto_description=1`, prefer structured model output in the target language, validate configured grapheme length, relevance, no unresolved evidence IDs, and non-duplication of the title. If the model metadata output fails but the article is valid, use a sentence-boundary summary and record `description_fallback=true`; do not silently use `mb_substr($excerpt, 0, 120)`. When disabled, retain current behavior.

- [ ] **Step 3: Run tests and verify RED**

Run: `php artisan test tests/Unit/SeoMetadataGenerationServiceTest.php tests/Feature/WorkerSeoMetadataTest.php`

- [ ] **Step 4: Implement structured result, validation, and manifest**

Use either the article recipe's SEO/output modules or a separately configured published SEO recipe. Store keyword roles and fallback validation in the SEO manifest; persist the article's current `keywords` representation backward-compatibly while keeping structured provenance in the manifest.

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test tests/Unit/SeoMetadataGenerationServiceTest.php tests/Feature/WorkerSeoMetadataTest.php
git add app/Services/GeoFlow/Generation/SeoMetadataResult.php app/Services/GeoFlow/Generation/SeoMetadataGenerationService.php app/Services/GeoFlow/WorkerExecutionService.php tests/Unit/SeoMetadataGenerationServiceTest.php tests/Feature/WorkerSeoMetadataTest.php
git commit -m "feat: generate cluster-aware seo metadata"
```

### Task 9: Add Prompt Module and Recipe Admin Workflows

**Files:**
- Create: `app/Http/Controllers/Admin/PromptRecipeController.php`
- Create: request classes under `app/Http/Requests/Admin/` for module/version/recipe validation and publication
- Create: Blade views under `resources/views/admin/prompt-recipes/`
- Modify: `routes/web.php`
- Modify: admin sidebar and all supported `lang/*/admin.php` files
- Test: `tests/Feature/AdminPromptRecipeTest.php`

**Interfaces:**
- Admin can create module identities and draft versions, publish versions, assemble/reorder recipes, preview compiled output against a selected active cluster, inspect validation, and publish a recipe.
- Existing AI Prompt screens and routes remain available and unchanged.

- [ ] **Step 1: Write failing authentication and workflow tests**

All GET/POST routes require the existing admin guard and CSRF. Test immutable published versions, mandatory-module lock display, allow-listed condition/override validation, multilingual labels, compiled preview with redacted context, activity logs, and no model call during recipe preview.

- [ ] **Step 2: Run test and verify RED**

Run: `php artisan test tests/Feature/AdminPromptRecipeTest.php`

- [ ] **Step 3: Implement thin controller, requests, views, routes, and translations**

Controllers call Task 2/4 services. Do not duplicate compile/publication logic in Blade or controllers. Preview shows ordered version IDs, required variables, warnings, and rendered text; it does not publish or generate an article.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test tests/Feature/AdminPromptRecipeTest.php tests/Feature/AdminAiPromptsPageTest.php
git add app/Http/Controllers/Admin/PromptRecipeController.php app/Http/Requests/Admin resources/views/admin/prompt-recipes routes/web.php resources/views/admin lang tests/Feature/AdminPromptRecipeTest.php
git commit -m "feat: manage prompt recipes in admin"
```

### Task 10: Complete Verification, Documentation, and Upgrade Safety

**Files:**
- Create: `docs/prompt-recipes.md`
- Modify: `README.md`
- Test: all affected and full suites

**Interfaces:**
- Produces an operator handoff for module/version publication, mandatory GEO governance, recipe preview, task migration choices, manifests, locale behavior, failure recovery, and rollback.

- [ ] **Step 1: Add upgrade and operations documentation**

Document that existing tasks remain on `prompt_id`, how to opt one task into an active cluster and published recipe, why published versions are immutable, where manifests are inspected, how secrets are redacted, and how to roll back application code without deleting manifest history.

- [ ] **Step 2: Run format and syntax verification**

```bash
./vendor/bin/pint --test
find app database/migrations tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

- [ ] **Step 3: Run all new focused suites and legacy generation suites**

Run all tests named in Tasks 1–9 plus `AdminAiPromptsPageTest`, `AdminTasksPageTest`, `WorkerExecutionServiceMaxTokensTest`, `WorkerExecutionServiceAuthorFallbackTest`, `WorkerExecutionSourceTitleTest`, and `TaskTransactionSafetyTest`.

- [ ] **Step 4: Verify migrations and routes on a disposable SQLite file and PostgreSQL-compatible CI environment**

Run `migrate:fresh`, inspect prompt-recipe routes as JSON, then roll back migration `000005` in the same database process/environment. Assert legacy task generation still works after rollback when running the compatible old application revision.

- [ ] **Step 5: Run the complete suite and record baseline deltas**

Run: `php artisan test`

Expected: no new failures compared with the recorded branch baseline. Do not hide environment failures; record exact commands, counts, duration, and failure names.

- [ ] **Step 6: Commit**

```bash
git add docs/prompt-recipes.md README.md
git commit -m "docs: explain prompt recipe generation"
```

---

## Plan Self-Review

- Spec coverage: modules/immutable versions/recipes, mandatory GEO policy, compiler/renderer/validator, multilingual context, generation manifests, exact title association, Worker compatibility, `auto_keywords`, `auto_description`, admin workflow, audit, migration and tests are each assigned to a task.
- Contract consistency: every generation path consumes `ContentGenerationContext`; compiler and renderer consume exact published version IDs; title, Worker and SEO manifests use `GenerationManifestService`; `KeywordCluster` remains the sole cluster contract.
- Scope boundary: this plan does not implement community personas/distribution, LITB-specific article templates, commerce synchronization, or a public website redesign.
- Placeholder scan: the plan contains no TBD/TODO steps; every implementation task names files, interfaces, tests, commands, and expected behavior.

