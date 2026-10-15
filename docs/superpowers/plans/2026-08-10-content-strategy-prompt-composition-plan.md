# Content Strategy and Prompt Composition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a versioned content brief, strategy, blueprint, modular Prompt compiler, traceable generation run, and quality-gated article handoff while preserving GEOFlow's existing Prompt and Worker paths.

**Architecture:** Add three bounded modules inside the Laravel monolith: ContentStrategy owns briefs and immutable strategy/blueprint versions; PromptComposition owns immutable module/preset versions and deterministic compilation; ContentGeneration coordinates the existing model service, quality gates, revision, and Article adoption. New tables reference existing keywords, knowledge chunks, AI models, admins, and articles; the legacy path remains unchanged behind feature flags.

**Tech Stack:** PHP 8.3, Laravel 12, PostgreSQL, Blade/Tailwind, Laravel queues/events, Laravel AI through the existing `ArticleContentGenerationService`, PHPUnit.

## Global Constraints

- Do not remove or repurpose the existing `prompts`, `tasks`, `task_runs`, or `articles` tables.
- Existing Task → Prompt → Worker → Article behavior must remain available and covered by regression tests.
- Used module versions, preset versions, strategy versions, blueprint versions, and generation runs are immutable and cannot be physically deleted.
- Prompt modules compile into one coherent instruction; modules do not independently generate article fragments.
- Deterministic validation and conflict resolution run before any model call.
- Hard constraints, evidence rules, strategy, and blueprint may not be silently removed to satisfy a token budget.
- Core long-form generation supports at most one automatic targeted revision in this phase.
- ManualPublication remains the downstream execution system and does not receive strategy fields in this phase.
- Keep the unrelated local `docker-compose.yml` modification out of feature commits.

---

## File Structure

### Content strategy

- `app/Models/ContentBrief.php` — brief aggregate and current-state helpers.
- `app/Models/ContentStrategyVersion.php` — immutable strategy snapshot.
- `app/Models/ContentBlueprintVersion.php` — immutable blueprint snapshot.
- `app/Domain/ContentStrategy/ContentStrategyService.php` — create suggestions and confirm immutable versions.
- `app/Domain/ContentStrategy/BlueprintValidator.php` — deterministic blueprint schema validation.

### Prompt composition

- `app/Models/PromptModule.php` — stable module identity.
- `app/Models/PromptModuleVersion.php` — immutable module text and variable contract.
- `app/Models/PromptPreset.php` — stable preset identity.
- `app/Models/PromptPresetVersion.php` — immutable preset matching/generation policy.
- `app/Models/PromptPresetModuleVersion.php` — ordered version binding.
- `app/Domain/PromptComposition/PromptContext.php` — typed compilation input.
- `app/Domain/PromptComposition/PromptModuleResolver.php` — resolves exact production versions.
- `app/Domain/PromptComposition/PromptVariableValidator.php` — validates contracts.
- `app/Domain/PromptComposition/PromptConflictResolver.php` — applies deterministic precedence.
- `app/Domain/PromptComposition/PromptCompiler.php` — renders fixed sections and returns a compilation result.
- `app/Domain/PromptComposition/CompiledPrompt.php` — immutable compilation result.

### Generation and quality

- `app/Models/ContentGenerationRun.php` — one initial generation or retry.
- `app/Models/ContentGenerationRunModule.php` — exact module-version snapshot.
- `app/Models/ContentGenerationRunEvidence.php` — exact knowledge reference snapshot.
- `app/Models/ContentGenerationRunStep.php` — initial/check/revision/adoption timeline.
- `app/Models/ContentQualityReport.php` — structured quality result.
- `app/Domain/ContentGeneration/DeterministicQualityGate.php` — structural and density checks.
- `app/Domain/ContentGeneration/SemanticQualityReviewer.php` — structured model review.
- `app/Domain/ContentGeneration/ContentGenerationCoordinator.php` — orchestration and idempotency.
- `app/Domain/ContentGeneration/GenerationAdoptionService.php` — creates/updates Article through existing workflow.

### Admin UI and integration

- `app/Http/Controllers/Admin/ContentBriefController.php`
- `app/Http/Controllers/Admin/PromptModuleController.php`
- `app/Http/Controllers/Admin/PromptPresetController.php`
- `app/Http/Controllers/Admin/ContentGenerationController.php`
- `app/Http/Requests/Admin/*` — one request per mutation.
- `resources/views/admin/content-briefs/*`
- `resources/views/admin/prompt-modules/*`
- `resources/views/admin/prompt-presets/*`
- `resources/views/admin/content-generations/show.blade.php`
- `routes/web.php` — guarded admin routes.
- `config/geoflow.php` — three feature flags.

