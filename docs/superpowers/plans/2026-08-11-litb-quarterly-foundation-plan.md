# LITB Quarterly Content Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the navigable, idempotent knowledge, keyword, Prompt, and 90-brief foundation required for LITB's English-first quarterly GEO/SEO program.

**Architecture:** Add a manifest-driven `LitbContentAssets` application service and Artisan command around existing KnowledgeBase, keyword intelligence, Prompt composition, and content strategy domains. Reserved stable keys and source hashes make reruns safe; imported assets keep provenance while operator-owned edits remain authoritative.

**Tech Stack:** PHP 8.2+, Laravel, PostgreSQL, Blade/Tailwind, PhpSpreadsheet, PHPUnit, Docker Compose

## Global Constraints

- Treat `/Users/litb/Documents/litb_geo/litb_zhishiku` and `/Users/litb/Documents/litb_geo/litb关键词` as read-only inputs.
- Preserve the user's existing `docker-compose.yml` changes and never include them in task commits.
- English is the primary production language; Portuguese, Chinese, Spanish, French, German, and Italian remain explicit localization targets.
- Initialization is idempotent, never overwrites records without seed provenance, and retains import audit rows after revert.
- Time-sensitive price, inventory, shipping, returns, financial, and news claims require dated evidence.
- Existing Prompt, Task, Worker, Article, review, and publishing behavior remains backward compatible.
- No initializer operation automatically publishes an article.

---

## File Structure

- `app/Console/Commands/InitializeLitbContentAssetsCommand.php`: operator entry point and phase selection.
- `app/Services/GeoFlow/LitbAssets/LitbAssetConfig.php`: validates paths, quarter identity, locale, and market.
- `app/Services/GeoFlow/LitbAssets/LitbAssetManifest.php`: deterministic run manifest and counters.
- `app/Services/GeoFlow/LitbAssets/LitbSourceScanner.php`: read-only source discovery, hashing, and classification.
- `app/Services/GeoFlow/LitbAssets/LitbKnowledgeImporter.php`: idempotent KnowledgeBase creation/update and chunk dispatch.
- `app/Services/GeoFlow/LitbAssets/LitbKeywordImporter.php`: source-to-library mapping and reuse of keyword import services.
- `app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefImporter.php`: creates the reserved 90-brief catalog and immutable strategy/blueprint snapshots.
- `config/litb_content_assets.php`: input paths, knowledge groups, keyword libraries, quota, and reserved namespace.
- `database/seeders/LitbPromptLibrarySeeder.php`: expanded system Prompt modules and production presets.
- `database/seeders/LitbQuarterlyBriefSeeder.php`: invokes the brief importer from the database seeding workflow.
- `database/migrations/2026_08_11_000001_add_litb_content_asset_provenance.php`: stable keys and provenance fields.
- `resources/content/litb-quarterly-2026-q3/brief-catalog.json`: reviewed 90-entry source catalog.
- `resources/views/admin/partials/content-center-nav.blade.php`: shared secondary navigation.

### Task 1: Add Discoverable Content and Prompt Navigation

**Files:**
- Modify: `resources/views/admin/partials/header.blade.php`
- Create: `resources/views/admin/partials/content-center-nav.blade.php`
- Modify: `resources/views/admin/content-briefs/index.blade.php`
- Modify: `resources/views/admin/prompt-modules/index.blade.php`
- Modify: `resources/views/admin/prompt-presets/index.blade.php`
- Modify: `lang/en/admin.php`
- Modify: `lang/zh_CN/admin.php`
- Test: `tests/Feature/AdminContentCenterNavigationTest.php`

**Interfaces:**
- Consumes: named routes `admin.content-briefs.index`, `admin.prompt-modules.index`, and `admin.prompt-presets.index`.
- Produces: reusable Blade partial accepting `activeSection: string`.

- [ ] **Step 1: Write the failing navigation test**

