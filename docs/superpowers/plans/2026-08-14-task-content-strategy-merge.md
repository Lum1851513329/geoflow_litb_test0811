# Task / Content-Strategy Pipeline Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route `Task`'s "generate a new draft" step through the `ContentBrief` quality-gated engine (opt-in via a new `generation_engine` field), give that engine AI-phrased section headings and AI-crafted titles instead of templated ones, and add a spreadsheet-driven bulk way to create multi-site, quality-gated `Task`s.

**Architecture:** `DeterministicQualityGate` moves from literal-heading-text matching to position-based section matching, unlocking free-form AI headings. `PromptPresetVersion` gains a reusable `blueprint_template` (+ `default_brief_fields`) so a brief's Strategy/Blueprint can be auto-confirmed from a chosen Preset instead of hand-edited per topic (`ContentBriefQuickCreateService`, generalizing `LitbQuarterlyBriefImporter`'s existing `forceCreate`-with-`CONFIRMED` pattern). `Task` gains `generation_engine`/`prompt_preset_id`; when set to `quality_gated`, `WorkerExecutionService` creates a Brief and reserves a `ContentGenerationRun` instead of doing a single-shot AI call, and a new per-minute command auto-adopts `ready_for_review` runs under a dedicated system `Admin`. A spreadsheet importer groups rows by `(site, preset, category)` into new `Task`s that then run through this same machinery — no separate distribution path.