---

### Task 1: Persistence foundation and immutable model contracts

**Files:**
- Create: `database/migrations/2026_08_10_010000_create_content_strategy_tables.php`
- Create: `database/migrations/2026_08_10_010100_create_prompt_composition_tables.php`
- Create: `database/migrations/2026_08_10_010200_create_content_generation_trace_tables.php`
- Create: `app/Models/ContentBrief.php`
- Create: `app/Models/ContentStrategyVersion.php`
- Create: `app/Models/ContentBlueprintVersion.php`
- Create: `app/Models/PromptModule.php`
- Create: `app/Models/PromptModuleVersion.php`
- Create: `app/Models/PromptPreset.php`
- Create: `app/Models/PromptPresetVersion.php`
- Create: `app/Models/PromptPresetModuleVersion.php`
- Create: `app/Models/ContentGenerationRun.php`
- Create: `app/Models/ContentGenerationRunModule.php`
- Create: `app/Models/ContentGenerationRunEvidence.php`
- Create: `app/Models/ContentGenerationRunStep.php`
- Create: `app/Models/ContentQualityReport.php`
- Test: `tests/Feature/ContentStrategySchemaTest.php`

**Interfaces:**
- Produces: Eloquent relations `ContentBrief::strategyVersions()`, `ContentBrief::blueprintVersions()`, `PromptModule::versions()`, `PromptPresetVersion::moduleVersions()`, and `ContentGenerationRun::steps()`.
- Produces: unique stable keys and monotonically increasing per-parent version numbers enforced by database constraints.

- [ ] **Step 1: Write the failing schema and immutability test**

```php
public function test_content_pipeline_schema_preserves_versioned_lineage(): void
{
    $this->assertTrue(Schema::hasColumns('content_briefs', [
        'topic', 'market_code', 'language_code', 'audience', 'core_question',
        'business_goal', 'content_type', 'planned_publish_at', 'status',
    ]));
    $this->assertTrue(Schema::hasColumns('prompt_module_versions', [
        'prompt_module_id', 'version', 'content', 'variable_contract', 'lifecycle_status',
    ]));
    $this->assertTrue(Schema::hasColumns('content_generation_runs', [
        'content_brief_id', 'strategy_version_id', 'blueprint_version_id',
        'preset_version_id', 'ai_model_id', 'article_id', 'compiled_prompt',
        'raw_output', 'adopted_output', 'status', 'idempotency_key',
    ]));
}
```

- [ ] **Step 2: Run the test and verify the missing-table failure**

Run: `docker compose exec app php artisan test tests/Feature/ContentStrategySchemaTest.php`

Expected: FAIL because `content_briefs` and related tables do not exist.

- [ ] **Step 3: Add migrations with foreign keys, JSONB payloads, uniqueness, and indexes**

Use explicit constraints including:

```php
$table->unique(['content_brief_id', 'version']);
$table->unique(['prompt_module_id', 'version']);
$table->unique(['prompt_preset_id', 'version']);
$table->unique('idempotency_key');
$table->index(['status', 'created_at']);
```

Store snapshot payloads as JSON and high-volume prompt/output fields as `longText`. Use `restrictOnDelete()` for referenced immutable versions and `nullOnDelete()` only for optional live objects such as Article.

- [ ] **Step 4: Add focused Eloquent models, casts, and relations**

Use model constants for states, guarded version fields, and no mutator that updates historical content. Example:

```php
final class PromptModuleVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PRODUCTION = 'production';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'prompt_module_id', 'version', 'content', 'variable_contract',
        'lifecycle_status', 'change_note', 'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return ['variable_contract' => 'array', 'version' => 'integer'];
    }
}
```

- [ ] **Step 5: Run schema test and migration rollback test**

Run: `docker compose exec app php artisan test tests/Feature/ContentStrategySchemaTest.php`

Expected: PASS.

- [ ] **Step 6: Commit persistence foundation**

```bash
git add database/migrations app/Models tests/Feature/ContentStrategySchemaTest.php
git commit -m "feat: add content strategy pipeline schema"
```

### Task 2: Prompt module lifecycle and version management service

**Files:**
- Create: `app/Domain/PromptComposition/PromptModuleService.php`
- Create: `app/Exceptions/ImmutablePromptVersionException.php`
- Test: `tests/Unit/PromptModuleServiceTest.php`