```php
public function test_enabled_content_center_exposes_prompt_navigation(): void
{
    config()->set('geoflow.content_strategy_enabled', true);
    config()->set('geoflow.modular_prompts_enabled', true);
    config()->set('geoflow.generation_quality_gate_enabled', true);

    $this->actingAs($this->admin(), 'admin')
        ->get(route('admin.content-briefs.index'))
        ->assertOk()
        ->assertSee(route('admin.prompt-modules.index'), false)
        ->assertSee(route('admin.prompt-presets.index'), false);
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php artisan test tests/Feature/AdminContentCenterNavigationTest.php`
Expected: FAIL because the content brief page has no Prompt links.

- [ ] **Step 3: Add the secondary navigation and active route mappings**

```blade
@php($items = [
    'briefs' => ['route' => 'admin.content-briefs.index', 'label' => __('admin.content_center.briefs')],
    'modules' => ['route' => 'admin.prompt-modules.index', 'label' => __('admin.content_center.prompt_modules')],
    'presets' => ['route' => 'admin.prompt-presets.index', 'label' => __('admin.content_center.prompt_presets')],
])
<nav aria-label="{{ __('admin.content_center.title') }}" class="flex flex-wrap gap-2">
    @foreach($items as $key => $item)
        <a href="{{ route($item['route']) }}" @class(['rounded-md px-3 py-2 text-sm', 'bg-blue-600 text-white' => $activeSection === $key, 'bg-white text-gray-700 ring-1 ring-gray-200' => $activeSection !== $key])>{{ $item['label'] }}</a>
    @endforeach
</nav>
```

- [ ] **Step 4: Run navigation regressions**

Run: `php artisan test tests/Feature/AdminContentCenterNavigationTest.php tests/Feature/AdminPromptModulesTest.php tests/Feature/AdminContentBriefWorkflowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/partials/header.blade.php resources/views/admin/partials/content-center-nav.blade.php resources/views/admin/content-briefs/index.blade.php resources/views/admin/prompt-modules/index.blade.php resources/views/admin/prompt-presets/index.blade.php lang/en/admin.php lang/zh_CN/admin.php tests/Feature/AdminContentCenterNavigationTest.php
git commit -m "feat: expose content and prompt navigation"
```

### Task 2: Add Stable Asset Provenance and Configuration

**Files:**
- Create: `database/migrations/2026_08_11_000001_add_litb_content_asset_provenance.php`
- Modify: `app/Models/KnowledgeBase.php`
- Modify: `app/Models/KeywordLibrary.php`
- Modify: `app/Models/ContentBrief.php`
- Create: `config/litb_content_assets.php`
- Create: `app/Services/GeoFlow/LitbAssets/LitbAssetConfig.php`
- Test: `tests/Feature/LitbContentAssetSchemaTest.php`
- Test: `tests/Unit/LitbAssetConfigTest.php`

**Interfaces:**
- Produces: `LitbAssetConfig::fromConfig(): self`, `knowledgeRoot(): string`, `keywordRoot(): string`, `quarterKey(): string`, `archiveRoot(): string`.
- Produces nullable unique `asset_key` and JSONB `asset_provenance` on knowledge bases, keyword libraries, and content briefs.

- [ ] **Step 1: Write failing schema and config tests**