**Tech Stack:** Laravel 11 (PHP 8.3+), PostgreSQL (with SQLite test schema), `phpoffice/phpspreadsheet` (already a dependency) for the bulk-import reader/writer, Pest/PHPUnit for tests (this repo's existing tests are PHPUnit-style `TestCase` classes — follow that convention, not Pest syntax, matching every file read during planning).

## Global Constraints

- `generation_engine=legacy` Tasks and manually-created Briefs (today's flows) must keep working exactly as today — this is additive/opt-in, not a replacement.
- No change to `SemanticQualityReviewer`, `ArticleWorkflowTransitionService`, `DistributionOrchestrator`, or `docker-compose.yml`.
- The 4 generic (non-LITB) presets' stale mandatory-module bindings (`legacy_geo_trust_en`, `legacy_geo_ranking_en`, `legacy_geo_trust_zh`, `legacy_geo_ranking_zh` — each binds only 6 of the pre-existing 18 mandatory modules) are a known, separate, pre-existing bug. Do not fix it as a side effect. Task 6 below explicitly excludes these 4 from its backfill.
- Auto-adoption must use a dedicated system `Admin` actor (seeded in Task 10), never a real human admin's identity, so `adopted_by_admin_id` accurately distinguishes autonomous adoptions from human ones.
- Reuse `LitbQuarterlyBriefImporter`'s `forceCreate`-with-confirmed-status pattern (`app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefImporter.php:75-126`) rather than inventing a new one; reuse `ContentStrategyService::suggest()`'s baseline-strategy logic (`app/Domain/ContentStrategy/ContentStrategyService.php:29`) rather than duplicating it.
- Any new outbound HTTP call (reference-URL fetching, Task 11) must go through `App\Services\Outbound\SafeOutboundHttpClient`/`SafeOutboundRequest` — never a raw `Http::get()`. `ArticleContentGenerationService::generate()` calls the model via `$agent->prompt($prompt, [], $providerName, $modelId)` (`app/Services/GeoFlow/ArticleContentGenerationService.php:32`) with an empty tools array — the model has no browsing capability, so any reference material must be fetched by application code and supplied as evidence, never assumed fetched by the model itself.
- `show_references_section` is an explicit per-Preset editorial toggle (part of `blueprint_template`), never auto-inferred from content type.
- Every new nullable-column migration on an existing table follows this project's `Schema::hasColumn()` guard convention (see `database/migrations/2026_06_20_000000_add_distribution_strategy_to_tasks.php`).
- New JSON columns added to `prompt_preset_versions` (an immutable-snapshot table enforced by DB triggers) must be added to both the Postgres and SQLite immutability-guard trigger functions in the same migration, following the pattern in `database/migrations/2026_08_10_010320_add_prompt_module_snapshot_identity.php`.
- `ContentGenerationRequestService::reserve()` already accepts a plain `ai_model_id` argument (`app/Domain/ContentGeneration/ContentGenerationRequestService.php:39`) — it does not require interactive human model selection, so `Task.ai_model_id`/`model_selection_mode` pass straight through unchanged.
- `Task` routes (`routes/web.php:203-213`) are **not** behind the `geoflow.feature:*` middleware gate that `content-briefs`/`content-generations`/`prompt-presets` routes use — any `generation_engine=quality_gated` UI/behavior added to `TaskController` must independently check `config('geoflow.content_strategy_enabled')` etc. (Task 14), since simply adding the option to the Task form would otherwise let an operator pick a dead-end engine when the underlying pipeline is disabled.

## File Structure

New files this plan creates:

- `app/Domain/ContentGeneration/GeneratedArticleParser.php` — extracts the AI-written `# Title` line from generated Markdown.
- `app/Domain/ContentGeneration/ReferenceEvidenceFetcher.php` — fetches `reference_urls` via `SafeOutboundHttpClient` and returns fetched/failed results for evidence + citation rendering.
- `app/Domain/ContentStrategy/ContentBriefQuickCreateService.php` — the Component 3 "one call, fully confirmed" Brief+Strategy+Blueprint creation service; used by manual quick-create (Task 8) and Task-driven generation (Task 12) — bulk import (Tasks 15-17) reaches it only indirectly, by creating `Task`s that Task 12's code path later picks up.
- `app/Console/Commands/SeedTitleCraftingPromptModuleCommand.php`, `app/Console/Commands/SeedPresetBlueprintTemplatesCommand.php`, `app/Console/Commands/AdoptTaskDrivenGenerationRunsCommand.php`.
- `app/Domain/ContentGeneration/BulkContentImportService.php`, `app/Domain/ContentGeneration/BulkContentImportTemplateBuilder.php`, `app/Http/Controllers/Admin/BulkContentImportController.php`.
- Several migrations (named per task below).

Modified files (per task below): `app/Domain/ContentGeneration/DeterministicQualityGate.php`, `app/Models/{PromptPresetVersion,Task,ContentBrief,Title}.php`, `app/Domain/PromptComposition/PromptPresetService.php`, `app/Http/Requests/Admin/StoreContentBriefRequest.php`, `app/Http/Requests/Admin/StorePromptPresetRequest.php`, `app/Http/Controllers/Admin/{ContentBriefController,TaskController}.php`, `app/Services/GeoFlow/WorkerExecutionService.php`, `app/Policies/{ContentBriefPolicy,ContentGenerationRunPolicy}.php`, `routes/web.php`, `routes/console.php`, plus Blade views under `resources/views/admin/{prompt-presets,content-briefs,tasks}/`.

---

## Task 1: `DeterministicQualityGate` — position-based section matching

**Files:**

- Modify: `app/Domain/ContentGeneration/DeterministicQualityGate.php`
- Test: `tests/Unit/DeterministicQualityGateTest.php`

**Interfaces:**

- Consumes: nothing new (self-contained rewrite of existing `inspect()`/`parseMarkdown()`/`matchSectionKey()`).
- Produces: `QualityGateResult` now carries a `section_count_mismatch` issue in place of per-section `missing_section` issues when the found/expected section counts differ, and a new `missing_title_heading` issue when the Markdown doesn't open with a single H1. `parseMarkdown()`'s return array gains a `section_count_found: int` key; the `headings`/`paragraphs`/`section_content`/`section_markdown`/`body` keys are unchanged in shape.

This is the highest-risk task in the plan: it changes how every downstream quality check locates section content. Read `app/Domain/ContentGeneration/DeterministicQualityGate.php` in full before starting — the steps below replace specific methods, not the whole file.

- [ ] **Step 1: Update the three tests that assert exact-heading-text matching**

`test_gate_reports_each_blueprint_section_missing_from_markdown` currently asserts a single missing section produces one `missing_section` issue for that section's key. Under position-based matching, a single missing heading changes the found/expected *count*, so it now produces one `section_count_mismatch` issue instead. Replace it:

```php
public function test_gate_reports_a_section_count_mismatch_when_a_section_is_missing(): void
{
    $result = $this->gate->inspect("## Sizing\n".$this->words(80), $this->blueprint(), []);

    $issues = array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'section_count_mismatch');

    $this->assertFalse($result->passed);
    $this->assertCount(1, $issues);
    $this->assertSame(
        'Article has 1 top-level sections; blueprint requires 2.',
        array_values($issues)[0]['message'],
    );
}
```

(Check `$this->blueprint()`'s helper in the test file first — it must define exactly 2 sections with keys `sizing`/`care` for this message to read "requires 2"; if it defines a different count, use that count instead of `2` and update the message text to match. Do not guess — read the fixture before writing the assertion.)

`test_gate_does_not_match_a_heading_that_merely_contains_the_blueprint_word_mid_string` asserted that a heading like "Resizing Your Order" doesn't falsely match the "Sizing" blueprint section via substring containment — that whole *class* of behavior (text-based fuzzy matching) no longer exists under position-based matching, so this test is no longer meaningful and should be deleted, not adapted. Delete the whole method.

`test_gate_matches_a_heading_that_extends_the_blueprint_heading_with_extra_words` asserted a model-extended heading ("Sizing for Every Foot Shape") still matches. Under position-based matching this is trivially true for *any* heading text at all (position is what matters now, not text), so replace it with a test that actually exercises the new mechanism — headings with arbitrary, non-blueprint-derived text still zip correctly by position:

```php
public function test_gate_matches_sections_by_position_regardless_of_heading_wording(): void
{
    $markdown = '## How to Pick the Right Fit'."\n".$this->words(80)
        ."\n\n## Keeping Them Looking New\n".$this->words(80);

    $result = $this->gate->inspect($markdown, $this->blueprint(), []);

    $this->assertNotContains('section_count_mismatch', array_column($result->issuesAsArray(), 'code'));
    $this->assertNotContains('section_too_thin', array_column($result->issuesAsArray(), 'code'));
}
```

- [ ] **Step 2: Add a leading `# Title` line to every test fixture that feeds full articles through `inspect()`**

Component 1's title-crafting convention means real generated Markdown will always open with `# <title>`. Grep the test file for every call to `$this->gate->inspect(` whose Markdown argument represents a *complete* article (not a fragment testing one specific rejection) and prepend `"# A Great Title\n\n"` to the Markdown literal, e.g. `test_gate_passes_a_substantive_article_that_meets_the_blueprint`, `test_gate_enforces_prohibited_links_cta_and_market_constraints`, `test_gate_enforces_total_target_length_tolerance`, `test_gate_requires_reviewed_evidence_for_every_blueprint_fact`, and any other "happy path" test. Fragment-only tests that intentionally start with `## Sizing` to test one rejection (like the two above) do NOT need this — they're deliberately checking a specific heading-only scenario and adding an H1 would change what's being tested. Judgement call per test: if the test's Markdown reads as "here is what a full article looks like," add the title line; if it reads as "here is a minimal fragment to trigger one check," leave it as-is.

- [ ] **Step 3: Add a test for the new missing-title check**

```php
public function test_gate_flags_an_article_that_does_not_open_with_an_h1_title(): void
{
    $markdown = '## Sizing'."\n".$this->words(80)."\n\n## Care\n".$this->words(80);

    $result = $this->gate->inspect($markdown, $this->blueprint(), []);

    $this->assertContains('missing_title_heading', array_column($result->issuesAsArray(), 'code'));
}

public function test_gate_does_not_flag_missing_title_when_the_article_opens_with_an_h1(): void
{
    $markdown = "# A Great Title\n\n## Sizing\n".$this->words(80)."\n\n## Care\n".$this->words(80);

    $result = $this->gate->inspect($markdown, $this->blueprint(), []);

    $this->assertNotContains('missing_title_heading', array_column($result->issuesAsArray(), 'code'));
}
```

- [ ] **Step 4: Add tests for section-scoped checks being suppressed on a count mismatch, and global (non-section) checks staying active**

```php
public function test_section_scoped_question_coverage_is_suppressed_on_a_section_count_mismatch(): void
{
    // Only 1 heading found but the blueprint expects 2 — position zip is unreliable, so per-section
    // checks (thin/question/cta) are suppressed in favor of the single section_count_mismatch signal.
    $markdown = "# Title\n\n## Sizing\n".$this->words(80);

    $result = $this->gate->inspect($markdown, $this->blueprint(), []);

    $codes = array_column($result->issuesAsArray(), 'code');
    $this->assertContains('section_count_mismatch', $codes);
    $this->assertNotContains('section_too_thin', $codes);
    $this->assertNotContains('missing_question_coverage', $codes);
}

public function test_global_faq_question_coverage_still_checks_even_on_a_section_count_mismatch(): void
{
    $blueprint = $this->blueprint();
    $blueprint['faq_questions'] = ['What is the return window?'];
    $markdown = "# Title\n\n## Sizing\n".$this->words(80);

    $result = $this->gate->inspect($markdown, $blueprint, []);

    $issues = array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'missing_question_coverage');
    $this->assertNotEmpty($issues);
}
```

(Adjust `$blueprint['faq_questions']` assignment to match whatever the actual `blueprint()` fixture array shape is — read it first; if the helper returns a value object instead of a plain array, adapt accordingly, but the array shape confirmed via `BlueprintValidator`/`DeterministicQualityGate::questions()` is a plain associative array with a `sections` key, so `faq_questions` sits alongside it at the top level.)

- [ ] **Step 5: Run the test file to confirm it fails against the old implementation**

Run: `docker compose exec -T app php artisan test --filter=DeterministicQualityGateTest`
Expected: FAIL — the new/changed tests fail because `matchSectionKey()`-based matching and `missing_section` are still in place.

- [ ] **Step 6: Replace `parseMarkdown()` and remove `matchSectionKey()`**

Replace the entire `parseMarkdown()` method (originally `app/Domain/ContentGeneration/DeterministicQualityGate.php:274-349`) with:

```php
    /**
     * @param  list<array{key: string, heading: string, minimum_words: int, questions: list<string>}>  $sections
     * @return array{headings: list<array{level: int, text: string, section_key: string|null}>, paragraphs: list<array{text: string, section_key: string|null}>, section_content: array<string, string>, section_markdown: array<string, string>, body: string, section_count_found: int}
     */
    private function parseMarkdown(string $markdown, array $sections): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];

        $rawHeadings = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $rawHeadings[] = ['line' => $index, 'level' => strlen($matches[1]), 'text' => trim($matches[2])];
            }
        }

        // The model is instructed to open with a single H1 title (the title-crafting
        // module); that H1 is the article title, never a section boundary.
        $sectionCandidates = $rawHeadings;
        if ($sectionCandidates !== [] && $sectionCandidates[0]['level'] === 1) {
            array_shift($sectionCandidates);
        }

        $topLevel = null;
        foreach ($sectionCandidates as $heading) {
            if ($topLevel === null || $heading['level'] < $topLevel) {
                $topLevel = $heading['level'];
            }
        }

        $sectionHeadingLines = [];
        foreach ($sectionCandidates as $heading) {
            if ($heading['level'] === $topLevel) {
                $sectionHeadingLines[] = $heading['line'];
            }
        }

        // Zip section-level headings 1:1, in document order, against the blueprint's
        // sections, in blueprint order. A count mismatch is handled by the caller.
        $lineToSectionKey = [];
        $matchedCount = min(count($sectionHeadingLines), count($sections));
        for ($i = 0; $i < $matchedCount; $i++) {
            $lineToSectionKey[$sectionHeadingLines[$i]] = $sections[$i]['key'];
        }

        $headings = [];
        $paragraphs = [];
        $sectionContent = [];
        $sectionMarkdown = [];
        $bodyLines = [];
        $paragraphLines = [];
        $currentSectionKey = null;
        $currentSectionLevel = null;
        $flushParagraph = function () use (&$paragraphLines, &$paragraphs, &$currentSectionKey): void {
            $text = trim(implode(' ', $paragraphLines));
            $paragraphLines = [];
            if ($text !== '') {
                $paragraphs[] = ['text' => $text, 'section_key' => $currentSectionKey];
            }
        };

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $flushParagraph();
                $level = strlen($matches[1]);
                $text = trim($matches[2]);
                $sectionKey = $lineToSectionKey[$index] ?? null;
                $headings[] = ['level' => $level, 'text' => $text, 'section_key' => $sectionKey];

                if ($level === $topLevel) {
                    $currentSectionKey = $sectionKey;
                    $currentSectionLevel = $level;
                    if ($sectionKey !== null) {
                        $sectionContent[$sectionKey] ??= '';
                        $sectionMarkdown[$sectionKey] ??= '';
                    }
                } elseif ($currentSectionLevel !== null && $level <= $currentSectionLevel) {
                    $currentSectionKey = null;
                    $currentSectionLevel = null;
                }

                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();

                continue;
            }

            $cleanLine = $this->stripMarkdown($line);
            if ($currentSectionKey !== null) {
                $sectionMarkdown[$currentSectionKey] = trim(($sectionMarkdown[$currentSectionKey] ?? '')."\n".$line);
            }
            if ($cleanLine === '') {
                continue;
            }

            $bodyLines[] = $cleanLine;
            $paragraphLines[] = $cleanLine;
            if ($currentSectionKey !== null) {
                $sectionContent[$currentSectionKey] = trim(($sectionContent[$currentSectionKey] ?? '').' '.$cleanLine);
            }
        }

        $flushParagraph();

        return [
            'headings' => $headings,
            'paragraphs' => $paragraphs,
            'section_content' => $sectionContent,
            'section_markdown' => $sectionMarkdown,
            'body' => trim(implode(' ', $bodyLines)),
            'section_count_found' => count($sectionHeadingLines),
        ];
    }
```

Delete the `matchSectionKey()` method entirely (originally lines 813-843) — it is no longer called anywhere. Leave `normalize()` in place; it's still used by `duplicateParagraphs()`, `phraseOccurrences()`, `wordsIn()`, and `questionIsCovered()`.

- [ ] **Step 7: Update `inspect()`'s section-checking block**

Replace the per-section loop (originally lines 61-85) with:

```php
        if ($document['headings'] === [] || $document['headings'][0]['level'] !== 1) {
            $issues[] = new QualityIssue(
                code: 'missing_title_heading',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: 'The article does not open with a single H1 title heading.',
                repairInstruction: 'Add exactly one H1 heading as the very first line, containing the article title.',
            );
        }

        $expectedSectionCount = count($sections);
        $foundSectionCount = $document['section_count_found'];
        $sectionCountsMatch = $foundSectionCount === $expectedSectionCount;
        if (! $sectionCountsMatch) {
            $issues[] = new QualityIssue(
                code: 'section_count_mismatch',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: "Article has {$foundSectionCount} top-level sections; blueprint requires {$expectedSectionCount}.",
                repairInstruction: "Add or remove top-level sections so the article has exactly {$expectedSectionCount} sections, matching the blueprint order.",
            );
        } else {
            foreach ($sections as $section) {
                $sectionKey = $section['key'];
                $actualUnits = $this->contentUnitCount($document['section_content'][$sectionKey] ?? '', $language);
                if ($actualUnits < $section['minimum_words']) {
                    $issues[] = new QualityIssue(
                        code: 'section_too_thin',
                        severity: QualityIssue::SEVERITY_ERROR,
                        sectionKey: $sectionKey,
                        message: "Section \"{$section['heading']}\" has {$actualUnits} content units; it requires at least {$section['minimum_words']}.",
                        repairInstruction: "Expand \"{$section['heading']}\" with ".($section['minimum_words'] - $actualUnits).' more substantive content units that address its planned question.',
                    );
                }
            }
        }
```

This block must run *before* `$this->duplicateParagraphs(...)` in the method (i.e., insert it where the old per-section loop was, keeping everything above and below it — including the `unresolvedVariables` loop above it — unchanged).

Note `$language` is computed a few lines above this block already (`$language = $this->languageCode($blueprint, $requirements);`) — this rewrite depends on that existing local variable, do not move it.

- [ ] **Step 8: Update the question-coverage loop to suppress section-scoped questions on a mismatch**

Replace the questions loop (originally lines 125-138):

```php
        foreach ($this->questions($sections, $blueprint, $requirements) as $question) {
            if ($question['section_key'] !== null && ! $sectionCountsMatch) {
                continue;
            }
            $content = $question['section_key'] === null
                ? $document['body']
                : ($document['section_content'][$question['section_key']] ?? '');
            if (! $this->questionIsCovered($question['text'], $content)) {
                $issues[] = new QualityIssue(
                    code: 'missing_question_coverage',
                    severity: QualityIssue::SEVERITY_ERROR,
                    sectionKey: $question['section_key'],
                    message: "The planned question \"{$question['text']}\" is not covered.",
                    repairInstruction: "Answer the planned question: \"{$question['text']}\".",
                );
            }
        }
```

- [ ] **Step 9: Gate the CTA loop behind the same `$sectionCountsMatch` flag**

Wrap the existing CTA loop (originally lines 199-209) in `if ($sectionCountsMatch) { ... }` — the loop body itself is unchanged:

```php
        if ($sectionCountsMatch) {
            foreach ($sections as $section) {
                if (! $section['cta_allowed'] && $this->containsCallToAction($document['section_markdown'][$section['key']] ?? '')) {
                    $issues[] = new QualityIssue(
                        code: 'cta_not_allowed',
                        severity: QualityIssue::SEVERITY_ERROR,
                        sectionKey: $section['key'],
                        message: "Section \"{$section['heading']}\" contains a call to action that its blueprint forbids.",
                        repairInstruction: "Remove promotional calls to action from \"{$section['heading']}\".",
                    );
                }
            }
        }
```

Do **not** wrap the `required_fact_uncovered` loop (originally lines 211-224) — it checks blueprint-declared required facts against `$requirements['evidence_fact_ids']` (evidence availability), not against parsed Markdown content, so it is completely unaffected by section matching and must stay unconditional exactly as it is today.

- [ ] **Step 10: Run the full test file**

Run: `docker compose exec -T app php artisan test --filter=DeterministicQualityGateTest`
Expected: PASS — all existing + new tests green.

- [ ] **Step 11: Run the broader content-generation regression suite**

Run: `docker compose exec -T app php artisan test --filter=ContentGenerationCoordinatorTest`
Expected: PASS unchanged — this suite uses `FakeArticleContentGenerationService` with fixed Markdown fixtures; if any fixture's substantive-article Markdown doesn't open with an H1, this will now fail on the new `missing_title_heading` check. If it fails, find the fixture method (e.g. `substantiveArticle()`) in `tests/Feature/ContentGenerationCoordinatorTest.php` and prepend `# <a title>\n\n` to it — do not weaken the new check to make old fixtures pass.

- [ ] **Step 12: Commit**

```bash
git add app/Domain/ContentGeneration/DeterministicQualityGate.php tests/Unit/DeterministicQualityGateTest.php tests/Feature/ContentGenerationCoordinatorTest.php
git commit -m "feat: match blueprint sections by position, not literal heading text"
```

---

## Task 2: `GeneratedArticleParser` — extract the AI-written title

**Files:**

- Create: `app/Domain/ContentGeneration/GeneratedArticleParser.php`
- Test: `tests/Unit/GeneratedArticleParserTest.php`

**Interfaces:**

- Produces: `GeneratedArticleParser::extractTitle(string $markdown): array{title: ?string, content: string}` — consumed by Task 13 (auto-adopt command).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\GeneratedArticleParser;
use PHPUnit\Framework\TestCase;

final class GeneratedArticleParserTest extends TestCase
{
    private GeneratedArticleParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new GeneratedArticleParser;
    }

    public function test_extracts_a_leading_h1_title_and_strips_it_from_the_content(): void
    {
        $result = $this->parser->extractTitle("# How to Choose Running Shoes\n\n## Sizing\nBody text.");

        $this->assertSame('How to Choose Running Shoes', $result['title']);
        $this->assertSame("## Sizing\nBody text.", $result['content']);
    }

    public function test_returns_null_title_when_there_is_no_leading_h1(): void
    {
        $markdown = "## Sizing\nBody text.";

        $result = $this->parser->extractTitle($markdown);

        $this->assertNull($result['title']);
        $this->assertSame($markdown, $result['content']);
    }

    public function test_does_not_match_a_leading_h2_as_a_title(): void
    {
        $markdown = "## Not a title\nBody text.";

        $result = $this->parser->extractTitle($markdown);

        $this->assertNull($result['title']);
    }

    public function test_trims_trailing_hashes_and_whitespace_from_the_title(): void
    {
        $result = $this->parser->extractTitle("#   How to Choose Running Shoes   #\n\nBody.");

        $this->assertSame('How to Choose Running Shoes', $result['title']);
    }

    public function test_returns_null_title_for_an_empty_h1(): void
    {
        $markdown = "# \n\nBody.";

        $result = $this->parser->extractTitle($markdown);

        $this->assertNull($result['title']);
        $this->assertSame($markdown, $result['content']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=GeneratedArticleParserTest`
Expected: FAIL with "Class \"App\Domain\ContentGeneration\GeneratedArticleParser\" not found"

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Domain\ContentGeneration;

final class GeneratedArticleParser
{
    /**
     * @return array{title: ?string, content: string}
     */
    public function extractTitle(string $markdown): array
    {
        if (preg_match('/^\s*#(?!#)\s*(.*?)\s*#*\s*(?:\r?\n|$)/u', $markdown, $matches) !== 1) {
            return ['title' => null, 'content' => $markdown];
        }

        $title = trim($matches[1]);
        if ($title === '') {
            return ['title' => null, 'content' => $markdown];
        }

        $content = trim((string) preg_replace('/^\s*#(?!#)\s*.*?\s*#*\s*(?:\r?\n)+/u', '', $markdown, 1));

        return ['title' => $title, 'content' => $content];
    }
}
```

The `#(?!#)` negative lookahead ensures only a single `#` (H1) matches, never `##` (H2) — this is what makes `test_does_not_match_a_leading_h2_as_a_title` pass, since `preg_match` on `"## Not a title..."` fails the lookahead at the second `#`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=GeneratedArticleParserTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ContentGeneration/GeneratedArticleParser.php tests/Unit/GeneratedArticleParserTest.php
git commit -m "feat: add GeneratedArticleParser to extract AI-written titles from generated Markdown"
```

---

## Task 3: `blueprint_template` + `default_brief_fields` on `PromptPresetVersion`

**Files:**

- Create: `database/migrations/2026_08_15_000000_add_blueprint_template_to_prompt_preset_versions.php`
- Modify: `app/Models/PromptPresetVersion.php`
- Test: `tests/Feature/PromptPresetBlueprintTemplateMigrationTest.php`

**Interfaces:**

- Produces: `prompt_preset_versions.blueprint_template` (nullable jsonb, shape `{"target_length": int, "sections": [{"key": string, "heading": string, "purpose": string, "questions_to_answer": string[], "minimum_words": int, "cta_allowed": bool, "required_fact_ids": string[]}], "required_entities": string[], "show_references_section": bool}`) and `prompt_preset_versions.default_brief_fields` (nullable jsonb, shape `{"market_code": string, "language_code": string, "audience": string, "business_goal": string, "content_type": string, "cta_strength": string}`) — both accessible as PHP arrays via `PromptPresetVersion::$blueprint_template`/`$default_brief_fields` casts, consumed by Task 4 (admin editing), Task 6 (backfill), Task 7 (`ContentBriefQuickCreateService`).

Read `database/migrations/2026_08_10_010320_add_prompt_module_snapshot_identity.php` in full before starting — it is the template for adding a column to an immutable-snapshot table and amending its guard triggers. Also read `database/migrations/2026_08_10_010100_create_prompt_composition_tables.php` in full to see the current trigger function bodies you are amending (search it for `guard_prompt_preset_version_immutable`).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prompt_preset_versions')) {
            return;
        }

        if (! Schema::hasColumn('prompt_preset_versions', 'blueprint_template')) {
            Schema::table('prompt_preset_versions', function (Blueprint $table): void {
                $table->jsonb('blueprint_template')->nullable()->after('conflict_policy');
            });
        }
        if (! Schema::hasColumn('prompt_preset_versions', 'default_brief_fields')) {
            Schema::table('prompt_preset_versions', function (Blueprint $table): void {
                $table->jsonb('default_brief_fields')->nullable()->after('blueprint_template');
            });
        }

        $this->recreateGuardTriggers();
    }

    public function down(): void
    {
        if (! Schema::hasTable('prompt_preset_versions')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update');
        }

        Schema::table('prompt_preset_versions', function (Blueprint $table): void {
            if (Schema::hasColumn('prompt_preset_versions', 'default_brief_fields')) {
                $table->dropColumn('default_brief_fields');
            }
            if (Schema::hasColumn('prompt_preset_versions', 'blueprint_template')) {
                $table->dropColumn('blueprint_template');
            }
        });

        $this->recreateGuardTriggersWithoutNewColumns();
    }

    private function recreateGuardTriggers(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION guard_prompt_preset_version_immutable() RETURNS trigger AS $$
                BEGIN
                    IF NEW.prompt_preset_id IS DISTINCT FROM OLD.prompt_preset_id
                        OR NEW.version IS DISTINCT FROM OLD.version
                        OR NEW.matching_rules IS DISTINCT FROM OLD.matching_rules
                        OR NEW.generation_policy IS DISTINCT FROM OLD.generation_policy
                        OR NEW.conflict_policy IS DISTINCT FROM OLD.conflict_policy
                        OR NEW.blueprint_template IS DISTINCT FROM OLD.blueprint_template
                        OR NEW.default_brief_fields IS DISTINCT FROM OLD.default_brief_fields
                        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
                        RAISE EXCEPTION 'prompt_preset_versions rows are immutable except lifecycle_status/change_note/published_at';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('
                CREATE TRIGGER prompt_preset_versions_guard_update
                BEFORE UPDATE ON prompt_preset_versions
                FOR EACH ROW EXECUTE FUNCTION guard_prompt_preset_version_immutable();
            ');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER prompt_preset_versions_guard_update
                BEFORE UPDATE ON prompt_preset_versions
                WHEN NEW.prompt_preset_id IS NOT OLD.prompt_preset_id
                    OR NEW.version IS NOT OLD.version
                    OR NEW.matching_rules IS NOT OLD.matching_rules
                    OR NEW.generation_policy IS NOT OLD.generation_policy
                    OR NEW.conflict_policy IS NOT OLD.conflict_policy
                    OR NEW.blueprint_template IS NOT OLD.blueprint_template
                    OR NEW.default_brief_fields IS NOT OLD.default_brief_fields
                    OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
                BEGIN
                    SELECT RAISE(ABORT, 'prompt_preset_versions rows are immutable except lifecycle_status/change_note/published_at');
                END;
            SQL);
        }
    }

    private function recreateGuardTriggersWithoutNewColumns(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION guard_prompt_preset_version_immutable() RETURNS trigger AS $$
                BEGIN
                    IF NEW.prompt_preset_id IS DISTINCT FROM OLD.prompt_preset_id
                        OR NEW.version IS DISTINCT FROM OLD.version
                        OR NEW.matching_rules IS DISTINCT FROM OLD.matching_rules
                        OR NEW.generation_policy IS DISTINCT FROM OLD.generation_policy
                        OR NEW.conflict_policy IS DISTINCT FROM OLD.conflict_policy
                        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
                        RAISE EXCEPTION 'prompt_preset_versions rows are immutable except lifecycle_status/change_note/published_at';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('
                CREATE TRIGGER prompt_preset_versions_guard_update
                BEFORE UPDATE ON prompt_preset_versions
                FOR EACH ROW EXECUTE FUNCTION guard_prompt_preset_version_immutable();
            ');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER prompt_preset_versions_guard_update
                BEFORE UPDATE ON prompt_preset_versions
                WHEN NEW.prompt_preset_id IS NOT OLD.prompt_preset_id
                    OR NEW.version IS NOT OLD.version
                    OR NEW.matching_rules IS NOT OLD.matching_rules
                    OR NEW.generation_policy IS NOT OLD.generation_policy
                    OR NEW.conflict_policy IS NOT OLD.conflict_policy
                    OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
                BEGIN
                    SELECT RAISE(ABORT, 'prompt_preset_versions rows are immutable except lifecycle_status/change_note/published_at');
                END;
            SQL);
        }
    }
};
```

**Before writing the exact `CREATE OR REPLACE FUNCTION`/`CREATE TRIGGER` bodies above verbatim**, open `database/migrations/2026_08_10_010100_create_prompt_composition_tables.php` and `database/migrations/2026_08_10_010320_add_prompt_module_snapshot_identity.php` and diff the *real* trigger source against what's written here — the field list guarded (`prompt_preset_id, version, matching_rules, generation_policy, conflict_policy, created_by_admin_id`) and the exact `RAISE EXCEPTION`/`RAISE(ABORT, ...)` message text must match the existing function's actual current text with only the two new `OR NEW.x IS DISTINCT FROM OLD.x` clauses added — do not invent wording that differs from the real trigger.

- [ ] **Step 2: Add casts to the model**

In `app/Models/PromptPresetVersion.php`, add two entries to the `casts()` array (after `'conflict_policy' => 'array',`):

```php
            'blueprint_template' => 'array',
            'default_brief_fields' => 'array',
```

- [ ] **Step 3: Write a migration/model test**

```php
<?php

namespace Tests\Feature;

use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptPresetBlueprintTemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_template_and_default_brief_fields_are_nullable_and_cast_to_array(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.blueprint-template-migration',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
        ]);

        $this->assertNull($version->blueprint_template);
        $this->assertNull($version->default_brief_fields);

        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 2,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'default_brief_fields' => ['market_code' => 'US'],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
        ]);
        $version->refresh();

        $this->assertSame(['target_length' => 1800, 'sections' => []], $version->blueprint_template);
        $this->assertSame(['market_code' => 'US'], $version->default_brief_fields);
    }

    public function test_blueprint_template_cannot_be_changed_after_insert(): void
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.blueprint-template-immutable',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $version->forceFill(['blueprint_template' => ['target_length' => 2000, 'sections' => []]])->save();
    }
}
```

- [ ] **Step 4: Run migrations and the test**

Run: `docker compose exec -T app php artisan migrate --env=testing` (or however this repo's test suite refreshes schema — check `phpunit.xml`/`tests/TestCase.php` for the DB connection used; `RefreshDatabase` will run migrations automatically for a fresh SQLite/Postgres test DB, so simply running the test is normally sufficient)
Run: `docker compose exec -T app php artisan test --filter=PromptPresetBlueprintTemplateMigrationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_15_000000_add_blueprint_template_to_prompt_preset_versions.php app/Models/PromptPresetVersion.php tests/Feature/PromptPresetBlueprintTemplateMigrationTest.php
git commit -m "feat: add blueprint_template and default_brief_fields columns to prompt_preset_versions"
```

---

## Task 4: Admin editing of `blueprint_template`/`default_brief_fields`

**Files:**

- Modify: `app/Domain/PromptComposition/PromptPresetService.php`, `app/Http/Requests/Admin/StorePromptPresetRequest.php`, `resources/views/admin/prompt-presets/index.blade.php`, `resources/views/admin/prompt-presets/edit.blade.php`
- Test: `tests/Feature/PromptPresetControllerBlueprintTemplateTest.php`

**Interfaces:**

- Consumes: `PromptPresetVersion.blueprint_template`/`default_brief_fields` (Task 3).
- Produces: `PromptPresetService::createVersion()`/`createPreset()` now persist `blueprint_template`/`default_brief_fields` when present in the `$data`/`$version` array passed in — consumed by Task 6 (backfill command calls this same service).

Read `app/Domain/PromptComposition/PromptPresetService.php` in full (239 lines) before starting, specifically `createVersion()` and the private `createVersionForLockedPreset()` it delegates to (around line 137) — you are adding two fields to the `forceCreate()` array there, not changing the method's public signature.

- [ ] **Step 1: Extend `PromptPresetService`**

In the private method that builds the `PromptPresetVersion::forceCreate([...])` call (`createVersionForLockedPreset()`), add two lines to the array literal, immediately after `'conflict_policy' => $data['conflict_policy'] ?? null,`:

```php
            'blueprint_template' => $data['blueprint_template'] ?? null,
            'default_brief_fields' => $data['default_brief_fields'] ?? null,
```

No other method in this file needs to change — `createPreset()` and `createVersion()` both funnel through this same private method, so both callers gain the new fields automatically as long as the caller's `$data` array includes them.

- [ ] **Step 2: Extend `StorePromptPresetRequest`**

Find the `prepareForValidation()` method's loop that JSON-decodes textarea fields (`foreach (['matching_rules', 'generation_policy', 'conflict_policy'] as $field) { ... }`). Add `'blueprint_template'` and `'default_brief_fields'` to that array literal.

Add these rules to `rules()`, immediately after the existing `'conflict_policy' => ['nullable', 'array'],` line:

```php
            'blueprint_template' => ['nullable', 'array'],
            'blueprint_template.target_length' => ['required_with:blueprint_template', 'integer', 'min:1'],
            'blueprint_template.sections' => ['required_with:blueprint_template', 'array', 'min:1'],
            'blueprint_template.sections.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]*$/', 'distinct'],
            'blueprint_template.sections.*.heading' => ['required', 'string', 'max:200'],
            'blueprint_template.sections.*.purpose' => ['required', 'string', 'max:500'],
            'blueprint_template.sections.*.questions_to_answer' => ['required', 'array', 'min:1'],
            'blueprint_template.sections.*.questions_to_answer.*' => ['required', 'string'],
            'blueprint_template.sections.*.minimum_words' => ['required', 'integer', 'min:1'],
            'blueprint_template.sections.*.cta_allowed' => ['required', 'boolean'],
            'blueprint_template.sections.*.required_fact_ids' => ['nullable', 'array'],
            'blueprint_template.sections.*.required_fact_ids.*' => ['string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'blueprint_template.required_entities' => ['nullable', 'array'],
            'blueprint_template.required_entities.*' => ['string'],
            'blueprint_template.show_references_section' => ['nullable', 'boolean'],
            'default_brief_fields' => ['nullable', 'array'],
            'default_brief_fields.market_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
            'default_brief_fields.language_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/'],
            'default_brief_fields.audience' => ['nullable', 'string', 'max:500'],
            'default_brief_fields.business_goal' => ['nullable', 'string', 'max:500'],
            'default_brief_fields.content_type' => ['nullable', 'string', 'max:50'],
            'default_brief_fields.cta_strength' => ['nullable', 'string', 'max:30'],
```

- [ ] **Step 3: Add textareas to the two Blade views**

In `resources/views/admin/prompt-presets/index.blade.php` (the inline create form) and `resources/views/admin/prompt-presets/edit.blade.php` (the version-create form), find the existing `<textarea name="conflict_policy" ...>` block in each file and add two more textareas immediately after it, following the exact same markup pattern (same classes, same `old()` fallback convention):

```blade
<div>
    <label class="block text-sm font-medium text-gray-700">Blueprint template (JSON, optional)</label>
    <textarea name="blueprint_template" rows="16" spellcheck="false" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs leading-5">{{ old('blueprint_template') }}</textarea>
</div>
<div>
    <label class="block text-sm font-medium text-gray-700">Default brief fields (JSON, optional)</label>
    <textarea name="default_brief_fields" rows="6" spellcheck="false" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs leading-5">{{ old('default_brief_fields') }}</textarea>
</div>
```

(Match the actual surrounding markup structure — read the two files first; if the existing fields are wrapped in a different container element than a bare `<div>`, e.g. inside a `<div class="space-y-4">` or a grid cell, use that same wrapper, not a generic `<div>`.)

- [ ] **Step 4: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptPresetControllerBlueprintTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_storing_a_preset_persists_blueprint_template_and_default_brief_fields(): void
    {
        $admin = Admin::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $module = PromptModule::query()->create([
            'module_key' => 'test.module.'.uniqid(),
            'display_name' => 'Test module',
            'module_type' => PromptModule::TYPE_FOUNDATION,
            'is_system' => false,
            'is_mandatory' => false,
            'default_priority' => 0,
            'is_enabled' => true,
        ]);
        $moduleVersion = PromptModuleVersion::query()->forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'Test content.',
            'variable_contract' => [],
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);
        $module->update(['current_version_id' => $moduleVersion->id]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.prompt-presets.store'), [
            'preset_key' => 'test.blueprint-template-form',
            'display_name' => 'Test preset',
            'matching_rules' => '{}',
            'generation_policy' => '{}',
            'blueprint_template' => json_encode([
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'intro',
                    'heading' => 'Overview',
                    'purpose' => 'Summarize the answer',
                    'questions_to_answer' => ['What is this about?'],
                    'minimum_words' => 100,
                    'cta_allowed' => false,
                    'required_fact_ids' => [],
                ]],
            ]),
            'default_brief_fields' => json_encode(['market_code' => 'US', 'language_code' => 'en']),
            'module_versions' => [
                ['prompt_module_version_id' => $moduleVersion->id, 'sort_order' => 1],
            ],
        ]);

        $response->assertRedirect();
        $preset = \App\Models\PromptPreset::query()->where('preset_key', 'test.blueprint-template-form')->firstOrFail();
        $version = $preset->currentVersion;
        $this->assertSame(1800, $version->blueprint_template['target_length']);
        $this->assertSame('US', $version->default_brief_fields['market_code']);
    }
}
```

(Check whether an `Admin` model factory exists at `database/factories/AdminFactory.php` before assuming `Admin::factory()` works — if it doesn't exist, create the admin directly via `Admin::query()->create([...])` with a hashed password, matching whatever fields other admin-authenticated feature tests in this codebase already use; grep `tests/Feature` for `actingAs($admin, 'admin')` and copy the setup pattern from an existing test in the same directory, e.g. `PromptPresetController`-adjacent tests if any exist.)

- [ ] **Step 5: Run the test**

Run: `docker compose exec -T app php artisan test --filter=PromptPresetControllerBlueprintTemplateTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Domain/PromptComposition/PromptPresetService.php app/Http/Requests/Admin/StorePromptPresetRequest.php resources/views/admin/prompt-presets/index.blade.php resources/views/admin/prompt-presets/edit.blade.php tests/Feature/PromptPresetControllerBlueprintTemplateTest.php
git commit -m "feat: let admins edit blueprint_template and default_brief_fields on prompt presets"
```

---

## Task 5: Seed the `title_crafting` module and updated `seo_structure` guidance

**Files:**

- Create: `app/Console/Commands/SeedTitleCraftingPromptModuleCommand.php`
- Test: `tests/Feature/SeedTitleCraftingPromptModuleCommandTest.php`

**Interfaces:**

- Consumes: `PromptModuleService::createModule()`/`createVersion()`/`publish()` (existing, `app/Domain/PromptComposition/PromptModuleService.php:17,46,87`).
- Produces: a `title_crafting` `PromptModule` row (`is_system=true, is_mandatory=true, applicability=null, module_type=PromptModule::TYPE_OUTPUT_FORMAT`) with one `production` `PromptModuleVersion`, and a new `production` `PromptModuleVersion` for the existing `seo_structure` module — both consumed by Task 6 (which binds `title_crafting` into every non-generic preset's next version).

Read `database/seeders/PromptCompositionSeeder.php` in full first — this command follows the exact same module-content conventions (plain instructional English text, no `{{variables}}`) but runs as an idempotent Artisan command (safe to re-run), not a one-shot seeder, since it must run against an environment that already has real preset/module data.

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\PromptComposition\PromptModuleService;
use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use Illuminate\Console\Command;
use RuntimeException;

final class SeedTitleCraftingPromptModuleCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:seed-title-crafting-module';

    protected $description = 'Create the title_crafting mandatory prompt module and publish updated seo_structure guidance';

    private const TITLE_CRAFTING_CONTENT = <<<'PROMPT'
Write the article title as the very first line of your output, formatted as a single Markdown H1 ("# Title text"), before any other content. The brief's topic is a directional input describing what to write about — it is not the title; do not copy it verbatim.

Write a genuine headline using real headline-writing practice: lead with the concrete benefit, answer, or specific subject rather than a generic label ("A Guide to X" is not acceptable). Name the actual product, category, or comparison involved. Match the register the content calls for: a news headline states what happened; a buying-guide or how-to headline states the decision or outcome the reader gets; an FAQ headline poses or answers the actual question. Keep the title to roughly 50-60 characters where the content allows it, without sacrificing clarity for length. Do not use clickbait phrasing or unverifiable superlatives.
PROMPT;

    private const SEO_STRUCTURE_ADDITION = "\n\nEach blueprint section's \"heading\" field is a role label describing that section's purpose, not literal text to reproduce. Write a natural, specific H2 for every section, grounded in its stated purpose and the article's actual topic. Do not reuse a section's heading label verbatim, and do not reuse the same generic heading wording across different articles.";

    public function handle(PromptModuleService $modules): int
    {
        $actor = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $actor) {
            $this->error('Run "geoflow:content-strategy:seed-system-admin" first (Task 10) — no geoflow-system admin found.');

            return self::FAILURE;
        }

        $this->seedTitleCraftingModule($modules, $actor);
        $this->publishSeoStructureAddition($modules, $actor);

        return self::SUCCESS;
    }

    private function seedTitleCraftingModule(PromptModuleService $modules, Admin $actor): void
    {
        $existing = PromptModule::query()->where('module_key', 'title_crafting')->first();
        if ($existing !== null) {
            $this->info('title_crafting module already exists, skipping creation.');

            return;
        }

        $module = $modules->createModule([
            'module_key' => 'title_crafting',
            'display_name' => 'Title crafting',
            'module_type' => PromptModule::TYPE_OUTPUT_FORMAT,
            'description' => 'Instructs the model to write a genuine, headline-quality article title instead of reusing the brief topic verbatim.',
            'is_system' => true,
            'applicability' => null,
            'is_mandatory' => true,
            'default_priority' => 150,
            'is_enabled' => true,
        ], [
            'content' => self::TITLE_CRAFTING_CONTENT,
            'variable_contract' => [],
        ], $actor);

        $version = $module->currentVersion()->firstOrFail();
        $modules->publish($version, $actor, 'Initial publish of the title_crafting mandatory module.');
        $this->info('Created and published title_crafting module version '.$version->id);
    }

    private function publishSeoStructureAddition(PromptModuleService $modules, Admin $actor): void
    {
        $module = PromptModule::query()->where('module_key', 'seo_structure')->first();
        if (! $module) {
            throw new RuntimeException('seo_structure module not found — is PromptCompositionSeeder seeded in this environment?');
        }

        $current = $module->currentVersion;
        if ($current !== null && str_contains((string) $current->content, "role label describing that section's purpose")) {
            $this->info('seo_structure already has heading-guidance addition, skipping.');

            return;
        }

        $newContent = trim((string) $current?->content).self::SEO_STRUCTURE_ADDITION;
        $version = $modules->createVersion($module, [
            'content' => $newContent,
            'variable_contract' => [],
        ], $actor);
        $modules->publish($version, $actor, 'Add AI-phrased heading guidance so headings are not copied verbatim from blueprint labels.');
        $this->info('Published updated seo_structure module version '.$version->id);
    }
}
```

- [ ] **Step 2: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use Database\Seeders\PromptCompositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedTitleCraftingPromptModuleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_title_crafting_module_and_updates_seo_structure(): void
    {
        Admin::query()->create([
            'username' => 'geoflow-system',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);

        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $titleCrafting = PromptModule::query()->where('module_key', 'title_crafting')->firstOrFail();
        $this->assertTrue((bool) $titleCrafting->is_mandatory);
        $this->assertNull($titleCrafting->applicability);
        $version = $titleCrafting->currentVersion;
        $this->assertSame(PromptModuleVersion::STATUS_PRODUCTION, $version->lifecycle_status);
        $this->assertStringContainsString('# Title text', $version->content);

        $seoStructure = PromptModule::query()->where('module_key', 'seo_structure')->firstOrFail();
        $this->assertStringContainsString("role label describing that section's purpose", $seoStructure->currentVersion->content);
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        Admin::query()->create([
            'username' => 'geoflow-system',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);

        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $this->assertSame(1, PromptModule::query()->where('module_key', 'title_crafting')->count());
        $seoStructure = PromptModule::query()->where('module_key', 'seo_structure')->firstOrFail();
        $this->assertSame(2, $seoStructure->versions()->count());
    }
}
```

(This test's `Admin::create([...])` inline setup deliberately does **not** wait for Task 10 — Task 10 seeds the *real* system admin via its own migration/command for production use, but this test only needs a row matching the `username='geoflow-system'` lookup the command itself performs, so it creates one directly. If `Admin`'s migration requires additional NOT NULL columns beyond what's shown, add them per the actual schema.)

- [ ] **Step 3: Run the test**

Run: `docker compose exec -T app php artisan test --filter=SeedTitleCraftingPromptModuleCommandTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/SeedTitleCraftingPromptModuleCommand.php tests/Feature/SeedTitleCraftingPromptModuleCommandTest.php
git commit -m "feat: seed title_crafting mandatory module and updated seo_structure heading guidance"
```

---

## Task 6: Backfill preset versions — bind `title_crafting`, set `blueprint_template`

**Files:**

- Create: `app/Console/Commands/SeedPresetBlueprintTemplatesCommand.php`
- Test: `tests/Feature/SeedPresetBlueprintTemplatesCommandTest.php`

**Interfaces:**

- Consumes: `title_crafting` module (Task 5), `PromptPresetService::createVersion()`/`publish()` (existing + Task 4's `blueprint_template`/`default_brief_fields` extension), `LitbQuarterlyBriefCatalog::pillar()`/definitions (`app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefCatalog.php`).
- Produces: a new `production` `PromptPresetVersion` for every preset except the 4 `legacy_geo_*` ones, each binding `title_crafting` alongside every module the preset's current version already binds; for the 9 presets matching a `LitbQuarterlyBriefCatalog` pillar, that same new version also carries `blueprint_template`/`default_brief_fields`. Consumed by Task 8 (Component 3's preset dropdown, which requires `whereNotNull('blueprint_template')`).

**Critical ordering note:** this command MUST run after Task 5's command (it looks up `title_crafting`'s current production module version and fails loudly if it doesn't exist) and is itself required before Tasks 8, 12, 16, and 17 become usable (they all require at least one preset with a non-null `blueprint_template`).

Read `app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefCatalog.php` in full first, specifically `pillar()` (lines 172-185) for the pillar→preset-key mapping and `sections()` (lines 196-210) for the 5-section skeleton shape, and `app/Domain/PromptComposition/PromptPresetService.php`'s `createVersion()` (and the private `createVersionForLockedPreset` it delegates to) to confirm the exact `$data`/`$bindings` array shapes it expects — `$bindings` is a list of `['prompt_module_version_id' => int, 'sort_order' => int, 'is_required' => bool]`-shaped arrays (confirmed by `StorePromptPresetRequest`'s `module_versions.*` rules in Task 4's research), not `PromptPresetModuleVersion` model instances.

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\PromptComposition\PromptPresetService;
use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\LitbAssets\LitbQuarterlyBriefCatalog;
use Illuminate\Console\Command;
use RuntimeException;

final class SeedPresetBlueprintTemplatesCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:seed-preset-blueprint-templates';

    protected $description = 'Bind title_crafting into every non-generic preset and attach blueprint_template for the 9 LITB pillar presets';

    private const EXCLUDED_PRESET_KEYS = [
        'legacy_geo_trust_en',
        'legacy_geo_ranking_en',
        'legacy_geo_trust_zh',
        'legacy_geo_ranking_zh',
    ];

    public function __construct(private readonly LitbQuarterlyBriefCatalog $catalog)
    {
        parent::__construct();
    }

    public function handle(PromptPresetService $presets): int
    {
        $actor = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $actor) {
            $this->error('Run "geoflow:content-strategy:seed-system-admin" first (Task 10) — no geoflow-system admin found.');

            return self::FAILURE;
        }

        $titleCraftingModule = PromptModule::query()->where('module_key', 'title_crafting')->first();
        if (! $titleCraftingModule || ! $titleCraftingModule->current_version_id) {
            $this->error('title_crafting module not found — run "geoflow:content-strategy:seed-title-crafting-module" first (Task 5).');

            return self::FAILURE;
        }
        $titleCraftingVersionId = (int) $titleCraftingModule->current_version_id;

        $pillarSections = $this->pillarSectionsByPresetKey();

        $presetsToBackfill = PromptPreset::query()
            ->whereNotIn('preset_key', self::EXCLUDED_PRESET_KEYS)
            ->whereNotNull('current_version_id')
            ->get();

        foreach ($presetsToBackfill as $preset) {
            $this->backfillPreset($presets, $preset, $titleCraftingVersionId, $pillarSections[$preset->preset_key] ?? null, $actor);
        }

        return self::SUCCESS;
    }

    /** @var list<string> */
    private const PILLARS = [
        'category_authority', 'product_comparison', 'style_occasion', 'new_arrivals',
        'product_education', 'customer_education', 'brand_company', 'official_news', 'faq',
    ];

    /** @var array<string, string> generic, topic-agnostic phrasing for the 4 non-lead sections */
    private const GENERIC_SECTION_QUESTIONS = [
        'Key options and criteria' => 'What options and criteria matter most for this decision?',
        'How to check fit, compatibility, or terms' => 'How can a shopper check fit, compatibility, or terms before buying?',
        'Common mistakes and verification checklist' => 'What common mistakes should shoppers avoid?',
        'Frequently asked questions' => 'What do shoppers commonly ask about this topic?',
    ];

    /** @return array<string, array{blueprint_template: array<string, mixed>, default_brief_fields: array<string, mixed>}> */
    private function pillarSectionsByPresetKey(): array
    {
        $byPresetKey = [];
        foreach (self::PILLARS as $pillar) {
            $definition = $this->catalog->pillar($pillar);
            $presetKey = (string) $definition['preset'];

            $byPresetKey[$presetKey] = [
                'blueprint_template' => [
                    'target_length' => 1800,
                    'sections' => $this->templateSections($pillar),
                    'required_entities' => [],
                    'show_references_section' => in_array($pillar, [
                        'official_news', 'brand_company', 'product_comparison', 'customer_education', 'new_arrivals',
                    ], true),
                ],
                'default_brief_fields' => [
                    'market_code' => 'GLOBAL',
                    'language_code' => 'en',
                    'audience' => 'Global English-speaking consumers researching products, purchases, or official LightInTheBox information.',
                    'business_goal' => (string) $definition['business_goal'],
                    'content_type' => (string) $definition['content_type'],
                    'cta_strength' => (string) $definition['cta'],
                ],
            ];
        }

        return $byPresetKey;
    }

    /**
     * Builds the same 5-heading skeleton `LitbQuarterlyBriefCatalog::sections()` uses,
     * but with topic-agnostic purpose/question text — unlike that private per-brief
     * method, this template is shared across every future topic under the preset, so
     * it cannot bake in one specific keyword/question. The lead section's question is
     * left as the literal placeholder "{{core_question}}", which
     * `ContentBriefQuickCreateService` (Task 7) substitutes with the brief's actual
     * `core_question` when it instantiates a per-brief `ContentBlueprintVersion` from
     * this template — if that substitution is ever skipped, `PromptCompiler::render()`
     * already throws on any residual `{{`/`}}` in the compiled prompt, so this fails
     * loudly rather than silently shipping a placeholder to the model.
     *
     * @return list<array<string, mixed>>
     */
    private function templateSections(string $pillar): array
    {
        $headings = [
            'Direct answer and decision summary',
            'Key options and criteria',
            'How to check fit, compatibility, or terms',
            'Common mistakes and verification checklist',
            'Frequently asked questions',
        ];

        $sections = [];
        foreach ($headings as $index => $heading) {
            $sections[] = [
                'key' => \Illuminate\Support\Str::slug($heading),
                'heading' => $heading,
                'purpose' => $index === 0
                    ? "Directly answer the brief's core question before any other content."
                    : "Give evidence-backed guidance for {$heading}.",
                'questions_to_answer' => [$index === 0 ? '{{core_question}}' : self::GENERIC_SECTION_QUESTIONS[$heading]],
                'minimum_words' => $index === 4 ? 220 : 280,
                'cta_allowed' => $pillar !== 'official_news',
                'required_fact_ids' => [],
            ];
        }

        return $sections;
    }

    /** @param array{blueprint_template: array<string, mixed>, default_brief_fields: array<string, mixed>}|null $pillarData */
    private function backfillPreset(PromptPresetService $presets, PromptPreset $preset, int $titleCraftingVersionId, ?array $pillarData, Admin $actor): void
    {
        $currentVersion = $preset->currentVersion;
        if ($currentVersion === null) {
            $this->warn("Preset {$preset->preset_key} has no current version, skipping.");

            return;
        }

        $alreadyBound = PromptPresetModuleVersion::query()
            ->where('prompt_preset_version_id', $currentVersion->id)
            ->where('prompt_module_version_id', $titleCraftingVersionId)
            ->exists();
        if ($alreadyBound && ($pillarData === null || $currentVersion->blueprint_template !== null)) {
            $this->info("Preset {$preset->preset_key} already up to date, skipping.");

            return;
        }

        $existingBindings = PromptPresetModuleVersion::query()
            ->where('prompt_preset_version_id', $currentVersion->id)
            ->orderBy('sort_order')
            ->get();
        $nextSortOrder = ((int) $existingBindings->max('sort_order')) + 1;

        $bindings = $existingBindings->map(fn (PromptPresetModuleVersion $binding): array => [
            'prompt_module_version_id' => (int) $binding->prompt_module_version_id,
            'sort_order' => (int) $binding->sort_order,
            'is_required' => (bool) $binding->is_required,
        ])->all();
        if (! $alreadyBound) {
            $bindings[] = [
                'prompt_module_version_id' => $titleCraftingVersionId,
                'sort_order' => $nextSortOrder,
                'is_required' => true,
            ];
        }

        $data = [
            'matching_rules' => $currentVersion->matching_rules,
            'generation_policy' => $currentVersion->generation_policy,
            'conflict_policy' => $currentVersion->conflict_policy,
            'change_note' => 'Bind title_crafting mandatory module'.($pillarData !== null ? ' and attach blueprint_template.' : '.'),
        ];
        if ($pillarData !== null) {
            $data['blueprint_template'] = $pillarData['blueprint_template'];
            $data['default_brief_fields'] = $pillarData['default_brief_fields'];
        }

        $newVersion = $presets->createVersion($preset, $data, $bindings, $actor);
        $presets->publish($newVersion, $actor, 'Automated backfill: title_crafting binding'.($pillarData !== null ? ' + blueprint_template.' : '.'));
        $this->info("Backfilled {$preset->preset_key} -> version {$newVersion->version}.");
    }
}
```

`LitbQuarterlyBriefCatalog::pillar(string $pillar): array` (line 172) is currently `private`. Widen it to `public` as part of this task — a minimal, justified visibility change, not a behavior change, since this command is a legitimate second caller that needs the same `business_goal`/`content_type`/`cta`/`preset` lookup the importer already uses internally. Do **not** call `LitbQuarterlyBriefCatalog::sections()` — it is also `private`, but more importantly it bakes one specific brief's `$keyword`/`$question` into the section text (e.g. `"What options and criteria matter most when choosing {$keyword}?"`), which is fundamentally per-brief, not reusable across every future topic under a preset. `templateSections()` above intentionally reimplements the same 5-heading skeleton with generic, topic-agnostic phrasing instead of calling it. There is no public list of the 9 pillar keys on the catalog (they only exist as the array keys of a `private const TOPICS`) — `self::PILLARS` above hardcodes the same 9 keys directly, confirmed against `TOPICS`' actual keys.

The `$definition['content_type']`/`$definition['business_goal']`/`$definition['cta']` values used above must come from `pillar()`'s real return array (confirmed values: e.g. `category_authority`'s `content_type` is `'category_guide'`, not the pillar key itself — do not assume the pillar key and `content_type` are the same string, they differ for most pillars).

- [ ] **Step 2: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Database\Seeders\PromptCompositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedPresetBlueprintTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_a_litb_preset_with_blueprint_template_and_title_crafting(): void
    {
        $actor = Admin::query()->create([
            'username' => 'geoflow-system',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $preset = PromptPreset::query()->create([
            'preset_key' => 'litb.geo_category_authority.en',
            'display_name' => 'LITB category authority',
            'is_system' => true,
            'is_enabled' => true,
        ]);
        $seoStructureVersion = PromptModule::query()->where('module_key', 'seo_structure')->firstOrFail()->currentVersion;
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => ['category_authority']],
            'generation_policy' => [],
            'conflict_policy' => null,
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $actor->id,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $version->id,
            'prompt_module_version_id' => $seoStructureVersion->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $this->artisan('geoflow:content-strategy:seed-preset-blueprint-templates')->assertSuccessful();

        $preset->refresh();
        $newVersion = $preset->currentVersion;
        $this->assertSame(2, $newVersion->version);
        $this->assertNotNull($newVersion->blueprint_template);
        $this->assertSame(1800, $newVersion->blueprint_template['target_length']);
        $this->assertSame('US', $newVersion->default_brief_fields['market_code']);
        $titleCraftingVersionId = PromptModule::query()->where('module_key', 'title_crafting')->firstOrFail()->current_version_id;
        $this->assertTrue(
            PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $newVersion->id)
                ->where('prompt_module_version_id', $titleCraftingVersionId)
                ->exists()
        );
        $this->assertTrue(
            PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $newVersion->id)
                ->where('prompt_module_version_id', $seoStructureVersion->id)
                ->exists(),
            'Existing module bindings must be preserved in the new version, not dropped.'
        );
    }

    public function test_does_not_touch_the_four_excluded_generic_presets(): void
    {
        Admin::query()->create([
            'username' => 'geoflow-system',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $preset = PromptPreset::query()->create([
            'preset_key' => 'legacy_geo_trust_en',
            'display_name' => 'Legacy trust EN',
            'is_system' => true,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $this->artisan('geoflow:content-strategy:seed-preset-blueprint-templates')->assertSuccessful();

        $preset->refresh();
        $this->assertSame(1, $preset->currentVersion->version);
    }
}
```

- [ ] **Step 3: Run the tests**

Run: `docker compose exec -T app php artisan test --filter=SeedPresetBlueprintTemplatesCommandTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/SeedPresetBlueprintTemplatesCommand.php tests/Feature/SeedPresetBlueprintTemplatesCommandTest.php
git commit -m "feat: backfill preset versions with title_crafting binding and blueprint_template"
```

---

## Task 7: `ContentBriefQuickCreateService` — Component 3's core service

**Files:**

- Create: `app/Domain/ContentStrategy/ContentBriefQuickCreateService.php`
- Test: `tests/Feature/ContentBriefQuickCreateServiceTest.php`

**Interfaces:**

- Consumes: `PromptPresetVersion.blueprint_template`/`default_brief_fields` (Task 3). This service does not fetch or attach `reference_urls` evidence itself — it stores `reference_urls` on the `ContentBrief` verbatim; `ReferenceEvidenceFetcher` (built later, Task 11) and evidence snapshotting happen in Task 12's `WorkerExecutionService` branch, which calls this service and then separately fetches reference URLs before reserving the generation run.
- Produces: `ContentBriefQuickCreateService::create(array $data, PromptPreset $preset, Admin $actor): ContentBrief` where `$data` is `array{topic: string, core_question?: ?string, category_id?: ?int, primary_keyword_id?: ?int, keyword_cluster_id?: ?int, reference_urls?: list<string>, task_id?: ?int}`. Returns a `ContentBrief` with `status=STRATEGY_READY` and exactly one `CONFIRMED` `ContentStrategyVersion` + one `CONFIRMED` `ContentBlueprintVersion` already attached. Consumed directly by Task 8 (manual quick-create UI) and Task 12 (`WorkerExecutionService`'s quality-gated branch); bulk import (Tasks 15-17) never calls it directly — it creates `Task`s that Task 12's code path picks up later.

Read `app/Services/GeoFlow/LitbAssets/LitbQuarterlyBriefImporter.php` in full (141 lines) before starting — this service is a direct generalization of it, using a preset's `blueprint_template`/`default_brief_fields` instead of `LitbQuarterlyBriefCatalog`. Also re-read `app/Domain/ContentStrategy/BlueprintValidator.php`'s `validate()` requirements (`working_title`, `target_length`, `sections[].{key,heading,purpose,questions_to_answer,minimum_words,cta_allowed,required_fact_ids}`) — the blueprint this service builds must satisfy them or `ContentStrategyService::confirmBlueprint()`-style validation would reject it (this service uses `forceCreate()` directly like the importer does, bypassing that validator call, but the shape must still match what `DeterministicQualityGate::inspect()`/`PromptCompiler` expect at generation time, so build it correctly regardless).

- [ ] **Step 1: Add `content_briefs.task_id` first (small prerequisite, not its own task)**

This service accepts an optional `task_id` in `$data`. Rather than deferring this one-column addition to Task 9 and creating an ordering dependency between two tasks that both touch migrations, add it here as this task's first step — it is a single nullable, unindexed-beyond-one-index column with no interaction with the rest of Task 9's `tasks` table changes.

Create `database/migrations/2026_08_15_000200_add_task_id_to_content_briefs.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_briefs') && ! Schema::hasColumn('content_briefs', 'task_id')) {
            Schema::table('content_briefs', function (Blueprint $table): void {
                $table->foreignId('task_id')->nullable()->after('created_by_admin_id')->constrained('tasks')->nullOnDelete();
                $table->index('task_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('content_briefs') && Schema::hasColumn('content_briefs', 'task_id')) {
            Schema::table('content_briefs', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('task_id');
            });
        }
    }
};
```

**Do not add `task_id` to `$fillable`** — like every other identity/provenance field on `ContentBrief` (`asset_key`, `asset_provenance`), it is set once at creation via this service's own `create()`/`forceCreate()` call, never through mass-assignment from a controller request.

Add `task_id` to `casts()` in `app/Models/ContentBrief.php`: `'task_id' => 'integer',`. Add a relation: `public function task(): BelongsTo { return $this->belongsTo(Task::class, 'task_id'); }` and its inverse in `app/Models/Task.php`: `public function contentBriefs(): HasMany { return $this->hasMany(ContentBrief::class, 'task_id'); }`.

Also add a `generationRuns()` relation to `app/Models/ContentBrief.php` — it doesn't exist today (only the inverse `ContentGenerationRun::contentBrief()` does), and Task 13's auto-adopt command needs it: `public function generationRuns(): HasMany { return $this->hasMany(\App\Models\ContentGenerationRun::class, 'content_brief_id'); }`.

- [ ] **Step 2: Write the failing test for the happy path**

```php
<?php

namespace Tests\Feature;

use App\Domain\ContentStrategy\ContentBriefQuickCreateService;
use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentBriefQuickCreateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_fully_confirmed_brief_strategy_and_blueprint(): void
    {
        $actor = Admin::query()->create([
            'username' => 'quick-create-tester',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.quick-create',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'blueprint_template' => [
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'answer',
                    'heading' => 'Direct answer',
                    'purpose' => "Answer the brief's core question.",
                    'questions_to_answer' => ['{{core_question}}'],
                    'minimum_words' => 200,
                    'cta_allowed' => false,
                    'required_fact_ids' => [],
                ]],
                'required_entities' => [],
                'show_references_section' => false,
            ],
            'default_brief_fields' => [
                'market_code' => 'US',
                'language_code' => 'en',
                'audience' => 'Test audience',
                'business_goal' => 'Test goal',
                'content_type' => 'buying_guide',
                'cta_strength' => 'soft',
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $service = $this->app->make(ContentBriefQuickCreateService::class);
        $brief = $service->create([
            'topic' => 'How to Choose Running Shoes',
            'core_question' => 'What should a first-time buyer check before ordering running shoes online?',
        ], $preset, $actor);

        $this->assertSame(ContentBrief::STATUS_STRATEGY_READY, $brief->status);
        $this->assertSame('How to Choose Running Shoes', $brief->topic);

        $strategy = $brief->strategyVersions()->firstOrFail();
        $this->assertSame(ContentStrategyVersion::STATUS_CONFIRMED, $strategy->lifecycle_status);
        $this->assertSame('Test audience', $strategy->strategy['audience']);

        $blueprint = $brief->blueprintVersions()->firstOrFail();
        $this->assertSame(ContentBlueprintVersion::STATUS_CONFIRMED, $blueprint->lifecycle_status);
        $this->assertSame('How to Choose Running Shoes', $blueprint->blueprint['working_title']);
        $this->assertSame(
            'What should a first-time buyer check before ordering running shoes online?',
            $blueprint->blueprint['sections'][0]['questions_to_answer'][0],
            'The {{core_question}} placeholder in the template must be substituted with the real core_question.',
        );
        $this->assertStringNotContainsString('{{', json_encode($blueprint->blueprint), 'No unresolved template placeholders may remain in the instantiated blueprint.');
    }

    public function test_rejects_a_preset_without_a_blueprint_template(): void
    {
        $actor = Admin::query()->create([
            'username' => 'quick-create-tester-2',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.no-template',
            'display_name' => 'No template preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $service = $this->app->make(ContentBriefQuickCreateService::class);

        $this->expectException(\DomainException::class);
        $service->create(['topic' => 'Anything'], $preset, $actor);
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=ContentBriefQuickCreateServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the service**

```php
<?php

namespace App\Domain\ContentStrategy;

use App\Models\Admin;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ContentBriefQuickCreateService
{
    /**
     * @param  array{topic: string, core_question?: ?string, category_id?: ?int, primary_keyword_id?: ?int, keyword_cluster_id?: ?int, reference_urls?: list<string>, task_id?: ?int}  $data
     */
    public function create(array $data, PromptPreset $preset, Admin $actor): ContentBrief
    {
        $presetVersion = $preset->currentVersion;
        if ($presetVersion === null || $presetVersion->blueprint_template === null) {
            throw new DomainException("Preset \"{$preset->preset_key}\" has no blueprint_template on its current version.");
        }

        $topic = trim((string) $data['topic']);
        if ($topic === '') {
            throw new DomainException('topic is required.');
        }
        $coreQuestion = trim((string) ($data['core_question'] ?? ''));
        if ($coreQuestion === '') {
            $coreQuestion = "What should a reader know before deciding on {$topic}?";
        }

        $defaults = (array) ($presetVersion->default_brief_fields ?? []);

        return DB::transaction(function () use ($data, $preset, $presetVersion, $actor, $topic, $coreQuestion, $defaults): ContentBrief {
            $brief = ContentBrief::query()->create([
                'topic' => $topic,
                'market_code' => (string) ($defaults['market_code'] ?? 'GLOBAL'),
                'language_code' => (string) ($defaults['language_code'] ?? 'en'),
                'audience' => (string) ($defaults['audience'] ?? ''),
                'core_question' => $coreQuestion,
                'business_goal' => (string) ($defaults['business_goal'] ?? ''),
                'content_type' => (string) ($defaults['content_type'] ?? ''),
                'keyword_cluster_id' => $data['keyword_cluster_id'] ?? null,
                'primary_keyword_id' => $data['primary_keyword_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'reference_urls' => array_values($data['reference_urls'] ?? []),
                'cta_strength' => (string) ($defaults['cta_strength'] ?? 'soft'),
                'status' => ContentBrief::STATUS_STRATEGY_READY,
                'created_by_admin_id' => $actor->id,
                'task_id' => $data['task_id'] ?? null,
            ]);

            $strategy = [
                'audience' => (string) ($defaults['audience'] ?? ''),
                'core_question' => $coreQuestion,
                'primary_intent' => 'commercial',
                'secondary_intents' => [],
                'decision_stage' => 'consideration',
                'content_type' => (string) ($defaults['content_type'] ?? ''),
                'content_goal' => (string) ($defaults['business_goal'] ?? ''),
                'cta_strength' => (string) ($defaults['cta_strength'] ?? 'soft'),
                'target_url' => null,
                'target_keyword_cluster' => null,
                'questions_to_answer' => [$coreQuestion],
                'risks' => [],
                'evidence_gaps' => [],
            ];
            $strategyVersion = ContentStrategyVersion::forceCreate([
                'content_brief_id' => $brief->id,
                'version' => 1,
                'strategy' => $strategy,
                'suggestion_snapshot' => null,
                'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
                'created_by_admin_id' => $actor->id,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_at' => now(),
            ]);

            $blueprint = $this->instantiateBlueprint((array) $presetVersion->blueprint_template, $topic, $coreQuestion);
            ContentBlueprintVersion::forceCreate([
                'content_brief_id' => $brief->id,
                'content_strategy_version_id' => $strategyVersion->id,
                'version' => 1,
                'blueprint' => $blueprint,
                'validation_report' => null,
                'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
                'created_by_admin_id' => $actor->id,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_at' => now(),
            ]);

            return $brief;
        });
    }

    /** @param array<string, mixed> $template @return array<string, mixed> */
    private function instantiateBlueprint(array $template, string $topic, string $coreQuestion): array
    {
        $sections = [];
        foreach ((array) ($template['sections'] ?? []) as $section) {
            $section['questions_to_answer'] = array_map(
                fn (string $question): string => str_replace('{{core_question}}', $coreQuestion, $question),
                (array) ($section['questions_to_answer'] ?? []),
            );
            $sections[] = $section;
        }

        return [
            'working_title' => $topic,
            'target_length' => (int) ($template['target_length'] ?? 1800),
            'sections' => $sections,
            'required_entities' => (array) ($template['required_entities'] ?? []),
            'faq_questions' => [$coreQuestion],
            'seo_title' => mb_substr($topic.' | LightInTheBox', 0, 60),
            'meta_description' => mb_substr($coreQuestion, 0, 155),
            'schema_types' => ['Article'],
            'show_references_section' => (bool) ($template['show_references_section'] ?? false),
        ];
    }
}
```

**Check `ContentStrategyVersion.$fillable`/`ContentBlueprintVersion.$fillable` are `['lifecycle_status', 'change_note', 'confirmed_by_admin_id', 'confirmed_at']` before running this** (confirmed during planning) — `forceCreate()` is required here specifically because `content_brief_id`, `version`, `strategy`/`blueprint`, `suggestion_snapshot`/`validation_report`, and `created_by_admin_id` are deliberately **not** fillable (immutable-by-mass-assignment, matching `LitbQuarterlyBriefImporter`'s own use of `forceCreate()` for the identical reason). Also verify `content_blueprint_versions`' actual JSON column name is `blueprint` (not `blueprint_json` or similar) and `content_strategy_versions`' is `strategy`, and that a `validation_report` column exists on `content_blueprint_versions` (mirroring `suggestion_snapshot` on strategy versions) — read the `content_blueprint_versions` migration block before finalizing field names if anything above doesn't match.

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T app php artisan test --filter=ContentBriefQuickCreateServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_15_000200_add_task_id_to_content_briefs.php app/Models/ContentBrief.php app/Models/Task.php app/Domain/ContentStrategy/ContentBriefQuickCreateService.php tests/Feature/ContentBriefQuickCreateServiceTest.php
git commit -m "feat: add ContentBriefQuickCreateService for one-call confirmed brief creation"
```

---

## Task 8: Wire the simplified creation flow into `ContentBriefController`

**Files:**

- Modify: `app/Http/Controllers/Admin/ContentBriefController.php`, `app/Http/Requests/Admin/StoreContentBriefRequest.php`, `resources/views/admin/content-briefs/create.blade.php`
- Test: `tests/Feature/ContentBriefControllerQuickCreateTest.php`

**Interfaces:**

- Consumes: `ContentBriefQuickCreateService::create()` (Task 7).
- Produces: `POST /geo_admin/content-briefs` now branches on whether `prompt_preset_id` was submitted — if present, it skips straight to the brief's `show` page (strategy/blueprint already confirmed); if absent, it falls through to today's existing manual flow unchanged.

Read `app/Http/Controllers/Admin/ContentBriefController.php`'s `store()` (lines 74-84) and `app/Http/Requests/Admin/StoreContentBriefRequest.php` in full before starting.

- [ ] **Step 1: Extend `StoreContentBriefRequest`**

Add one rule to `rules()`:

```php
            'prompt_preset_id' => ['nullable', 'integer', 'exists:prompt_presets,id'],
```

Do not make any currently-required field conditionally-required here — per Component 3's design, when `prompt_preset_id` is present the controller (not this request class) reads `topic`/`core_question`/`category_id`/`primary_keyword_id`/`reference_urls` directly and ignores the rest, so the existing full validation ruleset staying mandatory for the *manual* path is fine; the quick-create path's Blade form (Step 3 below) simply won't render the fields it doesn't ask for, and the quick-create branch in the controller (Step 2) reads straight from `$request->validated()`'s subset it needs rather than requiring every field the manual path requires. If this causes the manual-path fields' `required` rules to reject a quick-create submission that omits them, loosen exactly those specific rules to `sometimes|required_without:prompt_preset_id` — check each rule in the existing file and apply this pattern only to the ones that are unconditionally `required` today.

- [ ] **Step 2: Branch `store()`**

Replace the body of `store()`:

```php
    public function store(StoreContentBriefRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $actor = $this->actor($request);

        $presetId = (int) ($data['prompt_preset_id'] ?? 0);
        if ($presetId > 0) {
            $preset = PromptPreset::query()->findOrFail($presetId);
            $brief = $this->quickCreate->create([
                'topic' => (string) $data['topic'],
                'core_question' => $data['core_question'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'primary_keyword_id' => $data['primary_keyword_id'] ?? null,
                'keyword_cluster_id' => $data['keyword_cluster_id'] ?? null,
                'reference_urls' => $data['reference_urls'] ?? [],
            ], $preset, $actor);

            return redirect()->route('admin.content-briefs.show', $brief)
                ->with('message', __('admin.content_strategy.messages.brief_created'));
        }

        $brief = ContentBrief::query()->create([...$data, 'status' => ContentBrief::STATUS_READY]);

        return redirect()->route('admin.content-briefs.strategy', $brief);
    }
```

Replace `[...$data, 'status' => ContentBrief::STATUS_READY]` with whatever the *original* `store()` body actually passed to `ContentBrief::create()` — read the current method first and keep that line's field list byte-for-byte identical to today, only wrapping it in the new `if`/`else` branch. Add the constructor dependency: `private readonly ContentBriefQuickCreateService $quickCreate` alongside the existing `ContentStrategyService $strategies` injection, and `use App\Domain\ContentStrategy\ContentBriefQuickCreateService;` / `use App\Models\PromptPreset;` imports.

- [ ] **Step 3: Add a Preset selector to the create form**

In `resources/views/admin/content-briefs/create.blade.php`, add a `prompt_preset_id` select populated from presets with a `blueprint_template`, positioned above the rest of the form with a short note that choosing one skips straight to generation:

```blade
<div class="mb-6 rounded-md border border-blue-200 bg-blue-50 p-4">
    <label class="block text-sm font-medium text-gray-700">Quick create from a preset (optional)</label>
    <select name="prompt_preset_id" class="mt-1 w-full rounded-md border-gray-300">
        <option value="">— Manual strategy/blueprint (today's flow) —</option>
        @foreach ($quickCreatePresets as $preset)
            <option value="{{ $preset->id }}">{{ $preset->display_name }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">Choosing a preset skips the strategy/blueprint review screens — topic and core question become the article's direction and generation is ready to request immediately.</p>
</div>
```

In `ContentBriefController::create()`, pass `$quickCreatePresets` to the view:

```php
'quickCreatePresets' => PromptPreset::query()
    ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
    ->orderBy('display_name')
    ->get(['id', 'display_name']),
```

(Match this against the query style Task 4/6's `whereHas('currentVersion', ...)` pattern already established — same filter, reused verbatim here.)

- [ ] **Step 4: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentBriefControllerQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_preset_id_creates_a_fully_confirmed_brief_and_skips_to_show(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brief-quick-create-tester',
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.controller-quick-create',
            'display_name' => 'Test preset',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'blueprint_template' => [
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'answer', 'heading' => 'Direct answer', 'purpose' => 'Answer it.',
                    'questions_to_answer' => ['{{core_question}}'], 'minimum_words' => 100,
                    'cta_allowed' => false, 'required_fact_ids' => [],
                ]],
                'required_entities' => [], 'show_references_section' => false,
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.content-briefs.store'), [
            'prompt_preset_id' => $preset->id,
            'topic' => 'How to Choose Running Shoes',
            'core_question' => 'What should a buyer check first?',
        ]);

        $brief = ContentBrief::query()->where('topic', 'How to Choose Running Shoes')->firstOrFail();
        $response->assertRedirect(route('admin.content-briefs.show', $brief));
        $this->assertSame(ContentBrief::STATUS_STRATEGY_READY, $brief->status);
        $this->assertTrue($brief->strategyVersions()->exists());
        $this->assertTrue($brief->blueprintVersions()->exists());
    }
}
```

(Check that `content-briefs` routes require the three `geoflow.feature:*` middlewares — this test's `TestCase` setup may need `config(['geoflow.content_strategy_enabled' => true, 'geoflow.modular_prompts_enabled' => true, 'geoflow.generation_quality_gate_enabled' => true]);` before the request, or the route 404s per `EnsureGeoFlowFeatureEnabled`. Check how other existing tests under `tests/Feature/` that hit `content-briefs`/`content-generations` routes already handle this — e.g. `ContentGenerationCoordinatorTest.php` or any `ContentBriefController`-adjacent test — and copy that exact setup pattern rather than guessing at the config keys' effect.)

- [ ] **Step 5: Run the test**

Run: `docker compose exec -T app php artisan test --filter=ContentBriefControllerQuickCreateTest`
Expected: PASS

- [ ] **Step 6: Run the existing content-brief regression suite**

Run: `docker compose exec -T app php artisan test --filter=ContentBrief`
Expected: PASS unchanged — the manual (no `prompt_preset_id`) path must behave identically to before.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/ContentBriefController.php app/Http/Requests/Admin/StoreContentBriefRequest.php resources/views/admin/content-briefs/create.blade.php tests/Feature/ContentBriefControllerQuickCreateTest.php
git commit -m "feat: let content-brief creation skip straight to generation via a chosen preset"
```

---

## Task 9: `generation_engine`/`prompt_preset_id` on `Task`

**Files:**

- Create: `database/migrations/2026_08_15_000300_add_generation_engine_to_tasks.php`
- Modify: `app/Models/Task.php`
- Test: `tests/Feature/TaskGenerationEngineMigrationTest.php`

**Interfaces:**

- Produces: `Task.generation_engine` (string, default `'legacy'`, values `legacy`|`quality_gated`), `Task.prompt_preset_id` (nullable FK). Consumed by Task 12 (`WorkerExecutionService`), Task 14 (admin form).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'generation_engine')) {
                $table->string('generation_engine', 20)->default('legacy')->after('prompt_id');
            }
            if (! Schema::hasColumn('tasks', 'prompt_preset_id')) {
                $table->foreignId('prompt_preset_id')->nullable()->after('generation_engine')->constrained('prompt_presets')->nullOnDelete();
            }
        });

        DB::table('tasks')->whereNull('generation_engine')->update(['generation_engine' => 'legacy']);

        // `tasks.prompt_id` is NOT NULL in the real Postgres schema
        // (`database/migrations/2026_04_18_120000_geoflow_legacy_schema.php:245`) — a
        // quality_gated Task has no single free-text Prompt (it uses `prompt_preset_id`
        // instead), so the column must become nullable for such Tasks to be creatable.
        DB::statement('ALTER TABLE tasks ALTER COLUMN prompt_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'prompt_preset_id')) {
                $table->dropConstrainedForeignId('prompt_preset_id');
            }
            if (Schema::hasColumn('tasks', 'generation_engine')) {
                $table->dropColumn('generation_engine');
            }
        });

        // Deliberately does not restore prompt_id's NOT NULL constraint — any
        // quality_gated Task created while this migration was applied would have
        // a null prompt_id, and re-adding NOT NULL here would fail outright, or
        // silently corrupt data if backfilled with a fake Prompt reference. Rolling
        // back this feature is expected to also mean deleting or migrating those
        // Tasks first, not something this migration should attempt automatically.
    }
};
```

- [ ] **Step 2: Update the model**

In `app/Models/Task.php`, add `'generation_engine', 'prompt_preset_id',` to `$fillable` (after `'prompt_id',`), add `'prompt_preset_id' => 'integer',` to `casts()`, and add a relation:

```php
    public function promptPreset(): BelongsTo
    {
        return $this->belongsTo(PromptPreset::class, 'prompt_preset_id');
    }
```

(`use App\Models\PromptPreset;` is unnecessary since it's in the same namespace — confirm by checking the existing `use` statements at the top of the file; `Task.php`'s other relations like `titleLibrary()` don't import their target classes either since they're all in `App\Models`.)

- [ ] **Step 3: Write a migration test**

```php
<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskGenerationEngineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_engine_defaults_to_legacy(): void
    {
        $task = Task::query()->create([
            'name' => 'Test task',
            'title_library_id' => 1,
            'status' => 'paused',
        ]);

        $this->assertSame('legacy', $task->fresh()->generation_engine);
        $this->assertNull($task->fresh()->prompt_preset_id);
    }
}
```

(If `Task::create()` fails on other NOT NULL columns not shown here, check an existing `Task`-creating test elsewhere in `tests/Feature/TaskControllerTest.php` or similar and copy its minimal required-field set rather than guessing.)

- [ ] **Step 4: Run the test**

Run: `docker compose exec -T app php artisan test --filter=TaskGenerationEngineMigrationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_15_000300_add_generation_engine_to_tasks.php app/Models/Task.php tests/Feature/TaskGenerationEngineMigrationTest.php
git commit -m "feat: add generation_engine and prompt_preset_id columns to tasks"
```

---

## Task 10: System `Admin` actor + policy extensions

**Files:**

- Create: `database/migrations/2026_08_15_000400_seed_geoflow_system_admin.php`
- Modify: `app/Policies/ContentBriefPolicy.php`, `app/Policies/ContentGenerationRunPolicy.php`
- Test: `tests/Feature/GeoFlowSystemAdminPolicyTest.php`

**Interfaces:**

- Produces: an `admins` row with `username='geoflow-system'`, `role='system'`, `status='active'` — consumed by Tasks 5, 6, 11, 13, 17 as the `Admin $actor` for every autonomous action. `ContentBriefPolicy::view()`/`update()` and `ContentGenerationRunPolicy::view()`/`adopt()` now also allow any active admin to view (not adopt, not update) a Task-driven brief/run regardless of `created_by_admin_id`.

Tasks routes (`routes/web.php:203-213`) carry no per-admin ownership restriction today (confirmed: no `TaskPolicy` class exists, no `->can(...)` gate on any task route) — any authenticated admin can manage any Task. Since `ContentBriefPolicy::owns()`/`ContentGenerationRunPolicy::ownsParentBrief()` currently restrict non-superadmins to briefs *they personally* created, a Task-driven brief (created by the system admin, never a real human) would otherwise be invisible in `/content-briefs/{id}` to every admin except superadmins — inconsistent with Tasks themselves being manageable by any admin. This task closes that gap for viewing only; `update`/`adopt` on a Task-driven brief/run stay restricted to superadmins (adoption for these is automated via Task 13 anyway, never a human action through the UI, so this restriction has no practical effect on the automated flow — it only affects whether a human can review the quality report and adopted article's generation history).

- [ ] **Step 1: Seed the system admin**

Read `database/migrations/2026_04_18_120000_geoflow_legacy_schema.php`'s `admins` table definition (or wherever `admins` is currently created — grep `database/migrations` for `CREATE TABLE.*admins\|Schema::create('admins'` to find it) to confirm every NOT NULL column before writing this migration's `insert()` call.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        $exists = DB::table('admins')->where('username', 'geoflow-system')->exists();
        if ($exists) {
            return;
        }

        DB::table('admins')->insert([
            'username' => 'geoflow-system',
            'password' => Hash::make(Str::random(64)),
            'email' => null,
            'display_name' => 'GEOFlow System',
            'role' => 'system',
            'status' => 'active',
            'auth_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('admins')) {
            DB::table('admins')->where('username', 'geoflow-system')->delete();
        }
    }
};
```

**Check every column listed against the real `admins` schema before finalizing** — if `email` is NOT NULL or has a unique index that rejects `null`, use a clearly-synthetic placeholder like `'geoflow-system@invalid.local'` instead; if there are additional NOT NULL columns not listed here (e.g. `welcome_seen_version`), add them with sensible defaults matching the model's `$attributes` default (`'auth_version' => 1`).

Deliberately use `role='system'`, not `'super_admin'`/`'superadmin'` — `Admin::isSuperAdmin()` checks for exactly those two strings, and this actor should **not** gain the broad view/manage bypass that role grants elsewhere in the admin panel (least-privilege: it is a narrow, code-only actor, not an operator account).

- [ ] **Step 2: Extend `ContentBriefPolicy`**

```php
final class ContentBriefPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $this->isActive($admin);
    }

    public function view(Admin $admin, ContentBrief $brief): bool
    {
        return $this->owns($admin, $brief) || ($this->isActive($admin) && $brief->task_id !== null);
    }

    public function create(Admin $admin): bool
    {
        return $this->isActive($admin);
    }

    public function update(Admin $admin, ContentBrief $brief): bool
    {
        return $this->owns($admin, $brief);
    }

    private function owns(Admin $admin, ContentBrief $brief): bool
    {
        return $this->isActive($admin)
            && ($admin->isSuperAdmin()
                || (int) $brief->created_by_admin_id === (int) $admin->getKey());
    }

    private function isActive(Admin $admin): bool
    {
        return $admin->status === 'active';
    }
}
```

(Only `view()` changed — `update()`/`create()`/`viewAny()`/`owns()`/`isActive()` are unchanged, shown here in full only so the whole class reads consistently. `$brief->task_id` requires Task 7 Step 1's migration/cast to be in place.)

- [ ] **Step 3: Extend `ContentGenerationRunPolicy`**

```php
final class ContentGenerationRunPolicy
{
    public function view(Admin $admin, ContentGenerationRun $run): bool
    {
        return $this->ownsParentBrief($admin, $run) || $this->isTaskDriven($admin, $run);
    }

    public function adopt(Admin $admin, ContentGenerationRun $run): bool
    {
        return $this->ownsParentBrief($admin, $run);
    }

    private function isTaskDriven(Admin $admin, ContentGenerationRun $run): bool
    {
        if ($admin->status !== 'active') {
            return false;
        }

        return $run->contentBrief()->whereNotNull('task_id')->exists();
    }

    private function ownsParentBrief(Admin $admin, ContentGenerationRun $run): bool
    {
        if ($admin->status !== 'active') {
            return false;
        }
        if ($admin->isSuperAdmin()) {
            return true;
        }

        return (int) $run->contentBrief()->value('created_by_admin_id') === (int) $admin->getKey();
    }
}
```

- [ ] **Step 4: Write a policy test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class GeoFlowSystemAdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_system_admin_that_is_not_a_superadmin(): void
    {
        $this->artisan('migrate');

        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $this->assertFalse($systemAdmin->isSuperAdmin());
        $this->assertSame('active', $systemAdmin->status);
    }

    public function test_any_active_admin_can_view_a_task_driven_brief_they_did_not_create(): void
    {
        $this->artisan('migrate');
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $regularAdmin = Admin::query()->create([
            'username' => 'regular-viewer', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $task = Task::query()->create(['name' => 'Test', 'title_library_id' => 1, 'status' => 'paused']);
        $brief = ContentBrief::query()->create([
            'topic' => 'Test', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id,
        ]);

        $this->assertTrue(Gate::forUser($regularAdmin)->allows('view', $brief));
        $this->assertFalse(Gate::forUser($regularAdmin)->allows('update', $brief));
    }

    public function test_a_regular_admin_still_cannot_view_a_manually_created_brief_owned_by_someone_else(): void
    {
        $this->artisan('migrate');
        $owner = Admin::query()->create([
            'username' => 'owner-admin', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $regularAdmin = Admin::query()->create([
            'username' => 'other-admin', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $brief = ContentBrief::query()->create([
            'topic' => 'Test', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $owner->id,
        ]);

        $this->assertFalse(Gate::forUser($regularAdmin)->allows('view', $brief));
    }
}
```

(`ContentBrief::create([...])` above uses mass-assignment against `$fillable` — confirm every field listed is actually fillable and every NOT NULL column is covered, per the exact `$fillable`/migration list already confirmed during planning; if any field name differs, fix it here rather than guessing.)

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T app php artisan test --filter=GeoFlowSystemAdminPolicyTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_15_000400_seed_geoflow_system_admin.php app/Policies/ContentBriefPolicy.php app/Policies/ContentGenerationRunPolicy.php tests/Feature/GeoFlowSystemAdminPolicyTest.php
git commit -m "feat: seed a system admin actor and let any admin view (not manage) task-driven briefs"
```

---

## Task 11: `ReferenceEvidenceFetcher`

**Files:**

- Create: `app/Domain/ContentGeneration/ReferenceEvidenceFetcher.php`
- Test: `tests/Unit/ReferenceEvidenceFetcherTest.php`

**Interfaces:**

- Consumes: `App\Services\Outbound\SafeOutboundHttpClient`/`SafeOutboundRequest` (existing).
- Produces: `ReferenceEvidenceFetcher::fetch(array $urls): list<array{url: string, status: 'fetched'|'failed', content: ?string}>`. Consumed by Task 12 (`WorkerExecutionService`'s brief creation) — this is also where a bulk-imported row's `reference_urls` (stored on its `Title`, per Task 12 Step 1) eventually get fetched, since every quality-gated Task (manually created or bulk-imported) flows through the same `generateQualityGatedDraft()` code path.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\ReferenceEvidenceFetcher;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SafeOutboundRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class ReferenceEvidenceFetcherTest extends TestCase
{
    public function test_extracts_readable_text_from_a_successfully_fetched_url(): void
    {
        $client = $this->createMock(SafeOutboundHttpClient::class);
        $client->method('get')->willReturn(
            Response::fromPsrResponse(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html><body><nav>Skip</nav><article><p>Real content here.</p></article></body></html>'))
        );
        $fetcher = new ReferenceEvidenceFetcher($client);

        $results = $fetcher->fetch(['https://example.com/a']);

        $this->assertSame('fetched', $results[0]['status']);
        $this->assertStringContainsString('Real content here.', $results[0]['content']);
        $this->assertStringNotContainsString('Skip', $results[0]['content']);
    }

    public function test_marks_a_url_failed_when_the_client_throws(): void
    {
        $client = $this->createMock(SafeOutboundHttpClient::class);
        $client->method('get')->willThrowException(new \RuntimeException('blocked'));
        $fetcher = new ReferenceEvidenceFetcher($client);

        $results = $fetcher->fetch(['https://example.com/blocked']);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertNull($results[0]['content']);
        $this->assertSame('https://example.com/blocked', $results[0]['url']);
    }

    public function test_marks_a_non_200_response_failed(): void
    {
        $client = $this->createMock(SafeOutboundHttpClient::class);
        $client->method('get')->willReturn(
            Response::fromPsrResponse(new \GuzzleHttp\Psr7\Response(404, [], 'not found'))
        );
        $fetcher = new ReferenceEvidenceFetcher($client);

        $results = $fetcher->fetch(['https://example.com/missing']);

        $this->assertSame('failed', $results[0]['status']);
    }
}
```

**Before finalizing this test and the implementation**, re-read `app/Services/Outbound/SafeOutboundHttpClient.php`'s `get(PendingRequest $request, string $url, int $maxBytes, ...)` signature — it requires a `PendingRequest` as its first argument (obtained via `Http::...` builder methods), not a bare URL call. Adjust the mock/test and the implementation's call site to match the real signature exactly (build a `PendingRequest` via `Http::timeout(10)` or similar, matching however `WordPressRestRequestFactory` — the codebase's existing consumer of this client — constructs its `PendingRequest` before calling `get()`; read that file's call site as the concrete usage template instead of guessing).

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=ReferenceEvidenceFetcherTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Domain\ContentGeneration;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ReferenceEvidenceFetcher
{
    private const MAX_RESPONSE_BYTES = 2_000_000;

    private const MAX_CONTENT_CHARS = 8_000;

    public function __construct(private readonly SafeOutboundHttpClient $client) {}

    /**
     * @param  list<string>  $urls
     * @return list<array{url: string, status: 'fetched'|'failed', content: ?string}>
     */
    public function fetch(array $urls): array
    {
        $results = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $results[] = $this->fetchOne($url);
        }

        return $results;
    }

    /** @return array{url: string, status: 'fetched'|'failed', content: ?string} */
    private function fetchOne(string $url): array
    {
        try {
            $response = $this->client->get(Http::timeout(10), $url, self::MAX_RESPONSE_BYTES);
        } catch (Throwable) {
            return ['url' => $url, 'status' => 'failed', 'content' => null];
        }

        if ($response->status() !== 200) {
            return ['url' => $url, 'status' => 'failed', 'content' => null];
        }

        $text = $this->extractReadableText((string) $response->body());
        if (trim($text) === '') {
            return ['url' => $url, 'status' => 'failed', 'content' => null];
        }

        return ['url' => $url, 'status' => 'fetched', 'content' => mb_substr($text, 0, self::MAX_CONTENT_CHARS)];
    }

    private function extractReadableText(string $html): string
    {
        $html = preg_replace('#<(script|style|nav|header|footer|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
```

**This `extractReadableText()` is a deliberately simple heuristic** (strip script/style/nav/header/footer tags, then strip all remaining tags) — not a full readability algorithm. This is sufficient for evidence text fed to the model (which already ignores irrelevant context reasonably well) and avoids adding a new dependency; do not add a `readability`-style package for this.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test --filter=ReferenceEvidenceFetcherTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/ContentGeneration/ReferenceEvidenceFetcher.php tests/Unit/ReferenceEvidenceFetcherTest.php
git commit -m "feat: add ReferenceEvidenceFetcher for SSRF-safe reference URL fetching"
```

---

## Task 12: `WorkerExecutionService`'s `quality_gated` branch

**Files:**

- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Test: `tests/Feature/WorkerExecutionServiceQualityGatedTest.php`

**Interfaces:**

- Consumes: `ContentBriefQuickCreateService::create()` (Task 7), `ContentGenerationRequestService::reserve()` (existing), `ReferenceEvidenceFetcher::fetch()` (Task 11), the system `Admin` (Task 10).
- Produces: `WorkerExecutionService::executeTask()` now returns `action: 'generate_quality_gated_draft'` (no `article_id`) for `generation_engine=quality_gated` Tasks instead of creating an `Article` synchronously.

Read `app/Services/GeoFlow/WorkerExecutionService.php`'s `executeTask()` (lines 48-201) and `getGenerationBlockReason()` (lines 283-303) in full before starting — you are inserting a branch after title/category selection and extending the draft-limit check, not rewriting the legacy path.

- [ ] **Step 1: Add `core_question`/`reference_urls` to `titles`**

This task's new branch reads `$titleRow->core_question`/`$titleRow->reference_urls`, so add them here rather than in a separate later task (following the same "small prerequisite migration folded into the task that first needs it" pattern used in Task 7 Step 1 for `content_briefs.task_id`). Task 16 (bulk import Task creation) reuses these same two columns.

Create `database/migrations/2026_08_15_000500_add_core_question_and_reference_urls_to_titles.php`. `titles` is a Postgres-raw-SQL-created table with no `updated_at` (per `Title::UPDATED_AT = null`), created in `database/migrations/2026_04_18_120000_geoflow_legacy_schema.php` — a plain `Schema::table()` guarded migration works fine on top of it regardless of how the base table was created:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        Schema::table('titles', function (Blueprint $table): void {
            if (! Schema::hasColumn('titles', 'core_question')) {
                $table->text('core_question')->nullable()->after('keyword');
            }
            if (! Schema::hasColumn('titles', 'reference_urls')) {
                $table->jsonb('reference_urls')->nullable()->after('core_question');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        Schema::table('titles', function (Blueprint $table): void {
            if (Schema::hasColumn('titles', 'reference_urls')) {
                $table->dropColumn('reference_urls');
            }
            if (Schema::hasColumn('titles', 'core_question')) {
                $table->dropColumn('core_question');
            }
        });
    }
};
```

**If the target database driver is SQLite (used by this project's test suite per `database/migrations/2026_04_18_120002_sqlite_geoflow_minimal_for_testing.php`), `jsonb()` is not a valid SQLite column type in some Laravel versions — check whether other nullable JSON columns added to SQLite-backed tables elsewhere in this migration set use `$table->jsonb(...)` or `$table->json(...)`** (grep other post-`2026_04_18`-dated migrations that touch tables also present in the SQLite test schema for the convention actually used) and match that exact method name here instead of assuming `jsonb()` works everywhere.

In `app/Models/Title.php`, add `'core_question', 'reference_urls',` to `$fillable`, and add a `casts()` method (the model currently has no `casts()` at all — add one):

```php
    protected function casts(): array
    {
        return [
            'reference_urls' => 'array',
        ];
    }
```

- [ ] **Step 2: Extend the constructor**

Add three dependencies to `WorkerExecutionService`'s constructor (alongside the existing seven):

```php
        private readonly ContentBriefQuickCreateService $briefQuickCreateService,
        private readonly ContentGenerationRequestService $generationRequests,
        private readonly ReferenceEvidenceFetcher $referenceEvidenceFetcher,
```

Add imports: `use App\Domain\ContentGeneration\ContentGenerationRequestService;`, `use App\Domain\ContentGeneration\ReferenceEvidenceFetcher;`, `use App\Domain\ContentStrategy\ContentBriefQuickCreateService;`, `use App\Models\ContentBrief;`, `use App\Models\ContentGenerationRun;`, `use App\Models\PromptPreset;`.

- [ ] **Step 3: Extend `getGenerationBlockReason()` to count in-flight quality-gated runs**

Quality-gated Tasks don't create an `Article` until a run is adopted (Task 13, asynchronous), so the existing draft-pool check (`Article::where('task_id', ...)->where('status', 'draft')->count()`) alone would undercount in-flight generations and let a Task over-generate past `draft_limit` while runs are still pending/generating/under review. Replace the method:

```php
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '已达到文章总数上限';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftCount = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->count();

        if ((string) ($task->generation_engine ?? 'legacy') === 'quality_gated') {
            $draftCount += ContentGenerationRun::query()
                ->whereIn('content_brief_id', ContentBrief::query()->where('task_id', (int) $task->id)->select('id'))
                ->whereNotIn('status', [ContentGenerationRun::STATUS_ADOPTED, ContentGenerationRun::STATUS_FAILED])
                ->count();
        }

        if ($draftCount >= $draftLimit) {
            return '草稿池已满，等待审核或按间隔发布';
        }

        return null;
    }
```

This preserves the original method's comment about why no row lock is added here (PostgreSQL disallows `FOR UPDATE` on an aggregate `count(*)` query — concurrency is protected by the Task row lock and `task_runs`' single-task serial queue instead), extending it with one more `whereNotIn`-scoped count when the engine is `quality_gated`.

- [ ] **Step 4: Branch `executeTask()` after title/category selection**

Replace this block (originally lines 81-88):

```php
        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeContext = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword);
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);
```

with:

```php
        $titleRow = $this->pickTitle($task);
        $category = $this->pickCategory($task);

        if ((string) ($task->generation_engine ?? 'legacy') === 'quality_gated') {
            return $this->generateQualityGatedDraft($task, $titleRow, $category);
        }

        $author = $this->pickAuthor($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeContext = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword);
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);
```

Everything below this point in `executeTask()` (the legacy generation call through the `DB::transaction(...)` Article creation) is unchanged — it now only runs for `legacy` Tasks, which is already true today since quality-gated Tasks return early above.

- [ ] **Step 5: Add `generateQualityGatedDraft()`**

```php
    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    private function generateQualityGatedDraft(Task $task, Title $titleRow, ?Category $category): array
    {
        $presetId = (int) ($task->prompt_preset_id ?? 0);
        if ($presetId <= 0) {
            throw new RuntimeException('任务未配置质量门禁内容模板');
        }
        $preset = PromptPreset::query()->find($presetId);
        if (! $preset) {
            throw new RuntimeException('任务配置的内容模板不存在');
        }

        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $systemAdmin) {
            throw new RuntimeException('系统管理员账户不存在，请先运行 geoflow:content-strategy:seed-system-admin');
        }

        $referenceUrls = (array) ($titleRow->reference_urls ?? []);
        $fetchedReferences = $referenceUrls !== [] ? $this->referenceEvidenceFetcher->fetch($referenceUrls) : [];

        $brief = $this->briefQuickCreateService->create([
            'topic' => (string) $titleRow->title,
            'core_question' => $titleRow->core_question,
            'category_id' => $category?->id,
            'reference_urls' => $referenceUrls,
            'task_id' => (int) $task->id,
        ], $preset, $systemAdmin);

        $strategyVersion = $brief->strategyVersions()->latest('version')->firstOrFail();
        $blueprintVersion = $brief->blueprintVersions()->latest('version')->firstOrFail();
        $presetVersion = $preset->currentVersion;
        if ($presetVersion === null) {
            throw new RuntimeException('任务配置的内容模板没有可用版本');
        }

        $reservation = $this->generationRequests->reserve($brief, [
            'strategy_version_id' => $strategyVersion->id,
            'blueprint_version_id' => $blueprintVersion->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => (int) $task->ai_model_id,
            'evidence' => array_values(array_filter(
                $fetchedReferences,
                static fn (array $reference): bool => $reference['status'] === 'fetched',
            )),
        ], $systemAdmin);

        Title::query()->whereKey($titleRow->id)->increment('used_count');
        Title::query()->whereKey($titleRow->id)->increment('usage_count');

        return [
            'article_id' => null,
            'title' => (string) $titleRow->title,
            'message' => '质量门禁生成任务已提交',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_quality_gated_draft',
                'title_id' => (int) $titleRow->id,
                'content_brief_id' => (int) $brief->id,
                'content_generation_run_id' => (int) $reservation['run']->id,
            ],
        ];
    }
```

**Before finalizing this step, re-check `ContentGenerationRequestService::reserve()`'s `$data` array contract** (`app/Domain/ContentGeneration/ContentGenerationRequestService.php:36`, `array{strategy_version_id: int, blueprint_version_id: int, preset_version_id: int, ai_model_id: int, idempotency_key?: string|null}` plus the `knowledge_chunk_ids`/`evidence` keys read inside `reserve()`'s body via `$data['knowledge_chunk_ids'] ?? []`/`$data['evidence'] ?? []`) — confirm the `evidence` array's expected per-item shape by reading `GenerationEvidenceSnapshotter::snapshot()` (`app/Domain/ContentGeneration/GenerationEvidenceSnapshotter.php`, injected into `ContentGenerationRequestService`) before assuming `['url' => ..., 'status' => ..., 'content' => ...]` (this task's `ReferenceEvidenceFetcher::fetch()` shape) is accepted as-is; if the snapshotter expects a different shape (e.g. `['content' => ..., 'source' => ..., 'relevance' => ...]`), map `$fetchedReferences` into that shape before passing it as `'evidence'`.

- [ ] **Step 6: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentGenerationRun;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkerExecutionServiceQualityGatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quality_gated_task_creates_a_brief_and_reserves_a_run_instead_of_an_article(): void
    {
        config(['geoflow.content_strategy_enabled' => true, 'geoflow.modular_prompts_enabled' => true, 'geoflow.generation_quality_gate_enabled' => true]);
        Admin::query()->create([
            'username' => 'geoflow-system', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system', 'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.worker-quality-gated', 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => [
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'answer', 'heading' => 'Direct answer', 'purpose' => 'Answer it.',
                    'questions_to_answer' => ['{{core_question}}'], 'minimum_words' => 100,
                    'cta_allowed' => false, 'required_fact_ids' => [],
                ]],
                'required_entities' => [], 'show_references_section' => false,
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $aiModel = AiModel::query()->create([
            'name' => 'Test model', 'status' => 'active', 'model_type' => 'chat',
            'api_url' => 'https://example.invalid', 'api_key' => 'test', 'model_id' => 'test-model',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $title = Title::query()->create(['library_id' => $library->id, 'title' => 'How to Choose Running Shoes', 'core_question' => 'What matters most?']);
        $task = Task::query()->create([
            'name' => 'Quality gated task', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
            'status' => 'active', 'schedule_enabled' => 1, 'article_limit' => 10, 'draft_limit' => 5,
        ]);

        $result = $this->app->make(WorkerExecutionService::class)->executeTask((int) $task->id);

        $this->assertNull($result['article_id']);
        $this->assertSame('generate_quality_gated_draft', $result['meta']['action']);
        $this->assertTrue(ContentGenerationRun::query()->whereKey($result['meta']['content_generation_run_id'])->exists());
        $this->assertSame(1, $title->fresh()->used_count);
    }
}
```

Confirm `AiModel`'s actual required fields/fillable before this test runs — read `app/Models/AiModel.php` and adjust the `create([...])` call to match its real `$fillable` list and any NOT NULL columns not shown here (e.g. `api_key` may need to be pre-encrypted via a mutator, or a `provider` column may be required — check before assuming this exact array works).

- [ ] **Step 7: Run the test**

Run: `docker compose exec -T app php artisan test --filter=WorkerExecutionServiceQualityGatedTest`
Expected: PASS

- [ ] **Step 8: Run the legacy regression suite**

Run: `docker compose exec -T app php artisan test --filter=WorkerExecutionService`
Expected: PASS unchanged.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_15_000500_add_core_question_and_reference_urls_to_titles.php app/Models/Title.php app/Services/GeoFlow/WorkerExecutionService.php tests/Feature/WorkerExecutionServiceQualityGatedTest.php
git commit -m "feat: route quality_gated Tasks through the content-brief pipeline instead of a single-shot call"
```

---

## Task 13: Auto-adopt command for Task-driven runs

**Files:**

- Create: `app/Console/Commands/AdoptTaskDrivenGenerationRunsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/AdoptTaskDrivenGenerationRunsCommandTest.php`

**Interfaces:**

- Consumes: `GeneratedArticleParser::extractTitle()` (Task 2), `GenerationAdoptionService::adopt()` (existing, `app/Domain/ContentGeneration/GenerationAdoptionService.php:23`), the system `Admin` (Task 10), `ContentBrief.generationRuns()` (Task 7 Step 1).
- Produces: every `ready_for_review` run belonging to a Task-driven brief becomes an adopted `Article` (`status=draft, review_status=pending, task_id` set) within one minute, without human interaction — this is what makes Task 12's asynchronous dispatch eventually turn into something Task's existing `need_review`/`publish_interval` logic can act on.

Read `app/Domain/ContentGeneration/GenerationAdoptionService.php` in full (confirmed earlier: `adopt(ContentGenerationRun $run, array $articleData, Admin $actor): Article`, requires `status === STATUS_READY_FOR_REVIEW`, creates the `Article` via `ArticleGeoFlowService::createArticle()` which accepts `task_id` in its data array) and `app/Console/Commands/RecoverContentGenerationDispatchesCommand.php` in full (the cadence/structure template for this new command) before starting.

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\GeneratedArticleParser;
use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class AdoptTaskDrivenGenerationRunsCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:adopt-task-driven-runs {--limit=100}';

    protected $description = 'Auto-adopt ready-for-review generation runs that belong to a quality-gated Task';

    public function handle(GenerationAdoptionService $adoptions, GeneratedArticleParser $parser): int
    {
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $systemAdmin) {
            $this->error('No geoflow-system admin found — run "geoflow:content-strategy:seed-system-admin" first.');

            return self::FAILURE;
        }

        $runs = ContentGenerationRun::query()
            ->where('status', ContentGenerationRun::STATUS_READY_FOR_REVIEW)
            ->whereIn('content_brief_id', ContentBrief::query()->whereNotNull('task_id')->select('id'))
            ->with(['contentBrief.task', 'contentBrief.category'])
            ->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get();

        $adopted = 0;
        foreach ($runs as $run) {
            $task = $run->contentBrief?->task;
            if ($task === null || $task->status !== 'active') {
                continue;
            }

            try {
                $this->adoptOne($run, $adoptions, $parser, $systemAdmin);
                $adopted++;
            } catch (DomainException $exception) {
                Log::warning('Task-driven run could not be auto-adopted', [
                    'content_generation_run_id' => $run->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Auto-adopted {$adopted} task-driven generation run(s).");

        return self::SUCCESS;
    }

    private function adoptOne(ContentGenerationRun $run, GenerationAdoptionService $adoptions, GeneratedArticleParser $parser, Admin $systemAdmin): void
    {
        $extracted = $parser->extractTitle((string) $run->raw_output);
        if ($extracted['title'] === null) {
            Log::warning('Task-driven run reached ready_for_review without a leading H1 title; leaving for manual review', [
                'content_generation_run_id' => $run->id,
            ]);

            return;
        }

        $content = $this->appendReferencesSection($run, $extracted['content']);
        $brief = $run->contentBrief;
        $task = $brief->task;

        $adoptions->adopt($run, [
            'title' => $extracted['title'],
            'content' => $content,
            'category_id' => $brief->category_id ?? $task->fixed_category_id,
            'author_id' => (int) ($task->custom_author_id ?: $task->author_id) ?: null,
            'task_id' => (int) $task->id,
        ], $systemAdmin);
    }

    private function appendReferencesSection(ContentGenerationRun $run, string $content): string
    {
        $blueprint = (array) ($run->blueprintVersion->blueprint ?? []);
        if (! (bool) ($blueprint['show_references_section'] ?? false)) {
            return $content;
        }

        $referenceUrls = (array) ($run->contentBrief->reference_urls ?? []);
        if ($referenceUrls === []) {
            return $content;
        }

        $links = array_map(static fn (string $url): string => '- '.trim($url), $referenceUrls);

        return $content."\n\n## References\n".implode("\n", $links);
    }
}
```

`GenerationAdoptionService::adopt()` requires `author_id` per `AdoptContentGenerationRequest`'s validation — but that validation only applies to the *human* `/adopt` HTTP route (`AdoptContentGenerationRequest`), not to `adopt()` itself, which is called here directly with a plain array, bypassing the FormRequest entirely (confirmed: `adopt()`'s only hard requirements are `status === STATUS_READY_FOR_REVIEW` and non-empty `raw_output`). Still, `ArticleGeoFlowService::normalizeCreateInput()`'s `author_id` is resolved via `normalizeReference(Author::class, ..., true)` — **before finalizing this task, read that method's `true` third argument's effect** (it likely means "required," which would reject a `null` `author_id`) and, if so, fall back to the same `pickAuthor()`-equivalent logic `WorkerExecutionService` already uses (an active `Author`, or `Task.author_id`) rather than passing `null` — reuse `Task.author_id` directly here since `WorkerExecutionService::pickAuthor()` is `private` and not reusable from this command; if `Task.author_id` is itself null for a given Task, query the first available `Author` the same way `pickAuthor()`'s final fallback does (`Author::query()->orderBy('id')->first()`), and if that's also empty, `firstOrCreate` the same `GEOFlow` default author `pickAuthor()` creates.

Note the `## References` heading rendered by `appendReferencesSection()` deliberately runs *after* adoption's own quality-gate pass (it appends to `$content` only at adoption time, never seen by `DeterministicQualityGate::inspect()`) — this achieves the spec's "references section is not subject to the quality gate's checks" requirement without needing the gate's section-count logic to special-case an "acknowledged extra section," which would have added meaningful complexity to Task 1's already-substantial rewrite for no user-visible difference (the final published article looks identical either way).

- [ ] **Step 2: Register the schedule**

In `routes/console.php`, add (matching the existing `geoflow:recover-content-generation-dispatches` entry's exact pattern):

```php
Schedule::command('geoflow:content-strategy:adopt-task-driven-runs')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);
```

- [ ] **Step 3: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdoptTaskDrivenGenerationRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_adopts_a_ready_for_review_run_and_extracts_the_ai_title(): void
    {
        $systemAdmin = Admin::query()->create([
            'username' => 'geoflow-system', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system', 'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $task = Task::query()->create([
            'name' => 'Test task', 'title_library_id' => $library->id, 'status' => 'active',
            'generation_engine' => 'quality_gated', 'schedule_enabled' => 1,
        ]);
        $brief = ContentBrief::query()->create([
            'topic' => 'Running shoes', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
            'task_id' => $task->id,
        ]);
        $strategy = ContentStrategyVersion::query()->forceCreate([
            'content_brief_id' => $brief->id, 'version' => 1, 'strategy' => [],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::query()->forceCreate([
            'content_brief_id' => $brief->id, 'content_strategy_version_id' => $strategy->id, 'version' => 1,
            'blueprint' => ['working_title' => 'Running shoes', 'target_length' => 100, 'sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $run = ContentGenerationRun::query()->forceCreate([
            'content_brief_id' => $brief->id, 'strategy_version_id' => $strategy->id, 'blueprint_version_id' => $blueprint->id,
            'raw_output' => "# How to Choose Running Shoes\n\nBody text about running shoes.",
            'status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW,
            'idempotency_key' => 'test-'.uniqid(),
        ]);

        $this->artisan('geoflow:content-strategy:adopt-task-driven-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentGenerationRun::STATUS_ADOPTED, $run->status);
        $article = Article::query()->findOrFail($run->article_id);
        $this->assertSame('How to Choose Running Shoes', $article->title);
        $this->assertStringNotContainsString('# How to Choose Running Shoes', $article->content);
        $this->assertSame((int) $task->id, $article->task_id);
    }

    public function test_does_not_adopt_runs_belonging_to_a_paused_task(): void
    {
        // Same setup as above but $task->status = 'paused' — assert the run stays ready_for_review.
        // (Implementer: build this by copying the happy-path setup with one field changed, not a new fixture.)
    }
}
```

(This test constructs `ContentGenerationRun`/`ContentBlueprintVersion` rows directly via `forceCreate()` rather than going through the full reservation pipeline — check whether `ContentGenerationRun`'s NOT NULL columns beyond what's shown here (e.g. `preset_version_id`, `ai_model_id`) actually allow `null`/omission; if the schema requires them, create minimal `PromptPresetVersion`/`AiModel` fixtures first and include their IDs, following the same minimal-fixture pattern used in Task 12's test.)

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T app php artisan test --filter=AdoptTaskDrivenGenerationRunsCommandTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/AdoptTaskDrivenGenerationRunsCommand.php routes/console.php tests/Feature/AdoptTaskDrivenGenerationRunsCommandTest.php
git commit -m "feat: auto-adopt task-driven generation runs with AI-extracted titles"
```

---

## Task 14: `TaskController` admin form — `generation_engine`/`prompt_preset_id`

**Files:**

- Modify: `app/Http/Controllers/Admin/TaskController.php`, `resources/views/admin/tasks/form.blade.php`
- Test: `tests/Feature/TaskControllerGenerationEngineTest.php`

**Interfaces:**

- Consumes: `Task.generation_engine`/`prompt_preset_id` (Task 9).
- Produces: `/geo_admin/tasks/create` and `/geo_admin/tasks/{task}/edit` let an operator pick `quality_gated`, only when a preset with `blueprint_template` exists **and** the three `geoflow.*` feature flags are enabled server-side — this option is validated server-side regardless of what the client renders, per the Global Constraints note that `tasks` routes aren't behind the feature-flag middleware gate.

Read `app/Http/Controllers/Admin/TaskController.php`'s `validateTaskForm()` (lines 558-584), `buildTaskPayload()` (lines 590-623), and `loadTaskFormOptions()` (lines 437-535) in full before starting.

- [ ] **Step 1: Extend validation**

In `validateTaskForm()`, add:

```php
            'generation_engine' => ['nullable', 'string', 'in:legacy,quality_gated'],
            'prompt_preset_id' => ['nullable', 'integer', 'min:1'],
```

Immediately after the `$request->validate([...])` call, add a cross-field check (the request-level `in:legacy,quality_gated` rule alone can't express "quality_gated requires the pipeline to be enabled and a preset to be chosen" — a `Validator::extend`/`withValidator` closure or a plain post-validation check both work; use a plain post-validation check here since `validateTaskForm()` is a single method returning the validated array, not a FormRequest class with a `withValidator()` hook):

```php
        $generationEngine = (string) ($validated['generation_engine'] ?? 'legacy');
        if ($generationEngine === 'quality_gated') {
            $pipelineEnabled = (bool) config('geoflow.content_strategy_enabled', false)
                && (bool) config('geoflow.modular_prompts_enabled', false)
                && (bool) config('geoflow.generation_quality_gate_enabled', false);
            if (! $pipelineEnabled) {
                throw ValidationException::withMessages(['generation_engine' => '质量门禁生成流水线当前未启用']);
            }
            $presetId = (int) ($validated['prompt_preset_id'] ?? 0);
            $hasTemplate = $presetId > 0 && PromptPreset::query()
                ->whereKey($presetId)
                ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
                ->exists();
            if (! $hasTemplate) {
                throw ValidationException::withMessages(['prompt_preset_id' => '请选择一个已配置内容蓝图模板的预设']);
            }
        }
```

Add imports: `use App\Models\PromptPreset;`, `use App\Models\PromptPresetVersion;`, `use Illuminate\Validation\ValidationException;` (check whether `ValidationException` is already imported in this file before adding a duplicate `use`).

Also relax the existing `'prompt_id' => ['required', 'integer', 'min:1'],` rule already present in `validateTaskForm()` to `['required_if:generation_engine,legacy', 'nullable', 'integer', 'min:1']` — a `quality_gated` Task has no free-text `Prompt`, so it must not be forced to submit one.

- [ ] **Step 2: `TaskLifecycleService` — the actual field allowlist `TaskController` calls through**

`TaskController::store()`/`update()` do not call `Task::create()`/`Task::update()` directly — they call `TaskLifecycleService::createTask()`/`updateTask()` (`app/Services/GeoFlow/TaskLifecycleService.php:73,155`), which run their own independent `normalizeTaskInput()` validation and field allowlist. Without changes here, `generation_engine`/`prompt_preset_id` submitted by the form in Step 4 would be silently dropped, and `prompt_id`'s existing `'required' => ! $isUpdate` rule inside this service (line ~435) would reject *every* Task creation that omits it — including quality-gated ones — regardless of Step 1's HTTP-layer relaxation. Read `app/Services/GeoFlow/TaskLifecycleService.php`'s `normalizeTaskInput()` (lines 417-500-ish, read the full method) and `createTask()` (lines 73-121) in full before starting.

In `normalizeTaskInput()`, change the `prompt_id` entry in `$referenceMap`'s `'required'` value from `! $isUpdate` to `! $isUpdate && (string) ($data['generation_engine'] ?? 'legacy') !== 'quality_gated'`.

Add `generation_engine`/`prompt_preset_id` handling in the same method, following the existing pattern for a plain validated field (not a foreign-key reference, since `generation_engine` is an enum string) — add near the top, alongside the `name` handling:

```php
        $generationEngine = (string) ($data['generation_engine'] ?? ($isUpdate ? 'legacy' : 'legacy'));
        if (array_key_exists('generation_engine', $data) || ! $isUpdate) {
            if (! in_array($generationEngine, ['legacy', 'quality_gated'], true)) {
                $fieldErrors['generation_engine'] = '生成引擎无效';
            } else {
                $output['generation_engine'] = $generationEngine;
            }
        }

        if ($generationEngine === 'quality_gated') {
            $presetId = (int) ($data['prompt_preset_id'] ?? 0);
            if ($presetId <= 0 || ! PromptPreset::query()->whereKey($presetId)->exists()) {
                $fieldErrors['prompt_preset_id'] = '请选择内容模板';
            } else {
                $output['prompt_preset_id'] = $presetId;
            }
        } elseif (array_key_exists('prompt_preset_id', $data)) {
            $output['prompt_preset_id'] = null;
        }
```

(Match this against the file's actual `$fieldErrors`-throwing convention at the end of `normalizeTaskInput()` — read how the method turns a non-empty `$fieldErrors` into an exception, e.g. `if ($fieldErrors !== []) { throw new ApiException(...); }`, and confirm this new code runs *before* that check, not after.)

Add `use App\Models\PromptPreset;` to this file's imports if not already present.

In `createTask()`, add two lines to the `Task::query()->create([...])` array:

```php
                'generation_engine' => $normalized['generation_engine'] ?? 'legacy',
                'prompt_preset_id' => $normalized['prompt_preset_id'] ?? null,
```

`updateTask()` needs no code change — it already applies whatever `normalizeTaskInput()` puts in `$output` via a dynamic `Task::query()->update($normalized)` call, so the two new keys flow through automatically once `normalizeTaskInput()` produces them.

- [ ] **Step 3: Extend `buildTaskPayload()`**

Add two lines to the returned array (matching the existing style, e.g. next to `'model_selection_mode' => ...`):

```php
            'generation_engine' => (string) ($data['generation_engine'] ?? 'legacy'),
            'prompt_preset_id' => $generationEngineIsQualityGated ? (int) $data['prompt_preset_id'] : null,
```

(Read the actual method body first — `buildTaskPayload()` is passed `$data` from `validateTaskForm()`'s return value, and the exact local-variable name for "is this task quality_gated" needs to match whatever `validateTaskForm()` actually returns; a clean approach is deriving it fresh here: `$generationEngineIsQualityGated = (string) ($data['generation_engine'] ?? 'legacy') === 'quality_gated';` as a new line at the top of `buildTaskPayload()`, then using it in the array as shown.)

- [ ] **Step 4: Pass quick-create-eligible presets to the form**

In `loadTaskFormOptions()`, add one more entry to whatever array/object it returns (matching its existing style — every other dropdown source in this method already follows one consistent pattern, e.g. `->get(['id', 'name'])`):

```php
'qualityGatedPresets' => PromptPreset::query()
    ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
    ->orderBy('display_name')
    ->get(['id', 'display_name']),
'qualityGatedEngineAvailable' => (bool) config('geoflow.content_strategy_enabled', false)
    && (bool) config('geoflow.modular_prompts_enabled', false)
    && (bool) config('geoflow.generation_quality_gate_enabled', false),
```

- [ ] **Step 5: Extend the Blade form**

In `resources/views/admin/tasks/form.blade.php`, find the "内容配置" section's `prompt_id` select. Wrap the engine choice around it — read the existing markup for the `prompt_id`/`ai_model_id` fields first to match indentation/classes exactly, then add, immediately above that field:

```blade
@if ($qualityGatedEngineAvailable)
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700">生成引擎</label>
        <select name="generation_engine" x-data="" x-on:change="$el.closest('form').querySelector('[data-legacy-prompt-field]').classList.toggle('hidden', $el.value === 'quality_gated'); $el.closest('form').querySelector('[data-quality-gated-preset-field]').classList.toggle('hidden', $el.value !== 'quality_gated')" class="mt-1 w-full rounded-md border-gray-300">
            <option value="legacy" {{ old('generation_engine', $task->generation_engine ?? 'legacy') === 'legacy' ? 'selected' : '' }}>传统（固定模板，单次生成）</option>
            <option value="quality_gated" {{ old('generation_engine', $task->generation_engine ?? 'legacy') === 'quality_gated' ? 'selected' : '' }}>质量门禁（AI 结构化生成，自动质检）</option>
        </select>
    </div>
    <div data-quality-gated-preset-field class="mb-4 {{ old('generation_engine', $task->generation_engine ?? 'legacy') === 'quality_gated' ? '' : 'hidden' }}">
        <label class="block text-sm font-medium text-gray-700">内容模板</label>
        <select name="prompt_preset_id" class="mt-1 w-full rounded-md border-gray-300">
            <option value="">— 选择内容模板 —</option>
            @foreach ($qualityGatedPresets as $preset)
                <option value="{{ $preset->id }}" {{ (int) old('prompt_preset_id', $task->prompt_preset_id ?? 0) === $preset->id ? 'selected' : '' }}>{{ $preset->display_name }}</option>
            @endforeach
        </select>
    </div>
@endif
<div data-legacy-prompt-field class="mb-4 {{ ($qualityGatedEngineAvailable && old('generation_engine', $task->generation_engine ?? 'legacy') === 'quality_gated') ? 'hidden' : '' }}">
    {{-- existing prompt_id select markup goes here, unmodified --}}
</div>
```

Move the *existing* `prompt_id` field's markup (unchanged) inside the new `data-legacy-prompt-field` wrapper `<div>` rather than duplicating it — this is a wrap, not a rewrite of that field. Check whether this project's Blade views already load Alpine.js globally (grep `resources/views/layouts` for `alpinejs` or `x-data` usage elsewhere) before using `x-on:change`/inline Alpine — if Alpine isn't already a dependency here, use a small vanilla-JS `<script>` block with an `addEventListener('change', ...)` instead, matching whatever client-side JS convention the rest of `resources/views/admin/tasks/form.blade.php` already uses (check the top/bottom of that file for an existing `@push('scripts')` block and add to it rather than introducing a new pattern).

- [ ] **Step 6: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskControllerGenerationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_quality_gated_task_requires_the_pipeline_to_be_enabled(): void
    {
        config(['geoflow.content_strategy_enabled' => false]);
        $admin = Admin::query()->create([
            'username' => 'task-engine-tester', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'super_admin', 'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.tasks.store'), [
            'task_name' => 'Test', 'title_library_id' => $library->id, 'ai_model_id' => 1,
            'status' => 'paused', 'generation_engine' => 'quality_gated',
        ]);

        $response->assertSessionHasErrors('generation_engine');
    }

    public function test_creating_a_quality_gated_task_succeeds_with_a_valid_preset(): void
    {
        config(['geoflow.content_strategy_enabled' => true, 'geoflow.modular_prompts_enabled' => true, 'geoflow.generation_quality_gate_enabled' => true]);
        $admin = Admin::query()->create([
            'username' => 'task-engine-tester-2', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'super_admin', 'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $preset = PromptPreset::query()->create(['preset_key' => 'test.task-engine', 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.tasks.store'), [
            'task_name' => 'Test', 'title_library_id' => $library->id, 'ai_model_id' => 1,
            'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
    }
}
```

(`ai_model_id` here is a bare `1` with no corresponding row — check whether `validateTaskForm()`'s `ai_model_id` rule actually requires `exists:ai_models,id` or just `integer|min:1`; per the rule list confirmed during planning it's only `['required', 'integer', 'min:1']`, no existence check, so this should be fine as-is, but verify against the real current rule set before trusting this test.)

- [ ] **Step 7: Run the tests**

Run: `docker compose exec -T app php artisan test --filter=TaskControllerGenerationEngineTest`
Expected: PASS

- [ ] **Step 8: Run the existing Task controller and TaskLifecycleService regression suites**

Run: `docker compose exec -T app php artisan test --filter=TaskController`
Run: `docker compose exec -T app php artisan test --filter=TaskLifecycleService`
Expected: PASS unchanged — every `legacy`-engine (i.e. every existing) Task creation/update must behave identically, including still rejecting a creation that omits `prompt_id` when `generation_engine` is left at its `legacy` default.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/TaskController.php app/Services/GeoFlow/TaskLifecycleService.php resources/views/admin/tasks/form.blade.php tests/Feature/TaskControllerGenerationEngineTest.php
git commit -m "feat: let operators choose the quality-gated generation engine on a Task"
```

---

## Task 15: Bulk import — spreadsheet parsing and row validation

**Files:**

- Create: `app/Domain/ContentGeneration/BulkContentImportRow.php`, `app/Domain/ContentGeneration/BulkContentImportParser.php`
- Test: `tests/Unit/BulkContentImportParserTest.php`

**Interfaces:**

- Consumes: `phpoffice/phpspreadsheet` (already a dependency, confirmed in `composer.json`).
- Produces: `BulkContentImportParser::parse(string $filePath): array{rows: list<BulkContentImportRow>, errors: list<array{row: int, message: string}>}`. Consumed by Task 16 (Task/Title creation) and reused by Task 17 (validating the reference-values sheet stays in sync, indirectly).

**Import sheet contract** (locked in from the design conversation): row 1 = column keys, row 2 = Chinese instructions (skipped when parsing — identified by position, always row 2), rows 3+ = data. Required columns: `site`, `preset`, `category`, `topic`, `knowledge_base`. Optional columns: `core_question`, `primary_keyword`, `reference_urls`.

To let a dropdown-constrained cell resolve unambiguously to a database row without the operator ever typing a raw key, `site` and `preset` cells contain `"{display label} ({machine key})"` (the exact string Task 17's generated dropdown list also uses, so what an operator picks from the dropdown is always parseable) — e.g. `site` cell: `"LITB Style Blog (blog.lightinthebox.com)"` (channel name + domain in parentheses), `preset` cell: `"Category Authority Guide (litb.geo_category_authority.en)"` (preset display name + `preset_key` in parentheses). `category` and `knowledge_base` cells contain the plain name (no parenthetical) — resolved by exact, case-sensitive name match, since neither has a stable machine key exposed elsewhere in the admin UI the way channels/presets do.

- [ ] **Step 1: Write the DTO**

```php
<?php

namespace App\Domain\ContentGeneration;

final readonly class BulkContentImportRow
{
    /**
     * @param  list<string>  $referenceUrls
     */
    public function __construct(
        public int $rowNumber,
        public int $distributionChannelId,
        public int $promptPresetId,
        public int $categoryId,
        public int $knowledgeBaseId,
        public string $topic,
        public ?string $coreQuestion,
        public ?string $primaryKeyword,
        public array $referenceUrls,
    ) {}

    public function groupKey(): string
    {
        return "{$this->distributionChannelId}:{$this->promptPresetId}:{$this->categoryId}";
    }
}
```

- [ ] **Step 2: Write the failing test**

Build a small fixture spreadsheet inline in the test using `PhpOffice\PhpSpreadsheet\Spreadsheet` directly (do not check in a binary `.xlsx` fixture file — build and save it to a temp path at test time, matching how a test that needs a real file typically works in this codebase; check `tests/` for an existing precedent of writing a temp file in `setUp()`/a test method and adapt that pattern if one exists, otherwise use PHP's own `tempnam(sys_get_temp_dir(), 'bulk-import-')`).

```php
<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\BulkContentImportParser;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class BulkContentImportParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_valid_rows_and_resolves_site_preset_category_knowledge_base(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com',
            'channel_type' => 'wordpress', 'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'Category Authority Guide', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $category = Category::query()->create(['name' => 'Style & Buying Guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'LITB Product KB', 'content' => 'x', 'review_status' => 'reviewed']);

        $path = $this->buildFixture([
            ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'],
            ['【必填】...', '【必填】...', '【必填】...', '【必填】...', '【必填】...', '【选填】...', '【选填】...', '【选填】...'],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'How to Choose Running Shoes', 'LITB Product KB', 'What matters most?', 'running shoes', "https://example.com/a\nhttps://example.com/b"],
        ]);

        $result = $this->app->make(BulkContentImportParser::class)->parse($path);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame($channel->id, $row->distributionChannelId);
        $this->assertSame($preset->id, $row->promptPresetId);
        $this->assertSame($category->id, $row->categoryId);
        $this->assertSame($knowledgeBase->id, $row->knowledgeBaseId);
        $this->assertSame('How to Choose Running Shoes', $row->topic);
        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $row->referenceUrls);
    }

    public function test_reports_an_error_for_an_unresolvable_site_without_processing_any_row(): void
    {
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'x', 'is_system' => true, 'is_enabled' => true]);
        $category = Category::query()->create(['name' => 'Style & Buying Guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'KB', 'content' => 'x', 'review_status' => 'reviewed']);

        $path = $this->buildFixture([
            ['site', 'preset', 'category', 'topic', 'knowledge_base'],
            ['instructions'],
            ['Unknown Site (nowhere.example.com)', 'x (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic', 'KB'],
        ]);

        $result = $this->app->make(BulkContentImportParser::class)->parse($path);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(3, $result['errors'][0]['row']);
    }

    /** @param list<list<string>> $rows */
    private function buildFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `docker compose exec -T app php artisan test --filter=BulkContentImportParserTest`
Expected: FAIL — `BulkContentImportParser` not found.

- [ ] **Step 4: Write the parser**

```php
<?php

namespace App\Domain\ContentGeneration;

use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class BulkContentImportParser
{
    private const HEADER_ROW = 1;

    private const INSTRUCTION_ROW = 2;

    private const FIRST_DATA_ROW = 3;

    /**
     * @return array{rows: list<BulkContentImportRow>, errors: list<array{row: int, message: string}>}
     */
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();

        $columnIndexByKey = [];
        foreach ($sheet->rangeToArray('A'.self::HEADER_ROW.':'.$sheet->getHighestDataColumn().self::HEADER_ROW)[0] as $index => $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $columnIndexByKey[$key] = $index;
            }
        }
        foreach (['site', 'preset', 'category', 'topic', 'knowledge_base'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columnIndexByKey)) {
                return ['rows' => [], 'errors' => [['row' => self::HEADER_ROW, 'message' => "Missing required column \"{$requiredColumn}\"."]]];
            }
        }

        $rows = [];
        $errors = [];
        for ($rowNumber = self::FIRST_DATA_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $rawRow = $sheet->rangeToArray('A'.$rowNumber.':'.$sheet->getHighestDataColumn().$rowNumber)[0];
            if ($this->isBlankRow($rawRow)) {
                continue;
            }

            $result = $this->parseRow($rowNumber, $rawRow, $columnIndexByKey);
            if (isset($result['error'])) {
                $errors[] = ['row' => $rowNumber, 'message' => $result['error']];

                continue;
            }
            $rows[] = $result['row'];
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    /**
     * @param  list<mixed>  $rawRow
     * @param  array<string, int>  $columnIndexByKey
     * @return array{row: BulkContentImportRow}|array{error: string}
     */
    private function parseRow(int $rowNumber, array $rawRow, array $columnIndexByKey): array
    {
        $get = fn (string $key): string => trim((string) ($rawRow[$columnIndexByKey[$key]] ?? ''));

        $siteCell = $get('site');
        $channel = $this->resolveByParenthetical($siteCell, fn (string $domain) => DistributionChannel::query()->where('domain', $domain)->first());
        if ($channel === null) {
            return ['error' => "Could not resolve site \"{$siteCell}\" to a distribution channel."];
        }

        $presetCell = $get('preset');
        $preset = $this->resolveByParenthetical($presetCell, fn (string $key) => PromptPreset::query()->where('preset_key', $key)->first());
        if ($preset === null || $preset->currentVersion === null || $preset->currentVersion->blueprint_template === null) {
            return ['error' => "Could not resolve preset \"{$presetCell}\" to a preset with a blueprint template."];
        }

        $categoryName = $get('category');
        $category = Category::query()->where('name', $categoryName)->first();
        if ($category === null) {
            return ['error' => "Could not resolve category \"{$categoryName}\"."];
        }

        $knowledgeBaseName = $get('knowledge_base');
        $knowledgeBase = KnowledgeBase::query()->where('name', $knowledgeBaseName)->first();
        if ($knowledgeBase === null) {
            return ['error' => "Could not resolve knowledge base \"{$knowledgeBaseName}\"."];
        }

        $topic = $get('topic');
        if ($topic === '') {
            return ['error' => 'topic is required.'];
        }

        $referenceUrls = array_values(array_filter(array_map('trim', explode("\n", $get('reference_urls')))));

        return ['row' => new BulkContentImportRow(
            rowNumber: $rowNumber,
            distributionChannelId: (int) $channel->id,
            promptPresetId: (int) $preset->id,
            categoryId: (int) $category->id,
            knowledgeBaseId: (int) $knowledgeBase->id,
            topic: $topic,
            coreQuestion: ($get('core_question') !== '') ? $get('core_question') : null,
            primaryKeyword: ($get('primary_keyword') !== '') ? $get('primary_keyword') : null,
            referenceUrls: $referenceUrls,
        )];
    }

    private function resolveByParenthetical(string $cell, \Closure $resolve): mixed
    {
        if (preg_match('/\(([^()]+)\)\s*$/u', $cell, $matches) !== 1) {
            return null;
        }

        return $resolve(trim($matches[1]));
    }

    /** @param list<mixed> $row */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
```

**Design choice, stated explicitly**: this parser returns *zero* rows and only the accumulated errors as soon as any row fails to resolve — "a row referencing an unknown channel fails validation before any generation is attempted, not after" (per the spec). It intentionally does not attempt same-group-conflict detection for `knowledge_base` here (per the spec, rows in the same `(site, preset, category)` group with different `knowledge_base` values are a conflict) — add that check as Step 5 below, in the same file, since it needs the full parsed row list before it can group anything.

- [ ] **Step 5: Add the same-group `knowledge_base` conflict check**

Add a second public method, called by Task 16's Task-creation step (kept separate from `parse()` so the two concerns — "can each row resolve?" vs. "are the resolved rows internally consistent as groups?" — stay independently testable):

```php
    /**
     * @param  list<BulkContentImportRow>  $rows
     * @return list<array{row: int, message: string}>
     */
    public function conflicts(array $rows): array
    {
        $knowledgeBaseIdByGroup = [];
        $errors = [];
        foreach ($rows as $row) {
            $groupKey = $row->groupKey();
            if (! array_key_exists($groupKey, $knowledgeBaseIdByGroup)) {
                $knowledgeBaseIdByGroup[$groupKey] = $row->knowledgeBaseId;

                continue;
            }
            if ($knowledgeBaseIdByGroup[$groupKey] !== $row->knowledgeBaseId) {
                $errors[] = ['row' => $row->rowNumber, 'message' => 'knowledge_base differs from an earlier row in the same site/preset/category group.'];
            }
        }

        return $errors;
    }
```

Add a test for this to `BulkContentImportParserTest`:

```php
    public function test_conflicts_flags_a_differing_knowledge_base_within_the_same_group(): void
    {
        $parser = $this->app->make(BulkContentImportParser::class);
        $rowA = new \App\Domain\ContentGeneration\BulkContentImportRow(3, 1, 1, 1, 10, 'Topic A', null, null, []);
        $rowB = new \App\Domain\ContentGeneration\BulkContentImportRow(4, 1, 1, 1, 20, 'Topic B', null, null, []);

        $errors = $parser->conflicts([$rowA, $rowB]);

        $this->assertCount(1, $errors);
        $this->assertSame(4, $errors[0]['row']);
    }
```

- [ ] **Step 6: Run the tests**

Run: `docker compose exec -T app php artisan test --filter=BulkContentImportParserTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Domain/ContentGeneration/BulkContentImportRow.php app/Domain/ContentGeneration/BulkContentImportParser.php tests/Unit/BulkContentImportParserTest.php
git commit -m "feat: add bulk content import spreadsheet parser and row validation"
```

---

## Task 16: Bulk import — Task creation, admin controller, routes, UI

**Files:**

- Create: `app/Domain/ContentGeneration/BulkContentImportService.php`, `app/Http/Controllers/Admin/BulkContentImportController.php`, `resources/views/admin/bulk-content-import/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BulkContentImportControllerTest.php`

**Interfaces:**

- Consumes: `BulkContentImportParser::parse()`/`conflicts()` (Task 15), `TaskLifecycleService::createTask()` (existing + Task 14's extension), `DistributionOrchestrator::syncTaskChannels()`/`lockTaskChannelSelection()` (existing), `Title`/`TitleLibrary` (Task 12 Step 1's columns).
- Produces: `POST /geo_admin/bulk-content-import` — one `Task` per `(site, preset, category)` group found in the uploaded spreadsheet, each running through Component 4's existing automation from this point on. No new distribution or adoption logic here.

Read `app/Http/Controllers/Admin/TaskController.php`'s `store()` (lines 156-188) in full — this task's Task-creation code must produce output structurally equivalent to it (same `taskLifecycleService`/`distributionOrchestrator` calls, same transaction shape), just driven by parsed spreadsheet groups instead of one HTML form submission.

- [ ] **Step 1: Write the service**

```php
<?php

namespace App\Domain\ContentGeneration;

use App\Models\Admin;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\AdminActivityLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BulkContentImportService
{
    public function __construct(
        private readonly BulkContentImportParser $parser,
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
    ) {}

    /**
     * @return array{tasks_created: int, rows_imported: int}
     */
    public function import(string $filePath, int $aiModelId, bool $needReview, Admin $actor): array
    {
        $parsed = $this->parser->parse($filePath);
        if ($parsed['errors'] !== []) {
            throw new BulkContentImportValidationException($parsed['errors']);
        }

        $conflictErrors = $this->parser->conflicts($parsed['rows']);
        if ($conflictErrors !== []) {
            throw new BulkContentImportValidationException($conflictErrors);
        }

        $groups = [];
        foreach ($parsed['rows'] as $row) {
            $groups[$row->groupKey()][] = $row;
        }

        $tasksCreated = 0;
        foreach ($groups as $rowsInGroup) {
            $this->createTaskForGroup($rowsInGroup, $aiModelId, $needReview, $actor);
            $tasksCreated++;
        }

        return ['tasks_created' => $tasksCreated, 'rows_imported' => count($parsed['rows'])];
    }

    /** @param list<BulkContentImportRow> $rows */
    private function createTaskForGroup(array $rows, int $aiModelId, bool $needReview, Admin $actor): void
    {
        $first = $rows[0];

        DB::transaction(function () use ($rows, $first, $aiModelId, $needReview, $actor): void {
            $library = TitleLibrary::query()->create([
                'name' => 'Bulk import '.now()->format('Y-m-d H:i').' — '.Str::limit($first->topic, 40),
                'generation_type' => 'manual',
            ]);

            foreach ($rows as $row) {
                Title::query()->create([
                    'library_id' => $library->id,
                    'title' => $row->topic,
                    'keyword' => (string) ($row->primaryKeyword ?? ''),
                    'core_question' => $row->coreQuestion,
                    'reference_urls' => $row->referenceUrls,
                ]);
            }

            $rowCount = count($rows);
            $taskData = [
                'name' => 'Bulk import '.now()->format('Y-m-d H:i').' — '.Str::limit($first->topic, 40),
                'title_library_id' => $library->id,
                'ai_model_id' => $aiModelId,
                'need_review' => $needReview ? 1 : 0,
                'draft_limit' => $rowCount,
                'article_limit' => $rowCount,
                'is_loop' => 0,
                'status' => 'active',
                'category_mode' => 'fixed',
                'fixed_category_id' => $first->categoryId,
                'generation_engine' => 'quality_gated',
                'prompt_preset_id' => $first->promptPresetId,
                'knowledge_base_ids' => [$first->knowledgeBaseId],
            ];

            $channelIds = [$first->distributionChannelId];
            $this->distributionOrchestrator->lockTaskChannelSelection(null, $channelIds);
            $created = $this->taskLifecycleService->createTask($taskData);
            $task = Task::query()->whereKey((int) ($created['id'] ?? 0))->firstOrFail();
            $this->distributionOrchestrator->syncTaskChannels($task, $channelIds);

            AdminActivityLogger::log($actor, 'bulk_content_import:task_created', [
                'target_type' => 'task',
                'target_id' => $task->id,
                'details' => ['row_count' => count($rows), 'distribution_channel_id' => $first->distributionChannelId, 'prompt_preset_id' => $first->promptPresetId, 'category_id' => $first->categoryId],
            ]);
        });
    }
}
```

`TaskLifecycleService::createTask()` itself has no concept of "who created this Task" (unlike `PromptPresetService`/`PromptModuleService`, it takes no `Admin $actor` argument at all — confirmed during planning), so `$actor` would otherwise go completely unused by this method. The `AdminActivityLogger::log()` call above is what gives it a real purpose, following the exact call shape `PromptModuleService::updateIdentity()` already uses elsewhere in this codebase (`AdminActivityLogger::log($actor, '<event>', ['target_type' => ..., 'target_id' => ..., 'details' => [...]])`) — check that file's real usage before finalizing this call, since the shape shown here is reconstructed from memory of that one call site, not independently re-verified against `AdminActivityLogger`'s own class definition.

**Before finalizing, re-check `TaskLifecycleService::createTask()`'s hardcoded `Task::query()->create([...])` array** (confirmed during planning to explicitly list `name, title_library_id, image_library_id, image_count, prompt_id, ai_model_id, need_review, publish_interval, author_id, auto_keywords, auto_description, draft_limit, article_limit, is_loop, model_selection_mode, status, publish_scope, distribution_strategy, distribution_cursor, knowledge_base_id, category_mode, fixed_category_id`, plus Task 14 Step 2's `generation_engine`/`prompt_preset_id` addition) against `normalizeTaskInput()`'s actual required/optional field handling — this service may reject `$taskData` above if fields like `image_library_id`/`author_id`/`publish_scope` are unset and the normalizer doesn't default them itself the way it does for a normal HTML form submission (where every field is present, even if empty, because the form always submits them). Add any missing keys with sensible defaults (e.g. `'publish_scope' => 'local_and_distribution'`, `'model_selection_mode' => 'fixed'`) if a test run reveals `normalizeTaskInput()` rejects their absence.

- [ ] **Step 2: Write the validation exception**

```php
<?php

namespace App\Domain\ContentGeneration;

use RuntimeException;

final class BulkContentImportValidationException extends RuntimeException
{
    /** @param list<array{row: int, message: string}> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Bulk content import validation failed.');
    }

    /** @return list<array{row: int, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
```

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ContentGeneration\BulkContentImportService;
use App\Domain\ContentGeneration\BulkContentImportValidationException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BulkContentImportController extends Controller
{
    public function __construct(private readonly BulkContentImportService $importer) {}

    public function index(Request $request): View
    {
        return view('admin.bulk-content-import.index', [
            'pageTitle' => '批量导入文章任务',
            'activeMenu' => 'tasks',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'spreadsheet' => ['required', 'file', 'mimes:xlsx'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'need_review' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user('admin');
        abort_unless($actor instanceof Admin, 403);

        $path = $request->file('spreadsheet')->getRealPath();

        try {
            $result = $this->importer->import(
                $path,
                (int) $request->integer('ai_model_id'),
                $request->boolean('need_review'),
                $actor,
            );
        } catch (BulkContentImportValidationException $exception) {
            return back()->withErrors([
                'spreadsheet' => collect($exception->errors())
                    ->map(fn (array $error): string => "第 {$error['row']} 行：{$error['message']}")
                    ->implode(' '),
            ]);
        }

        return redirect()->route('admin.tasks.index')
            ->with('message', "已创建 {$result['tasks_created']} 个任务，共导入 {$result['rows_imported']} 行。");
    }
}
```

- [ ] **Step 4: Register routes**

In `routes/web.php`, inside the same `['admin.auth', 'admin.activity']`-only group `tasks` sits in (not the `geoflow.feature:*`-gated group — bulk import creates ordinary Tasks, which are already ungated), add:

```php
Route::prefix('bulk-content-import')->name('bulk-content-import.')->group(function () {
    Route::get('/', [BulkContentImportController::class, 'index'])->name('index');
    Route::post('/', [BulkContentImportController::class, 'store'])->name('store');
});
```

Add `use App\Http\Controllers\Admin\BulkContentImportController;` at the top of the file.

- [ ] **Step 5: Write a minimal upload view**

```blade
@extends('admin.layouts.app')

@section('content')
<div class="mx-auto max-w-2xl rounded-md border border-gray-200 bg-white p-6">
    <h1 class="text-lg font-semibold text-gray-900">批量导入文章任务</h1>
    <p class="mt-1 text-sm text-gray-500">上传填好的模板表格，系统会按站点/内容模板/分类分组，自动创建对应的任务。</p>

    <a href="{{ route('admin.bulk-content-import.template') }}" class="mt-4 inline-block rounded-md border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-700">下载导入模板</a>

    @if ($errors->any())
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.bulk-content-import.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700">表格文件（.xlsx）</label>
            <input type="file" name="spreadsheet" accept=".xlsx" required class="mt-1 w-full">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">AI 模型</label>
            <select name="ai_model_id" required class="mt-1 w-full rounded-md border-gray-300">
                @foreach ($aiModels as $aiModel)
                    <option value="{{ $aiModel->id }}">{{ $aiModel->name }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="need_review" value="1" checked>
            生成后需人工审核
        </label>
        <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">开始导入</button>
    </form>
</div>
@endsection
```

Check the actual admin layout name/section conventions (`@extends`/`@section`) against an existing simple admin view like `resources/views/admin/prompt-presets/index.blade.php` and match its exact `@extends`/section structure rather than assuming `admin.layouts.app`/`content` — these names must match this codebase's real layout file.

Pass `$aiModels` from `BulkContentImportController::index()`:

```php
'aiModels' => \App\Models\AiModel::query()->where('status', 'active')->where(function ($q) {
    $q->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
})->get(['id', 'name']),
```

(This mirrors the exact `aiModels` query `TaskController::loadTaskFormOptions()` already builds — read that method's real query and copy it verbatim rather than reconstructing it from this description, to guarantee the same "usable chat model" definition is used everywhere in the admin panel.)

- [ ] **Step 6: Write a feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class BulkContentImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_a_valid_spreadsheet_creates_one_task_per_group(): void
    {
        $admin = Admin::query()->create(['username' => 'bulk-import-tester', 'password' => bcrypt(bin2hex(random_bytes(32))), 'role' => 'super_admin', 'status' => 'active']);
        $channel = DistributionChannel::query()->create(['name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com', 'channel_type' => 'wordpress', 'status' => 'active']);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'Category Authority Guide', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $category = Category::query()->create(['name' => 'Style & Buying Guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'LITB KB', 'content' => 'x', 'review_status' => 'reviewed']);
        $aiModel = AiModel::query()->create(['name' => 'Test model', 'status' => 'active', 'model_type' => 'chat', 'api_url' => 'https://example.invalid', 'api_key' => 'x', 'model_id' => 'x']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [
            ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'],
            ['instructions'],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic One', 'LITB KB', '', '', ''],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic Two', 'LITB KB', '', '', ''],
        ];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.bulk-content-import.store'), [
            'spreadsheet' => $file,
            'ai_model_id' => $aiModel->id,
            'need_review' => '1',
        ]);

        $response->assertRedirect(route('admin.tasks.index'));
        $this->assertSame(1, Task::query()->count());
        $task = Task::query()->firstOrFail();
        $this->assertSame('quality_gated', $task->generation_engine);
        $this->assertSame(2, $task->article_limit);
        $this->assertSame(2, $task->titleLibrary->titles()->count());
    }
}
```

- [ ] **Step 7: Run the test**

Run: `docker compose exec -T app php artisan test --filter=BulkContentImportControllerTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Domain/ContentGeneration/BulkContentImportService.php app/Domain/ContentGeneration/BulkContentImportValidationException.php app/Http/Controllers/Admin/BulkContentImportController.php resources/views/admin/bulk-content-import/index.blade.php routes/web.php tests/Feature/BulkContentImportControllerTest.php
git commit -m "feat: create one Task per site/preset/category group from an uploaded spreadsheet"
```

---

## Task 17: Downloadable Excel import template

**Files:**

- Create: `app/Domain/ContentGeneration/BulkContentImportTemplateBuilder.php`
- Modify: `app/Http/Controllers/Admin/BulkContentImportController.php`, `routes/web.php`, `resources/views/admin/bulk-content-import/index.blade.php`
- Test: `tests/Feature/BulkContentImportTemplateBuilderTest.php`

**Interfaces:**

- Produces: `BulkContentImportTemplateBuilder::build(): Spreadsheet` — a fresh `.xlsx` reflecting current live `DistributionChannel`/`PromptPreset`/`Category` values every time it's downloaded, with dropdown data-validation on the `site`/`preset`/`category` columns sourced from a second "可选值参考" sheet, whose cell format (`"{label} ({key})"`) matches exactly what Task 15's parser expects to parse.

- [ ] **Step 1: Write the builder**

```php
<?php

namespace App\Domain\ContentGeneration;

use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class BulkContentImportTemplateBuilder
{
    private const HEADERS = ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'];

    private const INSTRUCTIONS = [
        'site' => '【必填】发布到哪个站点，从"可选值参考"表中选，不要手打。',
        'preset' => '【必填】使用哪个内容模板，从"可选值参考"表中选。同一分类下可能有多个模板，请核对模板名称。',
        'category' => '【必填】文章分类，从"可选值参考"表中选。',
        'topic' => '【必填】选题方向，写清楚主题即可，不用是完整标题（最终标题由AI生成）。',
        'knowledge_base' => '【必填】写作时参考的知识库名称，从"可选值参考"表中选。同一站点/模板/分类分组内必须一致。',
        'core_question' => '【选填】文章需要重点回答的具体问题，不填则由选题方向自动推导。',
        'primary_keyword' => '【选填】核心关键词。',
        'reference_urls' => '【选填】参考资料链接，一个单元格内每行一个链接。',
    ];

    private const EXAMPLE_ROWS = [
        ['示例——请删除后再填真实数据：LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'How to Choose Running Shoes', 'LITB Product KB', 'What should a first-time buyer check?', 'running shoes', ''],
        ['示例——请删除后再填真实数据：LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Choosing the Right Duvet Cover', 'LITB Product KB', '', 'duvet cover', "https://example.com/care-guide"],
    ];

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('导入数据');

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
            $sheet->setCellValueByColumnAndRow($index + 1, 2, self::INSTRUCTIONS[$header]);
        }
        foreach (self::EXAMPLE_ROWS as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, 3 + $rowIndex, $value);
            }
        }

        $referenceSheet = $spreadsheet->createSheet();
        $referenceSheet->setTitle('可选值参考');
        $siteValues = $this->siteValues();
        $presetValues = $this->presetValues();
        $categoryValues = $this->categoryValues();

        $referenceSheet->setCellValue('A1', '站点 site');
        $referenceSheet->setCellValue('B1', '内容模板 preset');
        $referenceSheet->setCellValue('C1', '分类 category');
        foreach ($siteValues as $index => $value) {
            $referenceSheet->setCellValue('A'.($index + 2), $value);
        }
        foreach ($presetValues as $index => $value) {
            $referenceSheet->setCellValue('B'.($index + 2), $value);
        }
        foreach ($categoryValues as $index => $value) {
            $referenceSheet->setCellValue('C'.($index + 2), $value);
        }

        $this->addDropdownValidation($sheet, 'A', count($siteValues), $referenceSheet, 'A', count($siteValues));
        $this->addDropdownValidation($sheet, 'B', count($presetValues), $referenceSheet, 'B', count($presetValues));
        $this->addDropdownValidation($sheet, 'C', count($categoryValues), $referenceSheet, 'C', count($categoryValues));

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @return list<string> */
    private function siteValues(): array
    {
        return DistributionChannel::query()
            ->where('status', DistributionChannel::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['name', 'domain'])
            ->map(fn (DistributionChannel $channel): string => "{$channel->name} ({$channel->domain})")
            ->all();
    }

    /** @return list<string> */
    private function presetValues(): array
    {
        return PromptPreset::query()
            ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
            ->orderBy('display_name')
            ->get(['display_name', 'preset_key'])
            ->map(fn (PromptPreset $preset): string => "{$preset->display_name} ({$preset->preset_key})")
            ->all();
    }

    /** @return list<string> */
    private function categoryValues(): array
    {
        return Category::query()->orderBy('name')->pluck('name')->all();
    }

    private function addDropdownValidation(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $column,
        int $optionCount,
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $referenceSheet,
        string $referenceColumn,
        int $referenceCount,
    ): void {
        if ($optionCount === 0) {
            return;
        }

        $referenceRange = "'".$referenceSheet->getTitle()."'!\${$referenceColumn}\$2:\${$referenceColumn}\$".($referenceCount + 1);
        for ($row = 3; $row <= 200; $row++) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($referenceRange);
        }
    }
}
```

**Row 200 as the dropdown-validation range limit is an arbitrary practical cap, not derived from any requirement** — pick a number generous enough for a real import batch (a few hundred articles at most per upload, per the spec's "one-off batch" framing) without generating an excessively large validation range; adjust if a real usage pattern later needs more.

- [ ] **Step 2: Wire the download route**

In `app/Http/Controllers/Admin/BulkContentImportController.php`, add a constructor dependency and action:

```php
    public function __construct(
        private readonly BulkContentImportService $importer,
        private readonly BulkContentImportTemplateBuilder $templateBuilder,
    ) {}

    public function template(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = $this->templateBuilder->build();
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, 'geoflow-bulk-content-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
```

In `routes/web.php`'s `bulk-content-import` group (added in Task 16), add:

```php
Route::get('template', [BulkContentImportController::class, 'template'])->name('template');
```

(Add this route line — the Blade view written in Task 16 Step 5 already links to `route('admin.bulk-content-import.template')`, so this route must exist for that link to resolve; if Task 16 was implemented before this task, this is the missing piece that link depended on.)

- [ ] **Step 3: Write a test**

```php
<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\BulkContentImportTemplateBuilder;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Tests\TestCase;

final class BulkContentImportTemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_sheet_values_match_the_format_the_parser_expects(): void
    {
        DistributionChannel::query()->create(['name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com', 'channel_type' => 'wordpress', 'status' => 'active']);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'Category Authority Guide', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        Category::query()->create(['name' => 'Style & Buying Guides']);

        $spreadsheet = $this->app->make(BulkContentImportTemplateBuilder::class)->build();

        $mainSheet = $spreadsheet->getSheet(0);
        $this->assertSame('site', $mainSheet->getCell('A1')->getValue());
        $this->assertStringContainsString('必填', (string) $mainSheet->getCell('A2')->getValue());

        $referenceSheet = $spreadsheet->getSheetByName('可选值参考');
        $this->assertSame('LITB Style Blog (blog.lightinthebox.com)', $referenceSheet->getCell('A2')->getValue());
        $this->assertSame('Category Authority Guide (litb.geo_category_authority.en)', $referenceSheet->getCell('B2')->getValue());
        $this->assertSame('Style & Buying Guides', $referenceSheet->getCell('C2')->getValue());

        $validation = $mainSheet->getCell('A3')->getDataValidation();
        $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
        $this->assertStringContainsString('可选值参考', $validation->getFormula1());
    }
}
```

- [ ] **Step 4: Run the test**

Run: `docker compose exec -T app php artisan test --filter=BulkContentImportTemplateBuilderTest`
Expected: PASS

- [ ] **Step 5: Manually verify the downloaded file opens correctly**

Run: `docker compose exec -T app php artisan tinker --execute="file_put_contents('/tmp/template-check.xlsx', (function() { \$b = app(\App\Domain\ContentGeneration\BulkContentImportTemplateBuilder::class)->build(); \$w = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(\$b); ob_start(); \$w->save('php://output'); return ob_get_clean(); })());"`
Then `docker compose cp app:/tmp/template-check.xlsx ./template-check.xlsx` (or equivalent for however this project copies files out of the app container) and open it in a real spreadsheet application to confirm the dropdowns actually render and the instruction row reads clearly — an automated test can check cell values and validation formulas exist, but cannot confirm the file is genuinely well-formed `.xlsx` a human can open without corruption warnings.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/ContentGeneration/BulkContentImportTemplateBuilder.php app/Http/Controllers/Admin/BulkContentImportController.php routes/web.php tests/Feature/BulkContentImportTemplateBuilderTest.php
git commit -m "feat: add downloadable bulk-import Excel template with live dropdown validation"
```

---

## Final Regression Pass

After Task 17, run the full suite once, not just the per-task filtered runs used throughout:

Run: `docker compose exec -T app php artisan test`
Expected: PASS with zero regressions. Pay particular attention to:

- Every `DeterministicQualityGate`-consuming test elsewhere in the suite (grep `tests/` for `DeterministicQualityGate` beyond the two files already touched in Task 1 — e.g. any Litb-archive or content-generation-coordinator fixture that feeds a full article through `inspect()` without a leading H1 will now trip the new `missing_title_heading` check).
- `tests/Feature/ArchiveLitbContentGenerationTest.php` — confirmed during planning to feed `raw_output` without a leading H1 (e.g. `"## Fit\nChoose shoes..."`); this test doesn't call `DeterministicQualityGate` directly (it archives an already-`completed`/`failed` run), so it should be unaffected, but verify this assumption by actually running it rather than trusting the earlier research summary.
- The full `TaskController`/`TaskLifecycleService` suites (Task 14 touches a service every existing Task creation/update path depends on — a mistake there breaks `legacy` Tasks too, not just new `quality_gated` ones).
- `LitbQuarterlyBriefImporter`-related tests (`AuditLitbQuarterlyContentCommand`, `LitbQuarterlyGenerationCoordinator`, etc., if such tests exist — grep for them) — Task 6's backfill touches every LITB preset's `current_version_id`, and any test that hardcodes an expectation like "the LITB preset is at version 1" will need updating to "version 2" (or whatever version number Task 6's backfill actually produces once Task 2/3/4/5/6 have all run in the real execution order).

## Global Constraints (repeated for the executing agent's convenience)

- `generation_engine=legacy` Tasks and manually-created Briefs (today's flows) must keep working exactly as today — additive/opt-in only.
- No change to `SemanticQualityReviewer`, `ArticleWorkflowTransitionService`, `DistributionOrchestrator`'s existing sync logic (Task 16 only *calls* `syncTaskChannels()`, never modifies it), or `docker-compose.yml`.
- The 4 `legacy_geo_*` presets' stale mandatory-module bindings stay broken — Task 6 explicitly excludes them from its backfill; do not "fix" this as a drive-by.
- Auto-adoption and other autonomous actions (Tasks 5, 6, 12, 13) always use the `geoflow-system` Admin (Task 10) — never a real human admin's identity. The one exception is Task 16's bulk import, which is a human-initiated upload — it logs the real uploading admin via `AdminActivityLogger`, not the system admin.
- Any new outbound HTTP call (Task 11's `ReferenceEvidenceFetcher`) goes through `SafeOutboundHttpClient`/`SafeOutboundRequest` — never a raw `Http::get()`.
- `show_references_section` (part of `blueprint_template`, Task 3/6) is an explicit per-Preset editorial toggle — no code infers it from content type.
- Every migration in this plan follows the `Schema::hasColumn()`/`Schema::hasTable()` guard convention already used throughout `database/migrations/`.