**Interfaces:**
- Consumes: `PromptModule`, `PromptModuleVersion`, authenticated `Admin`.
- Produces: `createModule(array $identity, array $version, Admin $actor): PromptModule`.
- Produces: `createVersion(PromptModule $module, array $data, Admin $actor): PromptModuleVersion`.
- Produces: `publish(PromptModuleVersion $version, Admin $actor, string $reason): PromptModuleVersion`.
- Produces: `archive(PromptModuleVersion $version, Admin $actor, string $reason): PromptModuleVersion`.

- [ ] **Step 1: Write lifecycle tests**

```php
public function test_publishing_a_version_deprecates_previous_production_without_mutating_content(): void
{
    $module = $this->moduleWithVersions(['production', 'draft']);
    $draft = $module->versions()->where('lifecycle_status', 'draft')->firstOrFail();
    $published = app(PromptModuleService::class)->publish($draft, $this->admin, 'Improve depth');

    $this->assertSame('production', $published->fresh()->lifecycle_status);
    $this->assertSame('deprecated', $module->versions()->where('version', 1)->value('lifecycle_status'));
    $this->assertSame('Improve depth', $published->change_note);
}
```

Also test stable `module_key`, sequential version allocation inside a transaction, rejection of content mutation for a used version, and archive rejection for an active preset dependency.

- [ ] **Step 2: Run the unit test and verify service-not-found failure**

Run: `docker compose exec app php artisan test tests/Unit/PromptModuleServiceTest.php`

- [ ] **Step 3: Implement transactional lifecycle operations**

Lock the parent module row before allocating `max(version) + 1`. Publishing must atomically deprecate the previous production version for the same module. Log changes through the existing admin activity logger without recording full Prompt text.

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec app php artisan test tests/Unit/PromptModuleServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit module lifecycle**

```bash
git add app/Domain/PromptComposition app/Exceptions tests/Unit/PromptModuleServiceTest.php
git commit -m "feat: manage versioned prompt modules"
```

### Task 3: Prompt presets and deterministic version resolution

**Files:**
- Create: `app/Domain/PromptComposition/PromptModuleResolver.php`
- Create: `app/Domain/PromptComposition/ResolvedPromptModule.php`
- Create: `app/Domain/PromptComposition/PromptPresetService.php`
- Test: `tests/Unit/PromptModuleResolverTest.php`

**Interfaces:**
- Consumes: `PromptPresetVersion`, market, language, content type, and optional expert version overrides.
- Produces: `resolve(PromptPresetVersion $preset, array $context, array $overrides = []): array<ResolvedPromptModule>`.
- `ResolvedPromptModule` exposes exact module ID, version ID, module type, priority, required flag, content, and variable contract.

- [ ] **Step 1: Write resolver tests**

```php
public function test_auto_mode_resolves_exact_production_versions_in_stable_order(): void
{
    $resolved = app(PromptModuleResolver::class)->resolve($this->presetVersion, [
        'content_type' => 'buying_guide',
        'language_code' => 'en',
        'market_code' => 'US',
    ]);

    $this->assertSame(['foundation_truth', 'geo_citability', 'buying_guide'],
        array_map(fn ($item) => $item->moduleKey, $resolved));
}
```

Test that required bindings cannot be overridden away, archived versions never auto-resolve, explicit expert overrides require an eligible version from the same module, and ties use binding position then ID.

- [ ] **Step 2: Run tests and verify failure**

Run: `docker compose exec app php artisan test tests/Unit/PromptModuleResolverTest.php`

- [ ] **Step 3: Implement immutable DTO, resolver, and preset version creation**