```php
$this->assertTrue(Schema::hasColumns('content_briefs', ['asset_key', 'asset_provenance']));
$config = LitbAssetConfig::fromConfig();
$this->assertSame('litb-quarterly-2026-q3', $config->quarterKey());
$this->assertDirectoryExists($config->knowledgeRoot());
```

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test tests/Feature/LitbContentAssetSchemaTest.php tests/Unit/LitbAssetConfigTest.php`
Expected: FAIL because fields and config object do not exist.

- [ ] **Step 3: Implement reversible provenance columns and validated config**

```php
$table->string('asset_key', 190)->nullable()->unique();
$table->jsonb('asset_provenance')->nullable();
```

```php
public static function fromConfig(): self
{
    return new self(
        knowledgeRoot: realpath((string) config('litb_content_assets.knowledge_root')) ?: throw new InvalidArgumentException('litb_assets.knowledge_root_missing'),
        keywordRoot: realpath((string) config('litb_content_assets.keyword_root')) ?: throw new InvalidArgumentException('litb_assets.keyword_root_missing'),
        quarterKey: (string) config('litb_content_assets.quarter_key'),
        archiveRoot: base_path((string) config('litb_content_assets.archive_root')),
    );
}
```

- [ ] **Step 4: Verify migration round-trip and tests**

Run: `php artisan migrate && php artisan test tests/Feature/LitbContentAssetSchemaTest.php tests/Unit/LitbAssetConfigTest.php`
Expected: PASS. Then run `php artisan migrate:rollback --step=1` and `php artisan migrate` in the test environment; both commands complete.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_11_000001_add_litb_content_asset_provenance.php app/Models/KnowledgeBase.php app/Models/KeywordLibrary.php app/Models/ContentBrief.php config/litb_content_assets.php app/Services/GeoFlow/LitbAssets/LitbAssetConfig.php tests/Feature/LitbContentAssetSchemaTest.php tests/Unit/LitbAssetConfigTest.php
git commit -m "feat: track LITB content asset provenance"
```

### Task 3: Scan and Classify Knowledge Sources

**Files:**
- Create: `app/Services/GeoFlow/LitbAssets/LitbSourceRecord.php`
- Create: `app/Services/GeoFlow/LitbAssets/LitbSourceScanner.php`
- Create: `app/Services/GeoFlow/LitbAssets/LitbAssetManifest.php`
- Test: `tests/Unit/LitbSourceScannerTest.php`

**Interfaces:**
- Produces: `LitbSourceScanner::scanKnowledge(string $root): array<LitbSourceRecord>`.
- Produces: `LitbSourceRecord::toArray(): array` with `source_key`, `relative_path`, `sha256`, `title`, `language_code`, `group_key`, `source_type`, `risk_level`, `review_status`, and `time_sensitive`.

- [ ] **Step 1: Write failing scanner tests using a fixture tree**

```php
$records = $scanner->scanKnowledge($fixtureRoot);
$this->assertSame('customer-service-policies', $records[0]->groupKey);
$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $records[0]->sha256);
$this->assertStringNotContainsString($fixtureRoot, $records[0]->relativePath);
```

- [ ] **Step 2: Run the scanner test and verify failure**

Run: `php artisan test tests/Unit/LitbSourceScannerTest.php`
Expected: FAIL because the scanner does not exist.

- [ ] **Step 3: Implement deterministic discovery and classification**

```php
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['md', 'txt', 'docx'], true)) {
        continue;
    }
    $relative = Str::after($file->getPathname(), rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    $records[] = LitbSourceRecord::fromPath($root, $relative, hash_file('sha256', $file->getPathname()));
}
usort($records, fn ($a, $b) => strcmp($a->sourceKey, $b->sourceKey));
```

- [ ] **Step 4: Run scanner tests and inspect the real source summary read-only**