Resolver must use only exact version bindings for published preset versions. Draft preset editing may select draft module versions, but a production preset must bind non-archived exact versions.

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec app php artisan test tests/Unit/PromptModuleResolverTest.php`

- [ ] **Step 5: Commit preset resolution**

```bash
git add app/Domain/PromptComposition tests/Unit/PromptModuleResolverTest.php
git commit -m "feat: resolve versioned prompt presets"
```

### Task 4: Variable contracts, conflict resolution, and Prompt compilation

**Files:**
- Create: `app/Domain/PromptComposition/PromptContext.php`
- Create: `app/Domain/PromptComposition/PromptVariableValidator.php`
- Create: `app/Domain/PromptComposition/PromptConflictResolver.php`
- Create: `app/Domain/PromptComposition/PromptCompiler.php`
- Create: `app/Domain/PromptComposition/CompiledPrompt.php`
- Create: `app/Exceptions/PromptCompilationException.php`
- Test: `tests/Unit/PromptCompilerTest.php`

**Interfaces:**
- Consumes: resolved modules and `PromptContext` containing strategy, blueprint, keywords, evidence, products, language, and market.
- Produces: `PromptCompiler::compile(array $modules, PromptContext $context): CompiledPrompt`.
- `CompiledPrompt` contains `text`, `moduleSnapshots`, `resolvedVariables`, `conflictDecisions`, `estimatedTokens`, and `contextDigest`.

- [ ] **Step 1: Write compilation tests**

```php
public function test_compiler_renders_one_coherent_prompt_and_records_conflict_decisions(): void
{
    $compiled = app(PromptCompiler::class)->compile($this->modules(), $this->context([
        'cta_allowed' => false,
        'requested_cta' => 'strong',
    ]));

    $this->assertStringContainsString('CONTENT STRATEGY', $compiled->text);
    $this->assertStringContainsString('CONTENT BLUEPRINT', $compiled->text);
    $this->assertStringNotContainsString('{{audience}}', $compiled->text);
    $this->assertSame('cta_disabled_by_hard_constraint', $compiled->conflictDecisions[0]['code']);
}
```

Test missing required variables, unknown variables, invalid types, deterministic section order, sensitive-value redaction in snapshots, and refusal to remove a required module when over budget.

- [ ] **Step 2: Run tests and verify failure**

Run: `docker compose exec app php artisan test tests/Unit/PromptCompilerTest.php`

- [ ] **Step 3: Implement variable validation**

Support contract types `string`, `integer`, `boolean`, `array`, and `object`; enforce `required`, `default`, and `max_length`. Return field-addressable errors such as `module.geo_citability.variables.audience.required`.

- [ ] **Step 4: Implement precedence-based conflict resolution**

Represent decisions as structured arrays with `code`, `winner`, `loser`, `reason`, and affected module version IDs. Implement initial conflicts for CTA permission, external links, evidence requirements, language, output format, and target length.

- [ ] **Step 5: Implement fixed-section compiler and token estimate**

Render all modules and dynamic data into the nine sections defined in the design. Estimate tokens conservatively from UTF-8 character counts; trim duplicate/low-relevance evidence only, and throw `PromptCompilationException` when protected content still exceeds the selected model budget.

- [ ] **Step 6: Run focused tests**

Run: `docker compose exec app php artisan test tests/Unit/PromptCompilerTest.php`

- [ ] **Step 7: Commit the compiler**

```bash
git add app/Domain/PromptComposition app/Exceptions/PromptCompilationException.php tests/Unit/PromptCompilerTest.php
git commit -m "feat: compile validated modular prompts"
```

### Task 5: Content brief, strategy versions, and blueprint validation

**Files:**
- Create: `app/Domain/ContentStrategy/ContentStrategyService.php`
- Create: `app/Domain/ContentStrategy/BlueprintValidator.php`
- Create: `app/Domain/ContentStrategy/StrategySuggestion.php`
- Test: `tests/Unit/ContentStrategyServiceTest.php`
- Test: `tests/Unit/BlueprintValidatorTest.php`

**Interfaces:**
- Produces: `suggest(ContentBrief $brief): StrategySuggestion` using deterministic keyword/cluster fields with an optional configured model extension.
- Produces: `confirmStrategy(ContentBrief $brief, array $strategy, Admin $actor, string $reason): ContentStrategyVersion`.
- Produces: `confirmBlueprint(ContentBrief $brief, ContentStrategyVersion $strategy, array $blueprint, Admin $actor): ContentBlueprintVersion`.
- Produces: `BlueprintValidator::validate(array $blueprint): array<string, array<string>>`.

- [ ] **Step 1: Write strategy and blueprint tests**

```php
public function test_confirming_changed_strategy_creates_a_new_immutable_version(): void
{
    $v1 = $this->service->confirmStrategy($this->brief, $this->strategy('informational'), $this->admin, 'Initial');
    $v2 = $this->service->confirmStrategy($this->brief, $this->strategy('commercial_investigation'), $this->admin, 'Operator correction');

    $this->assertSame(1, $v1->version);
    $this->assertSame(2, $v2->version);
    $this->assertSame('informational', $v1->fresh()->primary_intent);
}
```

Blueprint tests cover stable section keys, unique keys, non-empty purpose, at least one question per substantive section, positive word targets, target total consistency, valid evidence reference shape, and one CTA policy per section.

- [ ] **Step 2: Run tests and verify failure**

Run: `docker compose exec app php artisan test tests/Unit/ContentStrategyServiceTest.php tests/Unit/BlueprintValidatorTest.php`

- [ ] **Step 3: Implement deterministic suggestion and immutable confirmation**

Use existing KeywordCluster values where present. Store system suggestion, confidence, evidence, operator correction, correction reason, and final classification separately in the strategy snapshot.

- [ ] **Step 4: Implement blueprint validation and version allocation**

Reject invalid blueprints before persistence. Lock the brief while allocating versions. Update only the brief's current status/version pointers; never update historical payloads.

- [ ] **Step 5: Run focused tests**

Run: `docker compose exec app php artisan test tests/Unit/ContentStrategyServiceTest.php tests/Unit/BlueprintValidatorTest.php`

- [ ] **Step 6: Commit content strategy domain**

```bash
git add app/Domain/ContentStrategy tests/Unit/ContentStrategyServiceTest.php tests/Unit/BlueprintValidatorTest.php
git commit -m "feat: add versioned content strategy and blueprints"
```

### Task 6: Deterministic content quality gate

**Files:**
- Create: `app/Domain/ContentGeneration/QualityIssue.php`
- Create: `app/Domain/ContentGeneration/DeterministicQualityGate.php`
- Create: `app/Domain/ContentGeneration/QualityGateResult.php`
- Test: `tests/Unit/DeterministicQualityGateTest.php`

**Interfaces:**
- Produces: `inspect(string $markdown, array $blueprint, array $requirements): QualityGateResult`.
- Result exposes `passed`, `score`, and typed issues with `code`, `severity`, `sectionKey`, `message`, and `repairInstruction`.

- [ ] **Step 1: Write failing quality tests**

```php
public function test_gate_rejects_thin_sections_and_unresolved_variables(): void
{
    $result = $this->gate->inspect("## Sizing\nChoose the right size.\n{{product_name}}", $this->blueprint(), []);

    $this->assertFalse($result->passed);
    $this->assertContains('section_too_thin', array_column($result->issuesAsArray(), 'code'));
    $this->assertContains('unresolved_variable', array_column($result->issuesAsArray(), 'code'));
}
```

Also test missing sections, duplicate paragraphs, keyword stuffing threshold, missing required entity, missing question coverage, heading hierarchy, language mismatch heuristic, and a passing substantive article.

- [ ] **Step 2: Run tests and verify failure**

Run: `docker compose exec app php artisan test tests/Unit/DeterministicQualityGateTest.php`

- [ ] **Step 3: Implement pure deterministic checks**

Keep the service side-effect free. Normalize Markdown headings and paragraphs, map headings to blueprint section keys, and generate targeted repair instructions rather than generic “rewrite” messages.

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec app php artisan test tests/Unit/DeterministicQualityGateTest.php`

- [ ] **Step 5: Commit quality gate**

```bash
git add app/Domain/ContentGeneration tests/Unit/DeterministicQualityGateTest.php
git commit -m "feat: add deterministic content quality gate"
```

### Task 7: Traceable generation coordinator and one targeted revision

**Files:**
- Create: `app/Domain/ContentGeneration/SemanticQualityReviewer.php`
- Create: `app/Domain/ContentGeneration/ContentGenerationCoordinator.php`
- Create: `app/Jobs/GenerateStrategyContent.php`
- Create: `app/Events/ContentGenerationCompleted.php`
- Create: `app/Events/ContentGenerationFailed.php`
- Test: `tests/Feature/ContentGenerationCoordinatorTest.php`

**Interfaces:**
- Consumes: confirmed brief, strategy, blueprint, preset version, AI model, and idempotency key.
- Produces: `ContentGenerationCoordinator::generate(ContentGenerationRun $run): ContentGenerationRun`.
- Reuses: `PromptModuleResolver`, `PromptCompiler`, `ArticleContentGenerationService`, and `DeterministicQualityGate`.

- [ ] **Step 1: Write coordinator tests with a fake model service**

```php
public function test_generation_snapshots_inputs_and_performs_only_one_targeted_revision(): void
{
    $this->fakeGenerator->queue('thin initial output', 'substantive revised output');
    $run = $this->coordinator->generate($this->pendingRun());

    $this->assertSame('ready_for_review', $run->fresh()->status);
    $this->assertCount(2, $run->steps()->whereIn('type', ['initial_generation', 'targeted_revision'])->get());
    $this->assertNotNull($run->compiled_prompt);
    $this->assertNotEmpty($run->moduleSnapshots()->get());
}
```

Test idempotent duplicate job execution, model failure, empty output, needs-evidence failure, deterministic pass without revision, semantic-review parse failure, and second quality failure yielding `manual_review_required`.

- [ ] **Step 2: Run the test and verify failure**

Run: `docker compose exec app php artisan test tests/Feature/ContentGenerationCoordinatorTest.php`

- [ ] **Step 3: Implement run preparation and immutable snapshots**

Lock the run by ID and transition `pending → compiling → generating`. Persist exact module versions, evidence references, compiled Prompt, context digest, and model metadata before calling the model.