Run: `php artisan test tests/Unit/LitbSourceScannerTest.php`
Expected: PASS. Run a dry-run command in Task 7 later; do not write into source directories.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/LitbAssets/LitbSourceRecord.php app/Services/GeoFlow/LitbAssets/LitbSourceScanner.php app/Services/GeoFlow/LitbAssets/LitbAssetManifest.php tests/Unit/LitbSourceScannerTest.php
git commit -m "feat: classify LITB knowledge sources"
```

### Task 4: Import Knowledge Bases Idempotently

**Files:**
- Create: `app/Services/GeoFlow/LitbAssets/LitbKnowledgeImporter.php`
- Test: `tests/Feature/LitbKnowledgeImporterTest.php`

**Interfaces:**
- Consumes: `KnowledgeSourceParser::normalizeKnowledgeText(string $text): string`, `KnowledgeSourceParser::extractDocxContent(string $absolutePath): string`, and `KnowledgeChunkSyncCoordinator::request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool`.
- Produces: `LitbKnowledgeImporter::import(array $records, bool $dispatchChunks): LitbAssetManifest`.

- [ ] **Step 1: Write failing idempotency and ownership tests**

```php
$first = $importer->import([$record], false);
$second = $importer->import([$record], false);
$this->assertSame(1, KnowledgeBase::query()->where('asset_key', $record->sourceKey)->count());
$this->assertSame(1, $first->createdCount());
$this->assertSame(1, $second->skippedCount());
```

Also create a row with the same name but null `asset_key` and assert it is reported as a conflict rather than overwritten.

- [ ] **Step 2: Run the importer test and verify failure**

Run: `php artisan test tests/Feature/LitbKnowledgeImporterTest.php`
Expected: FAIL because the importer does not exist.

- [ ] **Step 3: Implement transactional upsert by reserved asset key**

```php
DB::transaction(function () use ($record, $content, $manifest): void {
    $knowledge = KnowledgeBase::query()->where('asset_key', $record->sourceKey)->lockForUpdate()->first();
    if ($knowledge === null) {
        KnowledgeBase::query()->create($this->creationPayload($record, $content));
        $manifest->created($record->sourceKey);
        return;
    }
    if (($knowledge->asset_provenance['sha256'] ?? null) === $record->sha256) {
        $manifest->skipped($record->sourceKey);
        return;
    }
    $knowledge->update($this->revisionPayload($knowledge, $record, $content));
    $manifest->updated($record->sourceKey);
});
```

- [ ] **Step 4: Run importer and existing knowledge tests**

Run: `php artisan test tests/Feature/LitbKnowledgeImporterTest.php tests/Feature/AdminMaterialsPagesTest.php tests/Feature/KnowledgeChunkAsyncSyncTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/LitbAssets/LitbKnowledgeImporter.php tests/Feature/LitbKnowledgeImporterTest.php
git commit -m "feat: import governed LITB knowledge assets"
```

### Task 5: Adapt Directory and Workbook Keywords

**Files:**
- Create: `app/Services/GeoFlow/LitbAssets/LitbKeywordSourceReader.php`
- Create: `app/Services/GeoFlow/LitbAssets/LitbKeywordImporter.php`
- Modify: `config/litb_content_assets.php`
- Test: `tests/Unit/LitbKeywordSourceReaderTest.php`
- Test: `tests/Feature/LitbKeywordImporterTest.php`

**Interfaces:**
- Produces: `LitbKeywordSourceReader::read(string $path, array $sourceConfig): iterable<array<string,mixed>>`.
- Produces: `LitbKeywordImporter::import(iterable $rows): LitbAssetManifest`.
- Consumes: `KeywordNormalizer`, `KeywordClassifier`, and existing workbook reader/column mapper.

- [ ] **Step 1: Write failing TXT/JSON/XLSX compatibility tests**

```php
$rows = iterator_to_array($reader->read($fixture, ['language_code' => 'en', 'market_code' => 'GLOBAL']));
$this->assertSame('dresses for women', $rows[0]['normalized_keyword']);
$this->assertArrayHasKey('source_locator', $rows[0]);
$this->assertSame('category', $rows[0]['keyword_type']);
```

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test tests/Unit/LitbKeywordSourceReaderTest.php tests/Feature/LitbKeywordImporterTest.php`
Expected: FAIL because the adapters do not exist.

- [ ] **Step 3: Implement source adapters and conflict-safe import**

```php
$keyword = Keyword::query()->firstOrCreate(
    ['library_id' => $library->id, 'normalized_keyword' => $row['normalized_keyword'], 'language_code' => $row['language_code'], 'market_scope' => $row['market_scope']],
    $this->creationPayload($row),
);
$this->upsertMetricSnapshot($keyword, $row);
```

Before implementation, verify the actual database unique indexes and adapt the lookup to the migration-defined scope so raw duplicate `(library_id, keyword)` insertions are never attempted.

- [ ] **Step 4: Run keyword regression tests**