- [ ] **Step 4: Implement initial generation, quality review, and targeted revision**

Semantic review returns strict JSON with `passed`, `score`, and issues. Build a revision Prompt containing the original blueprint, original article, and only unresolved repair instructions. Record each model interaction in `content_generation_run_steps`.

- [ ] **Step 5: Implement idempotent queued job and events**

Use `idempotency_key` plus terminal-state checks. A retry of the same completed job returns without a second model call. Failures persist sanitized messages and dispatch `ContentGenerationFailed` after commit.

- [ ] **Step 6: Run focused tests**

Run: `docker compose exec app php artisan test tests/Feature/ContentGenerationCoordinatorTest.php`

- [ ] **Step 7: Commit generation coordinator**

```bash
git add app/Domain/ContentGeneration app/Jobs app/Events tests/Feature/ContentGenerationCoordinatorTest.php
git commit -m "feat: add traceable quality-gated generation"
```

### Task 8: Adopt a generation run into the existing Article workflow

**Files:**
- Create: `app/Domain/ContentGeneration/GenerationAdoptionService.php`
- Test: `tests/Feature/GenerationAdoptionServiceTest.php`
- Modify: `app/Models/Article.php`

**Interfaces:**
- Produces: `adopt(ContentGenerationRun $run, array $articleData, Admin $actor): Article`.
- Reuses: `ArticleGeoFlowService` and `ArticleRiskScanner`; adopted articles start as drafts with `review_status=pending` unless existing workflow rules produce a stricter state.

- [ ] **Step 1: Write adoption tests**

```php
public function test_ready_run_can_be_adopted_once_into_pending_article_review(): void
{
    $article = $this->service->adopt($this->readyRun, $this->articleData(), $this->admin);

    $this->assertSame('pending', $article->review_status);
    $this->assertSame($article->id, $this->readyRun->fresh()->article_id);
    $this->assertSame('adopted', $this->readyRun->fresh()->status);
}
```

Test rejection of failed/unreviewed runs, optimistic concurrency, a second adoption returning the same Article rather than creating a duplicate, and risk scanning invocation.

- [ ] **Step 2: Run the test and verify failure**

Run: `docker compose exec app php artisan test tests/Feature/GenerationAdoptionServiceTest.php`

- [ ] **Step 3: Implement transactional adoption through existing services**

Do not duplicate Article validation or workflow logic. Add only a `generationRuns()` relation to Article. Record an adoption step with actor and source hashes.

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec app php artisan test tests/Feature/GenerationAdoptionServiceTest.php`

- [ ] **Step 5: Commit article integration**

```bash
git add app/Domain/ContentGeneration/GenerationAdoptionService.php app/Models/Article.php tests/Feature/GenerationAdoptionServiceTest.php
git commit -m "feat: adopt generation runs into article workflow"
```

### Task 9: Feature flags and legacy compatibility regression

**Files:**
- Modify: `config/geoflow.php`
- Modify: `.env.example`
- Test: `tests/Feature/ContentPipelineCompatibilityTest.php`
- Test: `tests/Unit/WorkerExecutionServicePromptTest.php`

**Interfaces:**
- Produces config keys `geoflow.content_strategy_enabled`, `geoflow.modular_prompts_enabled`, and `geoflow.generation_quality_gate_enabled`.

- [ ] **Step 1: Write compatibility tests**

```php
public function test_legacy_worker_uses_existing_renderer_when_modular_prompts_are_disabled(): void
{
    config()->set('geoflow.modular_prompts_enabled', false);
    $article = $this->runLegacyTask();
    $this->assertNotNull($article);
    $this->assertDatabaseCount('content_generation_runs', 0);
}
```

Also prove that disabled strategy routes return 404, legacy Prompt CRUD still works, and existing Worker prompt evidence behavior remains unchanged.

- [ ] **Step 2: Run compatibility tests and verify missing flags/routes failure**

Run: `docker compose exec app php artisan test tests/Feature/ContentPipelineCompatibilityTest.php tests/Unit/WorkerExecutionServicePromptTest.php`

- [ ] **Step 3: Add flags with safe defaults**

Default all three flags to `false` in production configuration. Tests may enable them explicitly. Do not branch inside `ArticleContentGenerationService`; branch at the new entry route/job only.

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec app php artisan test tests/Feature/ContentPipelineCompatibilityTest.php tests/Unit/WorkerExecutionServicePromptTest.php`

- [ ] **Step 5: Commit compatibility layer**

```bash
git add config/geoflow.php .env.example tests/Feature/ContentPipelineCompatibilityTest.php tests/Unit/WorkerExecutionServicePromptTest.php
git commit -m "feat: gate modular content pipeline"
```

### Task 10: Prompt module and preset administration

**Files:**
- Create: `app/Http/Controllers/Admin/PromptModuleController.php`
- Create: `app/Http/Controllers/Admin/PromptPresetController.php`
- Create: `app/Http/Requests/Admin/StorePromptModuleRequest.php`
- Create: `app/Http/Requests/Admin/StorePromptModuleVersionRequest.php`
- Create: `app/Http/Requests/Admin/PublishPromptModuleVersionRequest.php`
- Create: `app/Http/Requests/Admin/StorePromptPresetRequest.php`
- Create: `resources/views/admin/prompt-modules/index.blade.php`
- Create: `resources/views/admin/prompt-modules/show.blade.php`
- Create: `resources/views/admin/prompt-presets/index.blade.php`
- Create: `resources/views/admin/prompt-presets/edit.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminPromptModulesTest.php`

**Interfaces:**
- Consumes services from Tasks 2 and 3.
- Produces admin CRUD, version comparison, publish, deprecate, archive, rollback-by-publish, usage lookup, and preset ordering routes.

- [ ] **Step 1: Write authorization and workflow tests**

```php
public function test_super_admin_can_publish_a_new_module_version_with_reason(): void
{
    $response = $this->actingAs($this->superAdmin, 'admin')->post(
        route('admin.prompt-modules.versions.publish', [$this->module, $this->draftVersion]),
        ['reason' => 'Depth test passed']
    );
    $response->assertRedirect();
    $this->assertSame('production', $this->draftVersion->fresh()->lifecycle_status);
}
```

Test that content operators cannot publish system modules, version text is not included in activity-log payloads, used versions cannot be deleted, invalid contracts show field errors, and preset ordering persists exact version IDs.

- [ ] **Step 2: Run tests and verify route/controller failure**

Run: `docker compose exec app php artisan test tests/Feature/AdminPromptModulesTest.php`

- [ ] **Step 3: Implement requests, controllers, routes, and views**

Use existing admin layout, CSRF, policies/middleware, pagination, and translation conventions. Show a line diff between versions and a usage list of generation runs/articles. Never place full Prompt content in activity log metadata.

- [ ] **Step 4: Run focused tests and frontend build**

Run: `docker compose exec app php artisan test tests/Feature/AdminPromptModulesTest.php`

Run: `npm run build`

- [ ] **Step 5: Commit admin Prompt management**

```bash
git add app/Http/Controllers/Admin/Prompt* app/Http/Requests/Admin/*Prompt* resources/views/admin/prompt-* routes/web.php tests/Feature/AdminPromptModulesTest.php
git commit -m "feat: add prompt module administration"
```

### Task 11: Content brief wizard, generation review, and adoption UI

**Files:**
- Create: `app/Http/Controllers/Admin/ContentBriefController.php`
- Create: `app/Http/Controllers/Admin/ContentGenerationController.php`
- Create: `app/Http/Requests/Admin/StoreContentBriefRequest.php`
- Create: `app/Http/Requests/Admin/ConfirmContentStrategyRequest.php`
- Create: `app/Http/Requests/Admin/ConfirmContentBlueprintRequest.php`
- Create: `app/Http/Requests/Admin/RequestContentGenerationRequest.php`
- Create: `app/Http/Requests/Admin/AdoptContentGenerationRequest.php`
- Create: `resources/views/admin/content-briefs/index.blade.php`
- Create: `resources/views/admin/content-briefs/create.blade.php`
- Create: `resources/views/admin/content-briefs/show.blade.php`
- Create: `resources/views/admin/content-briefs/strategy.blade.php`
- Create: `resources/views/admin/content-briefs/blueprint.blade.php`
- Create: `resources/views/admin/content-generations/show.blade.php`
- Modify: `resources/views/admin/partials/header.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminContentBriefWorkflowTest.php`

**Interfaces:**
- Consumes Tasks 5, 7, and 8.
- Produces five-step admin wizard and run detail page with module versions, conflict decisions, evidence, quality issues, model usage, revision history, and Article adoption.

- [ ] **Step 1: Write the end-to-end admin workflow test**

```php
public function test_operator_can_complete_brief_to_generation_request_workflow(): void
{
    $brief = $this->createBriefThroughHttp();
    $this->confirmStrategyThroughHttp($brief);
    $this->confirmBlueprintThroughHttp($brief);
    $response = $this->requestGenerationThroughHttp($brief);

    $response->assertRedirect();
    $this->assertDatabaseHas('content_generation_runs', [
        'content_brief_id' => $brief->id,
        'status' => 'pending',
    ]);
}
```