Run: `php artisan test tests/Unit/LitbKeywordSourceReaderTest.php tests/Feature/LitbKeywordImporterTest.php tests/Feature/LitbKeywordWorkbookCompatibilityTest.php tests/Feature/KeywordImportServiceTest.php tests/Feature/KeywordImportJobTest.php`
Expected: PASS, including repeated `dresses for women` rows.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/LitbAssets/LitbKeywordSourceReader.php app/Services/GeoFlow/LitbAssets/LitbKeywordImporter.php config/litb_content_assets.php tests/Unit/LitbKeywordSourceReaderTest.php tests/Feature/LitbKeywordImporterTest.php
git commit -m "feat: import LITB hierarchical keyword assets"
```

### Task 6: Seed the Production LITB Prompt Library

**Files:**
- Create: `database/seeders/LitbPromptLibrarySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/LitbPromptLibrarySeederTest.php`

**Interfaces:**
- Consumes: `PromptModuleService`, `PromptPresetService`, and mandatory module rules.
- Produces: 12 mandatory shared modules, scenario modules, and 11 published English production presets under reserved `litb.*` keys.

- [ ] **Step 1: Write failing count, composition, and rerun tests**

```php
$this->seed(LitbPromptLibrarySeeder::class);
$this->seed(LitbPromptLibrarySeeder::class);
$this->assertSame(1, PromptPreset::query()->where('preset_key', 'litb.geo_product_buying_guide.en')->count());
$preset = PromptPreset::query()->where('preset_key', 'litb.geo_product_buying_guide.en')->firstOrFail();
$this->assertGreaterThanOrEqual(12, $preset->currentVersion->moduleVersions()->count());
```

- [ ] **Step 2: Run the seeder test and verify failure**

Run: `php artisan test tests/Feature/LitbPromptLibrarySeederTest.php`
Expected: FAIL because the seeder does not exist.

- [ ] **Step 3: Implement system-owned immutable versions and presets**

Use explicit arrays containing the full approved module instruction, variable contract, output contract, parameters, priority, and matching rules. Every preset includes the mandatory modules exactly once plus its scenario module; publication uses the existing domain services rather than direct lifecycle updates.

```php
private const PRESETS = [
    'litb.geo_product_buying_guide.en' => ['content_type' => 'buying_guide', 'scenario_module' => 'litb.content.buying_guide'],
    'litb.geo_category_authority.en' => ['content_type' => 'category_guide', 'scenario_module' => 'litb.content.category_authority'],
    'litb.new_arrivals_weekly.en' => ['content_type' => 'new_arrivals_weekly', 'scenario_module' => 'litb.content.new_arrivals'],
];
```

The implementation array must include all 11 presets enumerated in the design, not only the three shown in the interface example.

- [ ] **Step 4: Run Prompt seed and composition regressions**

Run: `php artisan test tests/Feature/LitbPromptLibrarySeederTest.php tests/Feature/PromptCompositionSeederTest.php tests/Feature/AdminPromptModulesTest.php tests/Unit/PromptModuleResolverTest.php`
Expected: PASS and rerun does not advance current versions unnecessarily.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/LitbPromptLibrarySeeder.php database/seeders/DatabaseSeeder.php tests/Feature/LitbPromptLibrarySeederTest.php
git commit -m "feat: seed LITB production prompt library"
```

### Task 7: Build and Seed the Reviewed 90-Brief Catalog

**Files:**
- Create: `resources/content/litb-quarterly-2026-q3/brief-catalog.json`
- Create: `app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefImporter.php`
- Create: `database/seeders/LitbQuarterlyBriefSeeder.php`
- Test: `tests/Feature/LitbQuarterlyBriefSeederTest.php`

**Interfaces:**
- Produces: `LitbQuarterlyBriefImporter::import(string $catalogPath, ?int $adminId): LitbAssetManifest`.
- Consumes: imported keyword clusters, KnowledgeBase asset keys, and Prompt preset keys.

- [ ] **Step 1: Write failing quota and completeness tests**