Test validation, market/language fields, low-confidence intent correction reason, blueprint errors, required module visibility, queue dispatch, run quality display, authorization, and adoption.

- [ ] **Step 2: Run tests and verify failure**

Run: `docker compose exec app php artisan test tests/Feature/AdminContentBriefWorkflowTest.php`

- [ ] **Step 3: Implement requests, controllers, guarded routes, and wizard views**

Keep advanced module selection collapsed by default. Show system-chosen modules and versions before generation. Only privileged users may view the final full compiled Prompt; other users see module names, version numbers, and a context digest.

- [ ] **Step 4: Add navigation and translations**

Add Chinese and English labels for the new menu and all validation/status text following existing `lang/*/admin.php` conventions. Other locales fall back to English until translated.

- [ ] **Step 5: Run focused tests and frontend build**

Run: `docker compose exec app php artisan test tests/Feature/AdminContentBriefWorkflowTest.php`

Run: `npm run build`

- [ ] **Step 6: Commit content workflow UI**

```bash
git add app/Http/Controllers/Admin/Content* app/Http/Requests/Admin/*Content* resources/views/admin/content-* resources/views/admin/partials/header.blade.php routes/web.php lang tests/Feature/AdminContentBriefWorkflowTest.php
git commit -m "feat: add content strategy generation wizard"
```

### Task 12: Legacy Prompt migration seeds, security, and full verification

**Files:**
- Create: `database/seeders/PromptCompositionSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/PromptCompositionSeederTest.php`
- Create: `tests/Feature/ContentPipelineSecurityTest.php`
- Modify: `docs/CHANGELOG.md`
- Modify: `docs/CHANGELOG_en.md`

**Interfaces:**
- Produces idempotent system modules and Legacy presets for existing Prompt types without changing existing Prompt IDs.

- [ ] **Step 1: Write idempotent seeder and security tests**

```php
public function test_seeder_is_idempotent_and_preserves_legacy_prompts(): void
{
    $before = Prompt::query()->pluck('content', 'id');
    $this->seed(PromptCompositionSeeder::class);
    $this->seed(PromptCompositionSeeder::class);

    $this->assertSame($before->all(), Prompt::query()->pluck('content', 'id')->all());
    $this->assertSame(1, PromptModule::query()->where('module_key', 'legacy_content')->count());
}
```

Security tests cover external-reference Prompt injection treated as quoted data, sensitive variable redaction, authorization of full Prompt snapshots, mass-assignment rejection, and outbound URL safety through existing policies.

- [ ] **Step 2: Run tests and verify failure**

Run: `docker compose exec app php artisan test tests/Feature/PromptCompositionSeederTest.php tests/Feature/ContentPipelineSecurityTest.php`

- [ ] **Step 3: Implement idempotent system seed data**

Seed minimal required modules for truth/evidence, GEO citability, SEO structure, language/market, output format, and quality checks. Seed compatible presets for the existing four Prompt scenarios by copying rather than mutating legacy content.

- [ ] **Step 4: Run all focused pipeline tests**

Run: `docker compose exec app php artisan test --filter='ContentStrategy|PromptModule|PromptCompiler|ContentGeneration|GenerationAdoption|ContentPipeline'`

Expected: PASS.

- [ ] **Step 5: Run the full backend and frontend verification**

Run: `docker compose exec app php artisan test`

Run: `npm run build`

Expected: all tests pass and Vite completes without errors.

- [ ] **Step 6: Verify migration cycle on PostgreSQL**

Run: `docker compose exec app php artisan migrate:status`

Run in the test environment or disposable database: `php artisan migrate:fresh --seed`.

Expected: all migrations and seeders complete twice without duplicate-key failures.

- [ ] **Step 7: Update changelogs and commit verification assets**

```bash
git add database/seeders tests/Feature/PromptCompositionSeederTest.php tests/Feature/ContentPipelineSecurityTest.php docs/CHANGELOG.md docs/CHANGELOG_en.md
git commit -m "feat: ship modular content strategy pipeline"
```

## Final Verification Checklist

- [ ] `git diff --check` reports no whitespace errors.
- [ ] `docker compose exec app php artisan test` passes.
- [ ] `npm run build` passes.
- [ ] Existing keyword import, Article Assistant, Worker execution, Article review, distribution, and ManualPublication focused suites pass.
- [ ] Feature flags disabled preserve the legacy path.
- [ ] A newly generated article shows exact strategy, blueprint, module, evidence, model, quality, and revision lineage.
- [ ] Used historical versions cannot be overwritten or physically deleted.
- [ ] The unrelated `docker-compose.yml` working-tree modification remains uncommitted and unchanged.