```php
$catalog = json_decode(file_get_contents(resource_path('content/litb-quarterly-2026-q3/brief-catalog.json')), true, flags: JSON_THROW_ON_ERROR);
$this->assertCount(90, $catalog);
$this->assertSame(18, collect($catalog)->where('pillar', 'category_authority')->count());
$this->assertSame(12, collect($catalog)->where('pillar', 'product_comparison')->count());
$this->assertSame(8, collect($catalog)->where('pillar', 'faq')->count());
foreach ($catalog as $brief) {
    $this->assertNotEmpty($brief['asset_key']);
    $this->assertNotEmpty($brief['primary_keyword']);
    $this->assertNotEmpty($brief['geo_direct_answer']);
    $this->assertNotEmpty($brief['sections']);
    $this->assertNotEmpty($brief['evidence_group_keys']);
}
```

- [ ] **Step 2: Run the catalog test and verify failure**

Run: `php artisan test tests/Feature/LitbQuarterlyBriefSeederTest.php`
Expected: FAIL because the catalog/importer does not exist.

- [ ] **Step 3: Author the full 90-entry catalog from imported assets**

Each JSON object must include concrete values for `asset_key`, `pillar`, `topic`, `content_type`, `planned_publish_at`, `audience`, `core_question`, `business_goal`, `funnel_stage`, `primary_keyword`, `secondary_keywords`, `question_keywords`, `working_title`, `slug`, `seo_title`, `meta_description`, `geo_direct_answer`, `sections`, `faq_questions`, `evidence_group_keys`, `required_mentions`, `prohibited_information`, `internal_link_targets`, `store_landing_page_type`, `cta_strength`, `schema_types`, `refresh_days`, `risk_flags`, and `prompt_preset_key`.

- [ ] **Step 4: Implement idempotent brief, strategy, and blueprint creation**

```php
$brief = ContentBrief::query()->where('asset_key', $definition['asset_key'])->lockForUpdate()->first();
if ($brief === null) {
    $brief = ContentBrief::query()->create($this->briefPayload($definition, $adminId));
    $this->createConfirmedStrategyAndBlueprint($brief, $definition, $adminId);
    $manifest->created($definition['asset_key']);
} elseif (($brief->asset_provenance['definition_sha256'] ?? null) === $digest) {
    $manifest->skipped($definition['asset_key']);
} else {
    $manifest->conflict($definition['asset_key'], 'operator_or_catalog_change_requires_review');
}
```

- [ ] **Step 5: Run seeder twice and validate all associations**

Run: `php artisan test tests/Feature/LitbQuarterlyBriefSeederTest.php tests/Feature/AdminContentBriefWorkflowTest.php`
Expected: PASS; exactly 90 reserved briefs and one initial confirmed strategy/blueprint per brief after two runs.

- [ ] **Step 6: Commit**

```bash
git add resources/content/litb-quarterly-2026-q3/brief-catalog.json app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefImporter.php database/seeders/LitbQuarterlyBriefSeeder.php tests/Feature/LitbQuarterlyBriefSeederTest.php
git commit -m "feat: seed LITB 90-day content briefs"
```

### Task 8: Add Dry-Run and Phased Initialization Command

**Files:**
- Create: `app/Console/Commands/InitializeLitbContentAssetsCommand.php`
- No registration edit is required: Laravel auto-discovers command classes under `app/Console/Commands`.
- Test: `tests/Feature/InitializeLitbContentAssetsCommandTest.php`
- Create: `docs/litb-content-assets.md`

**Interfaces:**
- Produces command `geoflow:litb-assets:init {--phase=*} {--dry-run} {--no-chunks}`.
- Consumes all importers from Tasks 3–7 and writes JSON manifests beneath the configured archive root.

- [ ] **Step 1: Write failing dry-run and phase tests**

```php
$this->artisan('geoflow:litb-assets:init', ['--dry-run' => true, '--phase' => ['knowledge']])
    ->assertSuccessful();
$this->assertDatabaseCount('knowledge_bases', 0);
$this->assertFileExists($this->latestManifestPath());
```

- [ ] **Step 2: Run the command test and verify failure**

Run: `php artisan test tests/Feature/InitializeLitbContentAssetsCommandTest.php`
Expected: FAIL because the command is not registered.

- [ ] **Step 3: Implement orchestration, locking, manifest persistence, and exit codes**

```php
protected $signature = 'geoflow:litb-assets:init {--phase=*} {--dry-run} {--no-chunks}';

public function handle(LitbAssetConfig $config): int
{
    return Cache::lock('litb-assets:init:'.$config->quarterKey(), 3600)->get(
        fn (): int => $this->runSelectedPhases($config, (array) $this->option('phase'), (bool) $this->option('dry-run')),
    ) ?? self::FAILURE;
}
```

- [ ] **Step 4: Run focused and full regression suites**

Run: `php artisan test tests/Feature/InitializeLitbContentAssetsCommandTest.php tests/Feature/LitbKnowledgeImporterTest.php tests/Feature/LitbKeywordImporterTest.php tests/Feature/LitbPromptLibrarySeederTest.php tests/Feature/LitbQuarterlyBriefSeederTest.php`
Expected: PASS.

- [ ] **Step 5: Run real dry-run and inspect manifest totals**

Run: `docker compose exec -T app php artisan geoflow:litb-assets:init --dry-run`
Expected: successful manifest with readable source totals, 90 brief definitions, zero database writes, and no changes under the user source directories.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/InitializeLitbContentAssetsCommand.php docs/litb-content-assets.md tests/Feature/InitializeLitbContentAssetsCommandTest.php
git commit -m "feat: initialize LITB content assets safely"
```

### Task 9: Execute the Foundation Import and Verify the Admin UI

**Files:**
- Modify only generated ignored runtime manifests under `content/litb-quarterly-2026-q3/import-manifests/`
- Test: existing suites and read-only SQL/HTTP checks

**Interfaces:**
- Consumes: `geoflow:litb-assets:init`.
- Produces: populated local GEOFlow knowledge bases, keyword libraries, Prompt library, and 90 content briefs.

- [ ] **Step 1: Create a database backup before real import**

Run: `docker compose exec -T postgres pg_dump -U geoflow -d geo_flow -Fc -f /tmp/litb-before-quarterly-assets.dump`
Expected: exit code 0 and a non-empty file verified with `docker compose exec -T postgres test -s /tmp/litb-before-quarterly-assets.dump`.

- [ ] **Step 2: Run phases in dependency order**

Run: `docker compose exec -T app php artisan geoflow:litb-assets:init --phase=knowledge --phase=keywords --phase=prompts --phase=briefs`
Expected: exit code 0; manifest reports created/updated/skipped/conflict totals per phase.

- [ ] **Step 3: Allow chunk queues to drain and inspect failures**

Run: `docker compose ps && docker compose logs --tail=200 knowledge-queue queue`
Expected: workers running and no terminal failed job caused by the import. If an embedding provider is unavailable, knowledge rows remain saved with retryable chunk status.

- [ ] **Step 4: Verify database invariants**

Run: `docker compose exec -T app php artisan tinker --execute="dump(['briefs'=>App\\Models\\ContentBrief::where('asset_key','like','litb-quarterly-2026-q3.%')->count(),'presets'=>App\\Models\\PromptPreset::where('preset_key','like','litb.%')->count(),'knowledge'=>App\\Models\\KnowledgeBase::whereNotNull('asset_key')->count()]);"`
Expected: `briefs` equals 90, `presets` is at least 11, and governed knowledge count is greater than 0.

- [ ] **Step 5: Verify routes through authenticated feature tests**

Run: `docker compose exec -T app php artisan test tests/Feature/AdminContentCenterNavigationTest.php tests/Feature/AdminContentBriefWorkflowTest.php tests/Feature/AdminPromptModulesTest.php`
Expected: PASS.

- [ ] **Step 6: Record runtime handoff without committing source inputs**

Document manifest path, counts, conflicts, retryable chunk failures, backup location, admin URLs, and next content-production command in `docs/litb-content-assets.md`, then commit only the documentation if it changed.
