# LITB Blog Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the mismatched news-aggregator homepage theme with a LightInTheBox-branded lifestyle/editorial theme, organize the 69 published articles into a real category taxonomy, populate the homepage with curated content modules, fix structured data to current SEO/GEO standards, and add `robots.txt`/`sitemap.xml` crawler-discovery infrastructure.

**Architecture:** Copy the existing shared "magazine" Blade structure (used identically by `geoflow-template-01` through `-20`) into a new theme directory, reskin via CSS custom-property overrides (not a full rewrite), fix the JSON-LD schema blocks already present in that structure, and drive category/homepage content entirely through existing data-driven systems (`Category` model, `site_settings.homepage_modules` via `HomepageModuleBuilder`) — no new content-authoring UI needed.

**Tech Stack:** PHP 8.2+, Laravel, Blade, PostgreSQL, PHPUnit, Docker Compose

## Global Constraints

- Site has no payment/checkout functionality; promotional content is editorial CTA cards linking out to the live store, never fabricated prices/stock.
- Every page has exactly one `<h1>`; heading levels must not skip.
- No table-based layout, no deprecated HTML tags, no JS-only navigation, external links get `rel="noopener"`, below-fold images get `loading="lazy"`.
- Structured data must never contain fabricated facts (no invented `sameAs` URLs, no invented FAQ answers — only real content already in the article).
- `llms.txt` and Image/Video/News sitemaps are explicitly out of scope for this plan.
- Do not modify or delete any of the existing 21 themes; the new theme is an additional option switched on via `site_settings.active_theme`.
- Existing `docker-compose.yml` modifications remain untouched.

---

## File Structure

- `app/Console/Commands/SetupLitbBlogCategoriesCommand.php`: creates the 7 reader-facing categories and recategorizes the 69 published LITB articles by their content pillar.
- `app/Support/Site/ArticleFaqSchema.php`: extracts real Question/Answer pairs from an article's "Frequently asked questions" markdown section for `FAQPage` JSON-LD.
- `resources/views/theme/litb-lifestyle-editorial/`: new theme (copied from `geoflow-template-15-reading-medium`, reskinned, schema-fixed).
- `app/Console/Commands/SeedLitbHomepageModulesCommand.php`: writes real, article-backed `homepage_modules`/`homepage_style` site settings.
- `app/Console/Commands/PublishLitbRobotsTxtCommand.php`: (re)writes `public/robots.txt` with AI-crawler rules and an absolute `Sitemap:` reference.
- `app/Http/Controllers/Site/SitemapController.php`: serves `/sitemap.xml`.
- `routes/web.php`: add the `GET /sitemap.xml` route.

### Task 1: Category Taxonomy and Article Recategorization

**Files:**
- Create: `app/Console/Commands/SetupLitbBlogCategoriesCommand.php`
- Test: `tests/Feature/SetupLitbBlogCategoriesCommandTest.php`

**Interfaces:**
- Produces: `geoflow:litb-site:setup-categories` artisan command, idempotent.
- Consumes: `App\Models\Category`, `App\Models\Article`, `App\Models\ContentGenerationRun` (`article_id`), `App\Models\ContentBrief` (`asset_provenance['pillar']`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupLitbBlogCategoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_categories_and_recategorizes_articles_by_pillar(): void
    {
        $comparisonArticle = $this->publishedArticleForPillar('product_comparison', 'heels-vs-flats');
        $faqArticle = $this->publishedArticleForPillar('faq', 'is-litb-official');

        $this->artisan('geoflow:litb-site:setup-categories')->assertSuccessful();

        $this->assertDatabaseHas('categories', ['slug' => 'comparisons', 'name' => 'Comparisons']);
        $this->assertDatabaseHas('categories', ['slug' => 'faq-help-center', 'name' => 'FAQ & Help Center']);
        $this->assertDatabaseHas('categories', ['slug' => 'new-arrivals', 'name' => 'New Arrivals']);

        $comparisonsCategoryId = Category::where('slug', 'comparisons')->value('id');
        $faqCategoryId = Category::where('slug', 'faq-help-center')->value('id');
        $this->assertSame($comparisonsCategoryId, $comparisonArticle->fresh()->category_id);
        $this->assertSame($faqCategoryId, $faqArticle->fresh()->category_id);
    }

    public function test_rerun_does_not_duplicate_categories_or_change_already_correct_assignments(): void
    {
        $article = $this->publishedArticleForPillar('brand_company', 'what-is-litb');

        $this->artisan('geoflow:litb-site:setup-categories')->assertSuccessful();
        $firstCategoryId = $article->fresh()->category_id;
        $this->artisan('geoflow:litb-site:setup-categories')->assertSuccessful();

        $this->assertSame(1, Category::where('slug', 'about-lightinthebox')->count());
        $this->assertSame($firstCategoryId, $article->fresh()->category_id);
    }

    private function publishedArticleForPillar(string $pillar, string $slugWord): Article
    {
        $admin = Admin::query()->firstOrCreate(
            ['username' => 'category-setup-admin'],
            ['password' => 'secret-123', 'email' => 'category-setup-admin@example.com', 'display_name' => 'Category Setup Admin', 'role' => 'admin', 'status' => 'active'],
        );
        $author = Author::query()->firstOrCreate(['email' => 'editorial@lightinthebox.com'], ['name' => 'LightInTheBox Editorial Team']);
        $fallbackCategory = Category::query()->firstOrCreate(['slug' => 'litb-guides'], ['name' => 'LightInTheBox Guides']);

        $brief = ContentBrief::query()->create([
            'asset_key' => 'litb-quarterly-2026-q3.'.$slugWord,
            'asset_provenance' => ['pillar' => $pillar, 'slug' => $slugWord],
            'topic' => $slugWord,
            'market_code' => 'GLOBAL',
            'language_code' => 'en',
            'audience' => 'Global shoppers',
            'core_question' => 'Test question?',
            'business_goal' => 'Test goal',
            'content_type' => 'guide',
            'status' => ContentBrief::STATUS_STRATEGY_READY,
        ]);
        $strategy = ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $article = Article::query()->create([
            'title' => ucfirst(str_replace('-', ' ', $slugWord)),
            'slug' => $slugWord,
            'content' => "## Direct answer and decision summary\nContent body.",
            'excerpt' => 'Excerpt.',
            'category_id' => $fallbackCategory->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $preset = \App\Models\PromptPreset::query()->create(['preset_key' => 'test-'.\Illuminate\Support\Str::uuid(), 'display_name' => 'Test preset']);
        $presetVersion = \App\Models\PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => \App\Models\PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = \App\Models\AiModel::query()->create([
            'name' => 'Test model', 'version' => '1', 'api_key' => 'k', 'api_url' => 'https://example.test',
            'model_id' => 'test-model', 'max_tokens' => 4096,
        ]);
        ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => 'p',
            'raw_output' => 'content',
            'status' => ContentGenerationRun::STATUS_ADOPTED,
            'article_id' => $article->id,
            'idempotency_key' => 'test-'.\Illuminate\Support\Str::uuid(),
        ]);

        return $article;
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/SetupLitbBlogCategoriesCommandTest.php`
Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Models\ContentGenerationRun;
use Illuminate\Console\Command;

class SetupLitbBlogCategoriesCommand extends Command
{
    protected $signature = 'geoflow:litb-site:setup-categories';

    protected $description = 'Create the LITB blog category taxonomy and recategorize published LITB articles by content pillar.';

    /** @var array<string,array{name:string,sort_order:int,pillars:list<string>}> */
    private const CATEGORIES = [
        'style-buying-guides' => ['name' => 'Style & Buying Guides', 'sort_order' => 1, 'pillars' => ['category_authority', 'product_education']],
        'comparisons' => ['name' => 'Comparisons', 'sort_order' => 2, 'pillars' => ['product_comparison']],
        'inspiration-occasions' => ['name' => 'Inspiration & Occasions', 'sort_order' => 3, 'pillars' => ['style_occasion']],
        'new-arrivals' => ['name' => 'New Arrivals', 'sort_order' => 4, 'pillars' => ['new_arrivals']],
        'shipping-payment-returns' => ['name' => 'Shipping, Payment & Returns', 'sort_order' => 5, 'pillars' => ['customer_education']],
        'about-lightinthebox' => ['name' => 'About LightInTheBox', 'sort_order' => 6, 'pillars' => ['brand_company', 'official_news']],
        'faq-help-center' => ['name' => 'FAQ & Help Center', 'sort_order' => 7, 'pillars' => ['faq']],
    ];

    public function handle(): int
    {
        $categoryIdsBySlug = [];
        foreach (self::CATEGORIES as $slug => $definition) {
            $category = Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $definition['name'], 'sort_order' => $definition['sort_order']],
            );
            $categoryIdsBySlug[$slug] = $category->id;
        }

        $pillarToSlug = [];
        foreach (self::CATEGORIES as $slug => $definition) {
            foreach ($definition['pillars'] as $pillar) {
                $pillarToSlug[$pillar] = $slug;
            }
        }

        $recategorized = 0;
        ContentGenerationRun::query()
            ->whereNotNull('article_id')
            ->with(['article', 'contentBrief'])
            ->chunkById(50, function ($runs) use (&$recategorized, $pillarToSlug, $categoryIdsBySlug): void {
                foreach ($runs as $run) {
                    $article = $run->article;
                    $pillar = (string) ($run->contentBrief?->asset_provenance['pillar'] ?? '');
                    $slug = $pillarToSlug[$pillar] ?? null;
                    if (! $article instanceof Article || $slug === null) {
                        continue;
                    }
                    $targetCategoryId = $categoryIdsBySlug[$slug];
                    if ($article->category_id !== $targetCategoryId) {
                        $article->forceFill(['category_id' => $targetCategoryId])->save();
                        $recategorized++;
                    }
                }
            });

        $this->info('Categories ensured: '.count(self::CATEGORIES));
        $this->info('Articles recategorized: '.$recategorized);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `docker compose exec -T app php artisan test tests/Feature/SetupLitbBlogCategoriesCommandTest.php`
Expected: PASS

- [ ] **Step 5: Run the real command against the live 69 articles**

Run: `docker compose exec -T app php artisan geoflow:litb-site:setup-categories`
Expected: "Categories ensured: 7", "Articles recategorized: 69" (or fewer if some briefs' pillar isn't in the mapping — investigate any not recategorized before continuing).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SetupLitbBlogCategoriesCommand.php tests/Feature/SetupLitbBlogCategoriesCommandTest.php
git commit -m "feat: add LITB blog category taxonomy and recategorize articles"
```

### Task 2: Scaffold the LITB Lifestyle Editorial Theme

**Files:**
- Create: `resources/views/theme/litb-lifestyle-editorial/` (copied from `geoflow-template-15-reading-medium`)
- Test: `tests/Feature/LitbLifestyleEditorialThemeTest.php`

**Interfaces:**
- Consumes: `App\Support\Site\SiteThemeViewResolver::first()` (unchanged — resolves `theme.litb-lifestyle-editorial.*` once `site_settings.active_theme` is set to `litb-lifestyle-editorial`).
- Produces: a theme directory satisfying the same Blade variable contract as `geoflow-template-15-reading-medium` (verified in Task 2's test), ready for Task 3's visual reskin and Task 4's schema fixes.

- [ ] **Step 1: Copy the theme directory and its published CSS**

```bash
cp -r resources/views/theme/geoflow-template-15-reading-medium resources/views/theme/litb-lifestyle-editorial
cp -r public/themes/geoflow-template-15-reading-medium public/themes/litb-lifestyle-editorial
```

- [ ] **Step 2: Rewrite every internal theme-id reference**

```bash
grep -rl 'geoflow-template-15-reading-medium' resources/views/theme/litb-lifestyle-editorial | xargs sed -i '' 's/geoflow-template-15-reading-medium/litb-lifestyle-editorial/g'
```

(On Linux, drop the empty `''` argument to `sed -i`.)

- [ ] **Step 3: Correct the identity fields inside the copied JSON files**

Edit `resources/views/theme/litb-lifestyle-editorial/manifest.json`: set `"id"` to `"litb-lifestyle-editorial"`, `"name"` to `"LITB Lifestyle Editorial"`, `"variant_number"` to `null`, `"reference_site"` to `"LightInTheBox brand editorial"`, `"base_theme_id"` to `"geoflow-template-15-reading-medium"`, `"target_theme_id"` to `"litb-lifestyle-editorial"`.

Edit `resources/views/theme/litb-lifestyle-editorial/mapping.json`: set `"id"` to `"litb-lifestyle-editorial"`.

Edit `resources/views/theme/litb-lifestyle-editorial/tokens.json`: set `"id"` to `"litb-lifestyle-editorial"`, `"variant_number"` to `null`, `"reference_site"` to `"LightInTheBox brand editorial"` (colors/typography are Task 3's concern).

- [ ] **Step 4: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LitbLifestyleEditorialThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_category_and_article_pages_render_under_the_new_theme(): void
    {
        SiteSetting::query()->create(['setting_key' => 'active_theme', 'setting_value' => 'litb-lifestyle-editorial']);
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-buying-guides']);
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $article = Article::query()->create([
            'title' => 'Wedding Dresses Guide',
            'slug' => 'wedding-dresses-guide',
            'content' => "## Direct answer and decision summary\nGuide content.",
            'excerpt' => 'A guide.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $this->get(route('site.home'))->assertOk()->assertViewIs('theme.litb-lifestyle-editorial.home');
        $this->get(route('site.category', $category->slug))->assertOk()->assertViewIs('theme.litb-lifestyle-editorial.category');
        $this->get(route('site.article', $article->slug))->assertOk()->assertViewIs('theme.litb-lifestyle-editorial.article');
    }
}
```

- [ ] **Step 5: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/LitbLifestyleEditorialThemeTest.php`
Expected: FAIL if any copy/rename step was missed (view not found, or still resolving to the old theme name).

- [ ] **Step 6: Fix any remaining reference and run again until it passes**

Run: `docker compose exec -T app php artisan test tests/Feature/LitbLifestyleEditorialThemeTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add resources/views/theme/litb-lifestyle-editorial public/themes/litb-lifestyle-editorial tests/Feature/LitbLifestyleEditorialThemeTest.php
git commit -m "feat: scaffold litb-lifestyle-editorial theme from the reading-medium base"
```

### Task 3: Apply LightInTheBox Brand Visual Identity

**Files:**
- Modify: `resources/views/theme/litb-lifestyle-editorial/assets/theme.css`
- Modify: `public/themes/litb-lifestyle-editorial/theme.css` (kept identical to the source copy, matching the existing publish convention)
- Test: `tests/Unit/LitbLifestyleEditorialThemeCssTest.php`

**Interfaces:**
- Consumes: the `:root` CSS custom properties already defined at the top of the copied `theme.css` (`--ne-red`, `--ne-green`, `--ne-ink`, `--ne-muted`, `--ne-subtle`, `--ne-line`, `--ne-bg`, `--ne-panel`, `--ne-soft`) and the hardcoded `font-family` on `.ne-body`.
- Produces: no new selectors; only value changes, so every existing theme rule keeps working with the new palette.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

class LitbLifestyleEditorialThemeCssTest extends TestCase
{
    public function test_theme_css_uses_the_litb_brand_palette_and_serif_display_font(): void
    {
        $css = file_get_contents(resource_path('views/theme/litb-lifestyle-editorial/assets/theme.css'));

        $this->assertStringContainsString('--ne-bg: #faf6f1;', $css);
        $this->assertStringContainsString('--ne-ink: #2b2622;', $css);
        $this->assertStringContainsString('--ne-red: #b8896f;', $css);
        $this->assertStringContainsString('"Playfair Display"', $css);
    }

    public function test_published_and_source_theme_css_stay_identical(): void
    {
        $source = file_get_contents(resource_path('views/theme/litb-lifestyle-editorial/assets/theme.css'));
        $published = file_get_contents(public_path('themes/litb-lifestyle-editorial/theme.css'));

        $this->assertSame($source, $published);
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Unit/LitbLifestyleEditorialThemeCssTest.php`
Expected: FAIL (palette not yet applied).

- [ ] **Step 3: Override the root palette and headline typography**

At the top of `resources/views/theme/litb-lifestyle-editorial/assets/theme.css`, replace the `:root { ... }` block's values (keep the same property names so every rule below keeps working):

```css
:root {
    --ne-red: #b8896f;
    --ne-red-dark: #96694f;
    --ne-green: #7c8a6e;
    --ne-coral: #c98f76;
    --ne-ink: #2b2622;
    --ne-muted: #6b5f56;
    --ne-subtle: #94897e;
    --ne-line: #e8ddd2;
    --ne-soft-line: #f2e9df;
    --ne-bg: #faf6f1;
    --ne-panel: #ffffff;
    --ne-soft: #f5efe6;
}
```

Then update the body/headline font declarations (find and replace each occurrence in the file):

```css
.ne-body {
    font-family: "Inter", Arial, "PingFang SC", sans-serif;
}
```

```css
.ne-home-lead-main h1,
.ne-article-h1,
.ne-page-title {
    font-family: "Playfair Display", Georgia, "Noto Serif SC", serif;
}
```

- [ ] **Step 4: Copy the edited file to the published path**

```bash
cp resources/views/theme/litb-lifestyle-editorial/assets/theme.css public/themes/litb-lifestyle-editorial/theme.css
```

- [ ] **Step 5: Run the test and verify it passes**

Run: `docker compose exec -T app php artisan test tests/Unit/LitbLifestyleEditorialThemeCssTest.php`
Expected: PASS

- [ ] **Step 6: Visually verify in a browser**

With `active_theme` set to `litb-lifestyle-editorial` (Task 7 does this permanently; for a manual check now, temporarily run `docker compose exec -T app php artisan tinker --execute="App\Models\SiteSetting::updateOrCreate(['setting_key'=>'active_theme'],['setting_value'=>'litb-lifestyle-editorial']);"`), open the homepage and confirm warm ivory background, charcoal text, dusty-rose accent, serif headlines.

- [ ] **Step 7: Commit**

```bash
git add resources/views/theme/litb-lifestyle-editorial/assets/theme.css public/themes/litb-lifestyle-editorial/theme.css tests/Unit/LitbLifestyleEditorialThemeCssTest.php
git commit -m "feat: apply LightInTheBox brand palette and typography to the new theme"
```

### Task 4: Fix Structured Data to Current SEO/GEO Standards

**Files:**
- Create: `app/Support/Site/ArticleFaqSchema.php`
- Test: `tests/Unit/ArticleFaqSchemaTest.php`
- Modify: `resources/views/theme/litb-lifestyle-editorial/article.blade.php`
- Modify: `resources/views/theme/litb-lifestyle-editorial/category.blade.php`
- Modify: `resources/views/theme/litb-lifestyle-editorial/layout.blade.php`
- Test: `tests/Feature/LitbLifestyleEditorialStructuredDataTest.php`

**Interfaces:**
- Produces: `ArticleFaqSchema::extract(string $markdown): array` returning a list of `['question' => string, 'answer' => string]` pairs found under a `## Frequently asked questions` (case-insensitive) heading, reading each `###` sub-heading as a question and the paragraph text that follows as the answer. Returns `[]` if no such section exists.

- [ ] **Step 1: Write the failing unit test for the FAQ extractor**

```php
<?php

namespace Tests\Unit;

use App\Support\Site\ArticleFaqSchema;
use Tests\TestCase;

class ArticleFaqSchemaTest extends TestCase
{
    public function test_it_extracts_question_answer_pairs_from_the_faq_section(): void
    {
        $markdown = <<<'MD'
## Direct answer and decision summary
Some intro content.

## Frequently asked questions

### Is LightInTheBox an official online store?
Yes, LightInTheBox is an official online retail platform.

### How do I contact LightInTheBox?
Use the official support channel listed on the site.
MD;

        $pairs = ArticleFaqSchema::extract($markdown);

        $this->assertCount(2, $pairs);
        $this->assertSame('Is LightInTheBox an official online store?', $pairs[0]['question']);
        $this->assertSame('Yes, LightInTheBox is an official online retail platform.', $pairs[0]['answer']);
        $this->assertSame('How do I contact LightInTheBox?', $pairs[1]['question']);
    }

    public function test_it_returns_empty_when_there_is_no_faq_section(): void
    {
        $this->assertSame([], ArticleFaqSchema::extract("## Direct answer\nNo FAQ here."));
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Unit/ArticleFaqSchemaTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the extractor**

```php
<?php

namespace App\Support\Site;

final class ArticleFaqSchema
{
    /** @return list<array{question:string,answer:string}> */
    public static function extract(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $inFaqSection = false;
        $pairs = [];
        $currentQuestion = null;
        $currentAnswerLines = [];

        $flush = function () use (&$pairs, &$currentQuestion, &$currentAnswerLines): void {
            if ($currentQuestion !== null) {
                $answer = trim(implode(' ', $currentAnswerLines));
                if ($answer !== '') {
                    $pairs[] = ['question' => $currentQuestion, 'answer' => $answer];
                }
            }
            $currentQuestion = null;
            $currentAnswerLines = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', trim($line), $matches) === 1) {
                if ($inFaqSection) {
                    $flush();
                    break;
                }
                if (stripos(trim($matches[1]), 'frequently asked questions') !== false) {
                    $inFaqSection = true;
                }

                continue;
            }
            if (! $inFaqSection) {
                continue;
            }
            if (preg_match('/^###\s+(.+)$/', trim($line), $matches) === 1) {
                $flush();
                $currentQuestion = trim($matches[1]);

                continue;
            }
            if (trim($line) !== '') {
                $currentAnswerLines[] = trim($line);
            }
        }
        $flush();

        return $pairs;
    }
}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `docker compose exec -T app php artisan test tests/Unit/ArticleFaqSchemaTest.php`
Expected: PASS

- [ ] **Step 5: Write the failing feature test for the fixed page-level schema**

```php
<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LitbLifestyleEditorialStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::query()->create(['setting_key' => 'active_theme', 'setting_value' => 'litb-lifestyle-editorial']);
    }

    public function test_guide_article_uses_blogposting_and_has_breadcrumb_and_publisher_schema(): void
    {
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-buying-guides']);
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $article = Article::query()->create([
            'title' => 'Wedding Dresses Guide',
            'slug' => 'wedding-dresses-guide',
            'content' => "## Direct answer and decision summary\nGuide content here.",
            'excerpt' => 'A guide.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $response = $this->get(route('site.article', $article->slug));

        $response->assertOk();
        $response->assertSee('"@type":"BlogPosting"', false);
        $response->assertDontSee('"@type":"NewsArticle"', false);
        $response->assertSee('"@type":"BreadcrumbList"', false);
        $response->assertSee('"@type":"Organization"', false);
    }

    public function test_official_news_article_uses_newsarticle_schema(): void
    {
        $category = Category::query()->create(['name' => 'About LightInTheBox', 'slug' => 'about-lightinthebox']);
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $article = Article::query()->create([
            'title' => 'LightInTheBox Quarterly Update',
            'slug' => 'litb-quarterly-update',
            'content' => "## Direct answer and decision summary\nUpdate content.",
            'excerpt' => 'An update.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('"@type":"NewsArticle"', false);
    }

    public function test_faq_article_includes_faqpage_schema_with_real_question_answer_pairs(): void
    {
        $category = Category::query()->create(['name' => 'FAQ & Help Center', 'slug' => 'faq-help-center']);
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $article = Article::query()->create([
            'title' => 'Is LightInTheBox Official?',
            'slug' => 'is-litb-official',
            'content' => "## Direct answer and decision summary\nYes.\n\n## Frequently asked questions\n\n### Is LightInTheBox an official online store?\nYes, it is an official retailer.",
            'excerpt' => 'FAQ.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $response = $this->get(route('site.article', $article->slug));

        $response->assertOk();
        $response->assertSee('"@type":"FAQPage"', false);
        $response->assertSee('Is LightInTheBox an official online store?', false);
    }

    public function test_category_page_includes_breadcrumb_schema(): void
    {
        $category = Category::query()->create(['name' => 'Comparisons', 'slug' => 'comparisons']);

        $this->get(route('site.category', $category->slug))
            ->assertOk()
            ->assertSee('"@type":"BreadcrumbList"', false);
    }
}
```

- [ ] **Step 6: Run the tests and verify they fail**

Run: `docker compose exec -T app php artisan test tests/Feature/LitbLifestyleEditorialStructuredDataTest.php`
Expected: FAIL (still `NewsArticle` everywhere, no `BreadcrumbList`, no `FAQPage`, no `Organization`).

- [ ] **Step 7: Fix `article.blade.php`'s schema block**

Replace the `@push('head')` block in `resources/views/theme/litb-lifestyle-editorial/article.blade.php` with:

```blade
@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaAtId = chr(64).'id';
        $isFaqCategory = $article->category?->slug === 'faq-help-center';
        $isNewsCategory = $article->category?->slug === 'about-lightinthebox' && str_contains(mb_strtolower((string) $article->title), 'update');
        $siteLogo = trim(\App\Support\Site\SiteSettingsBag::get('site_logo', ''));
        $publisher = array_filter([
            $schemaAtType => 'Organization',
            'name' => $siteTitle,
            'logo' => $siteLogo !== '' ? $siteLogo : null,
        ]);
        $articleSchema = array_filter([
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => $isNewsCategory ? 'NewsArticle' : 'BlogPosting',
            'headline' => $article->title,
            'description' => $pageDescription,
            'datePublished' => optional($article->published_at ?? $article->created_at)->toAtomString(),
            'dateModified' => optional($article->updated_at ?? $article->published_at ?? $article->created_at)->toAtomString(),
            'mainEntityOfPage' => [
                $schemaAtType => 'WebPage',
                $schemaAtId => $canonicalUrl ?? route('site.article', $article->slug),
            ],
            'author' => array_filter([
                $schemaAtType => 'Person',
                'name' => $article->author?->name ?? $siteTitle,
                'url' => $article->author ? route('site.home') : null,
            ]),
            'publisher' => $publisher,
            'articleSection' => $article->category?->name,
            'keywords' => $tags,
        ], fn ($value) => $value !== null && $value !== []);
        $breadcrumbItems = [
            [$schemaAtType => 'ListItem', 'position' => 1, 'name' => __('front.nav.home'), 'item' => route('site.home')],
        ];
        if ($article->category) {
            $breadcrumbItems[] = [$schemaAtType => 'ListItem', 'position' => 2, 'name' => $article->category->name, 'item' => route('site.category', $article->category->slug)];
        }
        $breadcrumbItems[] = [$schemaAtType => 'ListItem', 'position' => count($breadcrumbItems) + 1, 'name' => $article->title, 'item' => $canonicalUrl ?? route('site.article', $article->slug)];
        $breadcrumbSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'BreadcrumbList',
            'itemListElement' => $breadcrumbItems,
        ];
        $faqPairs = $isFaqCategory ? \App\Support\Site\ArticleFaqSchema::extract((string) $article->content) : [];
        $faqSchema = $faqPairs !== [] ? [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'FAQPage',
            'mainEntity' => array_map(fn (array $pair) => [
                $schemaAtType => 'Question',
                'name' => $pair['question'],
                'acceptedAnswer' => [$schemaAtType => 'Answer', 'text' => $pair['answer']],
            ], $faqPairs),
        ] : null;
    @endphp
    @if($article->category)
        <meta property="article:section" content="{{ $article->category->name }}">
    @endif
    <x-json-ld :data="$articleSchema" />
    <x-json-ld :data="$breadcrumbSchema" />
    @if($faqSchema)
        <x-json-ld :data="$faqSchema" />
    @endif
@endpush
```

- [ ] **Step 8: Add `BreadcrumbList` to `category.blade.php`**

In `resources/views/theme/litb-lifestyle-editorial/category.blade.php`, inside the existing `@push('head')` block, after the `$collectionSchema` assignment, add:

```blade
@php
    $breadcrumbSchema = [
        $schemaAtContext => 'https://schema.org',
        $schemaAtType => 'BreadcrumbList',
        'itemListElement' => [
            [$schemaAtType => 'ListItem', 'position' => 1, 'name' => __('front.nav.home'), 'item' => route('site.home')],
            [$schemaAtType => 'ListItem', 'position' => 2, 'name' => $category->name, 'item' => route('site.category', $category->slug)],
        ],
    ];
@endphp
<x-json-ld :data="$collectionSchema" />
<x-json-ld :data="$breadcrumbSchema" />
```

(This replaces the single existing `<x-json-ld :data="$collectionSchema" />` line at the end of that block.)

- [ ] **Step 9: Add site-wide `Organization` schema to `layout.blade.php`**

In `resources/views/theme/litb-lifestyle-editorial/layout.blade.php`, after the existing `<x-json-ld :data="$websiteSchema" />` line, add:

```blade
@php
    $organizationSchema = array_filter([
        $schemaAtContext => 'https://schema.org',
        $schemaAtType => 'Organization',
        'name' => $siteName,
        'url' => route('site.home'),
        'logo' => trim(\App\Support\Site\SiteSettingsBag::get('site_logo', '')) ?: null,
    ], fn ($value) => $value !== null);
@endphp
<x-json-ld :data="$organizationSchema" />
```

- [ ] **Step 10: Add `rel="noopener"` to external homepage-module links**

The shared `resources/views/site/partials/homepage-modules.blade.php` (used by every theme, including the new one) renders hero/rich_text/image_band/cta_band links without `rel`. Task 5 introduces an external CTA link (`https://www.lightinthebox.com`), so fix this now: in that file, find the line rendering `<a href="{{ $linkUrl }}" class="geo-home-module__link">` and replace it with:

```blade
@php($isExternalLink = str_starts_with($linkUrl, 'http://') || str_starts_with($linkUrl, 'https://'))
<a href="{{ $linkUrl }}" class="geo-home-module__link" @if($isExternalLink) target="_blank" rel="noopener" @endif>{{ $linkText }}</a>
```

Add a feature test `tests/Feature/HomepageModuleExternalLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageModuleExternalLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_cta_band_link_gets_rel_noopener(): void
    {
        SiteSetting::query()->create(['setting_key' => 'active_theme', 'setting_value' => 'litb-lifestyle-editorial']);
        SiteSetting::query()->create(['setting_key' => 'homepage_modules', 'setting_value' => json_encode([[
            'type' => 'cta_band', 'title' => 'Shop', 'body' => '', 'link_text' => 'Shop Now',
            'link_url' => 'https://www.lightinthebox.com', 'enabled' => true,
        ]])]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('rel="noopener"', false)
            ->assertSee('target="_blank"', false);
    }
}
```

Run: `docker compose exec -T app php artisan test tests/Feature/HomepageModuleExternalLinkTest.php`
Expected: PASS

- [ ] **Step 11: Run the tests and verify they pass**

Run: `docker compose exec -T app php artisan test tests/Feature/LitbLifestyleEditorialStructuredDataTest.php tests/Unit/ArticleFaqSchemaTest.php`
Expected: PASS

- [ ] **Step 12: Run the broader site test suite for regressions**

Run: `docker compose exec -T app php artisan test --filter=Site`
Expected: PASS (no regressions in unrelated site tests)

- [ ] **Step 13: Commit**

```bash
git add app/Support/Site/ArticleFaqSchema.php tests/Unit/ArticleFaqSchemaTest.php resources/views/theme/litb-lifestyle-editorial/article.blade.php resources/views/theme/litb-lifestyle-editorial/category.blade.php resources/views/theme/litb-lifestyle-editorial/layout.blade.php resources/views/site/partials/homepage-modules.blade.php tests/Feature/LitbLifestyleEditorialStructuredDataTest.php tests/Feature/HomepageModuleExternalLinkTest.php
git commit -m "fix: correct structured data to BlogPosting/NewsArticle/FAQPage with BreadcrumbList, Organization schema, and rel=noopener on external CTA links"
```

### Task 5: Populate Homepage Modules With Real Content

**Files:**
- Create: `app/Console/Commands/SeedLitbHomepageModulesCommand.php`
- Test: `tests/Feature/SeedLitbHomepageModulesCommandTest.php`

**Interfaces:**
- Consumes: `App\Support\Site\HomepageModuleBuilder::TYPES` (`hero`, `cta_band`, `feature_grid`, `rich_text`, `lead_form`), `App\Models\Category`, `App\Models\Article`.
- Produces: `geoflow:litb-site:seed-homepage` artisan command, idempotent, writing `site_settings.homepage_modules` (JSON list) and `site_settings.homepage_style` (JSON object).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedLitbHomepageModulesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_module_per_populated_category_plus_hero_and_about_and_is_idempotent(): void
    {
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $styleCategory = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-buying-guides', 'sort_order' => 1]);
        $emptyCategory = Category::query()->create(['name' => 'New Arrivals', 'slug' => 'new-arrivals', 'sort_order' => 4]);
        Article::query()->create([
            'title' => 'Wedding Dresses Guide', 'slug' => 'wedding-dresses-guide',
            'content' => 'Body', 'excerpt' => 'Excerpt', 'category_id' => $styleCategory->id, 'author_id' => $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);

        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();

        $modules = json_decode(SiteSetting::where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $this->assertNotEmpty($modules);
        $types = array_column($modules, 'type');
        $this->assertContains('hero', $types);
        $this->assertContains('cta_band', $types);
        $titles = array_column($modules, 'title');
        $this->assertContains('Style & Buying Guides', $titles);
        $this->assertContains('Trending Guides', $titles);
        $this->assertNotContains('New Arrivals', $titles, 'Empty categories must not produce an empty shelf.');

        $style = json_decode(SiteSetting::where('setting_key', 'homepage_style')->value('setting_value'), true);
        $this->assertSame('#b8896f', $style['accent_color']);

        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();
        $this->assertSame(1, SiteSetting::where('setting_key', 'homepage_modules')->count());
    }

    public function test_it_adds_a_newsletter_module_only_when_an_active_lead_form_exists(): void
    {
        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();
        $modulesWithoutForm = json_decode(SiteSetting::where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $this->assertNotContains('lead_form', array_column($modulesWithoutForm, 'type'));

        \App\Models\LeadForm::query()->create([
            'name' => 'Subscribe', 'slug' => 'subscribe', 'status' => \App\Models\LeadForm::STATUS_ACTIVE,
        ]);
        SiteSetting::query()->where('setting_key', 'homepage_modules')->delete();
        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();
        $modulesWithForm = json_decode(SiteSetting::where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $this->assertContains('lead_form', array_column($modulesWithForm, 'type'));
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/SeedLitbHomepageModulesCommandTest.php`
Expected: FAIL, command not found.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Console\Command;

class SeedLitbHomepageModulesCommand extends Command
{
    protected $signature = 'geoflow:litb-site:seed-homepage';

    protected $description = 'Write real, article-backed homepage modules and the LITB brand homepage style.';

    public function handle(): int
    {
        $modules = [];
        $sortOrder = 0;

        $modules[] = [
            'type' => 'hero', 'layout' => 'single', 'title' => 'LightInTheBox Style & Shopping Guides',
            'subtitle' => 'Evidence-based guidance for every LightInTheBox category',
            'body' => 'Practical, fact-checked guides to help you choose with confidence.',
            'enabled' => true, 'alignment' => 'left',
        ];

        $modules[] = [
            'type' => 'cta_band', 'layout' => 'single', 'title' => 'Featured This Season',
            'subtitle' => 'See current collections and offers on the official LightInTheBox store',
            'body' => '', 'link_text' => 'Shop LightInTheBox', 'link_url' => 'https://www.lightinthebox.com',
            'enabled' => true, 'alignment' => 'center',
        ];

        $categories = Category::query()
            ->whereIn('slug', ['style-buying-guides', 'comparisons', 'inspiration-occasions', 'shipping-payment-returns'])
            ->orderBy('sort_order')
            ->get();
        foreach ($categories as $category) {
            $articles = Article::query()->published()->where('category_id', $category->id)
                ->orderByDesc('published_at')->limit(6)->get(['title', 'excerpt', 'slug']);
            if ($articles->isEmpty()) {
                continue;
            }
            $rows = $articles->map(fn (Article $a) => implode('|', [
                $a->title,
                (string) $a->excerpt,
                route('site.article', $a->slug),
            ]))->implode("\n");
            $modules[] = [
                'type' => 'feature_grid', 'layout' => 'grid', 'title' => $category->name,
                'subtitle' => '', 'body' => $rows, 'enabled' => true, 'alignment' => 'left',
            ];
        }

        $trendingArticles = Article::query()->published()
            ->whereHas('category', fn ($q) => $q->whereIn('slug', ['style-buying-guides', 'comparisons']))
            ->orderByDesc('view_count')->orderByDesc('published_at')->limit(6)->get(['title', 'excerpt', 'slug']);
        if ($trendingArticles->isNotEmpty()) {
            $rows = $trendingArticles->map(fn (Article $a) => implode('|', [
                $a->title, (string) $a->excerpt, route('site.article', $a->slug),
            ]))->implode("\n");
            $modules[] = [
                'type' => 'feature_grid', 'layout' => 'grid', 'title' => 'Trending Guides',
                'subtitle' => 'Evergreen buying guides and comparisons', 'body' => $rows,
                'enabled' => true, 'alignment' => 'left',
            ];
        }

        $aboutCategory = Category::query()->where('slug', 'about-lightinthebox')->first();
        if ($aboutCategory) {
            $modules[] = [
                'type' => 'rich_text', 'layout' => 'split', 'title' => 'About LightInTheBox',
                'subtitle' => '', 'body' => 'LightInTheBox is a global online retailer. Read our brand story and official company information.',
                'link_text' => 'About LightInTheBox', 'link_url' => route('site.category', $aboutCategory->slug),
                'enabled' => true, 'alignment' => 'left',
            ];
        }

        $faqCategory = Category::query()->where('slug', 'faq-help-center')->first();
        if ($faqCategory) {
            $faqArticles = Article::query()->published()->where('category_id', $faqCategory->id)
                ->orderByDesc('published_at')->limit(6)->get(['title', 'excerpt', 'slug']);
            if ($faqArticles->isNotEmpty()) {
                $rows = $faqArticles->map(fn (Article $a) => implode('|', [$a->title, '', route('site.article', $a->slug)]))->implode("\n");
                $modules[] = [
                    'type' => 'feature_grid', 'layout' => 'grid', 'title' => 'Frequently Asked Questions',
                    'subtitle' => '', 'body' => $rows, 'enabled' => true, 'alignment' => 'left',
                ];
            }
        }

        $activeLeadForm = \App\Models\LeadForm::query()->where('status', \App\Models\LeadForm::STATUS_ACTIVE)->orderBy('name')->first();
        if ($activeLeadForm) {
            $modules[] = [
                'type' => 'lead_form', 'layout' => 'single', 'title' => 'Stay Updated',
                'subtitle' => 'Get new guides and official LightInTheBox updates.', 'body' => '',
                'lead_form_slug' => $activeLeadForm->slug, 'enabled' => true, 'alignment' => 'center',
            ];
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_style'],
            ['setting_value' => json_encode([
                'accent_color' => '#b8896f',
                'background_color' => '#faf6f1',
                'surface_color' => '#ffffff',
                'text_color' => '#2b2622',
                'muted_color' => '#6b5f56',
                'container_width' => 'default',
                'section_spacing' => 'relaxed',
                'radius' => 'soft',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        SiteSettingsBag::forget();

        $this->info('Homepage modules written: '.count($modules));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `docker compose exec -T app php artisan test tests/Feature/SeedLitbHomepageModulesCommandTest.php`
Expected: PASS

- [ ] **Step 5: Run the real command**

Run: `docker compose exec -T app php artisan geoflow:litb-site:seed-homepage`
Expected: "Homepage modules written: N" where N reflects however many of the 4 shelf categories plus About/FAQ actually have published articles.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SeedLitbHomepageModulesCommand.php tests/Feature/SeedLitbHomepageModulesCommandTest.php
git commit -m "feat: seed LITB homepage modules from real category content"
```

### Task 6: `robots.txt` AI-Crawler Rules and Dynamic `sitemap.xml`

**Files:**
- Create: `app/Console/Commands/PublishLitbRobotsTxtCommand.php`
- Test: `tests/Feature/PublishLitbRobotsTxtCommandTest.php`
- Create: `app/Http/Controllers/Site/SitemapController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SitemapControllerTest.php`

**Interfaces:**
- Produces: `geoflow:litb-site:publish-robots` artisan command writing `public/robots.txt`; `GET /sitemap.xml` route named `site.sitemap`.

- [ ] **Step 1: Write the failing robots.txt test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublishLitbRobotsTxtCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::put(public_path('robots.txt'), "User-agent: *\nDisallow:\n");
        parent::tearDown();
    }

    public function test_it_writes_ai_crawler_rules_and_a_sitemap_reference(): void
    {
        $this->artisan('geoflow:litb-site:publish-robots')->assertSuccessful();

        $contents = File::get(public_path('robots.txt'));

        $this->assertStringContainsString('User-agent: GPTBot', $contents);
        $this->assertStringContainsString('User-agent: ClaudeBot', $contents);
        $this->assertStringContainsString('User-agent: Google-Extended', $contents);
        $this->assertStringContainsString('User-agent: PerplexityBot', $contents);
        $this->assertStringContainsString('User-agent: Meta-ExternalAgent', $contents);
        $this->assertStringContainsString('Sitemap: '.config('app.url').'/sitemap.xml', $contents);
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/PublishLitbRobotsTxtCommandTest.php`
Expected: FAIL, command not found.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishLitbRobotsTxtCommand extends Command
{
    protected $signature = 'geoflow:litb-site:publish-robots';

    protected $description = 'Regenerate public/robots.txt with AI-crawler rules and the sitemap reference.';

    /** @var list<string> */
    private const AI_AGENTS = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'PerplexityBot', 'Meta-ExternalAgent'];

    public function handle(): int
    {
        $lines = ['User-agent: *', 'Disallow:', ''];
        foreach (self::AI_AGENTS as $agent) {
            $lines[] = "User-agent: {$agent}";
            $lines[] = 'Allow: /';
            $lines[] = '';
        }
        $lines[] = 'Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml';

        File::put(public_path('robots.txt'), implode("\n", $lines)."\n");
        $this->info('robots.txt published.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `docker compose exec -T app php artisan test tests/Feature/PublishLitbRobotsTxtCommandTest.php`
Expected: PASS

- [ ] **Step 5: Write the failing sitemap test**

```php
<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lists_home_categories_and_published_articles_only(): void
    {
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $category = Category::query()->create(['name' => 'Comparisons', 'slug' => 'comparisons']);
        $published = Article::query()->create([
            'title' => 'Heels vs Flats', 'slug' => 'heels-vs-flats', 'content' => 'Body', 'excerpt' => 'E',
            'category_id' => $category->id, 'author_id' => $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);
        $draft = Article::query()->create([
            'title' => 'Unpublished Draft', 'slug' => 'unpublished-draft', 'content' => 'Body', 'excerpt' => 'E',
            'category_id' => $category->id, 'author_id' => $author->id,
            'status' => 'draft', 'review_status' => 'pending',
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee(route('site.home'), false);
        $response->assertSee(route('site.category', $category->slug), false);
        $response->assertSee(route('site.article', $published->slug), false);
        $response->assertDontSee(route('site.article', $draft->slug), false);
    }
}
```

- [ ] **Step 6: Run the test and verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/SitemapControllerTest.php`
Expected: FAIL, route not found.

- [ ] **Step 7: Implement the controller**

```php
<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [
            ['loc' => route('site.home'), 'lastmod' => now()->toDateString()],
            ['loc' => route('site.about'), 'lastmod' => now()->toDateString()],
            ['loc' => route('site.archive'), 'lastmod' => now()->toDateString()],
        ];

        foreach (Category::query()->orderBy('sort_order')->get() as $category) {
            $urls[] = ['loc' => route('site.category', $category->slug), 'lastmod' => now()->toDateString()];
        }

        foreach (Article::query()->published()->orderByDesc('published_at')->get(['slug', 'updated_at']) as $article) {
            $urls[] = [
                'loc' => route('site.article', $article->slug),
                'lastmod' => optional($article->updated_at)->toDateString() ?? now()->toDateString(),
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.e($url['loc']).'</loc><lastmod>'.$url['lastmod'].'</lastmod></url>'."\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
```

- [ ] **Step 8: Add the route**

In `routes/web.php`, in the same public site route group as `site.home`/`site.about` (around line 61-69), add:

```php
    Route::get('/sitemap.xml', [\App\Http\Controllers\Site\SitemapController::class, 'index'])->name('site.sitemap');
```

- [ ] **Step 9: Run the test and verify it passes**

Run: `docker compose exec -T app php artisan test tests/Feature/SitemapControllerTest.php`
Expected: PASS

- [ ] **Step 10: Publish the real robots.txt**

Run: `docker compose exec -T app php artisan geoflow:litb-site:publish-robots`
Expected: "robots.txt published."; then `curl http://127.0.0.1:18082/robots.txt` and `curl http://127.0.0.1:18082/sitemap.xml` to confirm both are live (recall that a static `public/robots.txt` is served directly by the dev server before reaching Laravel, so this static regeneration approach is required, not optional, for `robots.txt` — `sitemap.xml` has no such static file and is served purely by the new route).

- [ ] **Step 11: Commit**

```bash
git add app/Console/Commands/PublishLitbRobotsTxtCommand.php tests/Feature/PublishLitbRobotsTxtCommandTest.php app/Http/Controllers/Site/SitemapController.php routes/web.php tests/Feature/SitemapControllerTest.php public/robots.txt
git commit -m "feat: add AI-crawler robots.txt rules and dynamic sitemap.xml"
```

### Task 7: Activate the New Theme and Verify End-to-End

**Files:**
- Runtime only: `site_settings.active_theme` row.

**Interfaces:**
- Consumes: everything from Tasks 1-6.

- [ ] **Step 1: Run the complete test suite**

Run: `docker compose exec -T app php artisan test`
Expected: PASS, with only the pre-existing, unrelated failures already known before this plan (locale coverage, release scripts, theme replication, image library, `PromptCompositionSeederTest`, `ContentPipelineCompatibilityTest`'s env-flag check) — no new failures.

- [ ] **Step 2: Switch the live site to the new theme**

Run: `docker compose exec -T app php artisan tinker --execute="App\Models\SiteSetting::updateOrCreate(['setting_key'=>'active_theme'],['setting_value'=>'litb-lifestyle-editorial']); App\Support\Site\SiteSettingsBag::forget();"`

- [ ] **Step 3: Verify the live site end-to-end**

Run:
```bash
curl -s -o /dev/null -w "home: %{http_code}\n" http://127.0.0.1:18082/
curl -s -o /dev/null -w "category: %{http_code}\n" http://127.0.0.1:18082/category/style-buying-guides
curl -s -o /dev/null -w "article: %{http_code}\n" http://127.0.0.1:18082/article/wedding-dresses-a-complete-style-fit-and-planning-guide
curl -s -o /dev/null -w "sitemap: %{http_code}\n" http://127.0.0.1:18082/sitemap.xml
curl -s -o /dev/null -w "robots: %{http_code}\n" http://127.0.0.1:18082/robots.txt
```
Expected: all `200`.

- [ ] **Step 4: Spot-check structured data on a live page**

Run: `curl -s http://127.0.0.1:18082/article/is-lightinthebox-an-official-online-store | grep -o '"@type":"[A-Za-z]*"'`
Expected: includes `BlogPosting` or `FAQPage` (matching that article's category), `BreadcrumbList`, `Organization`, `WebSite`.

- [ ] **Step 5: Commit the theme switch**

```bash
git add -A
git status
```

(The `active_theme` switch lives in the database, not a file — this step is to confirm no stray files were left uncommitted from manual verification steps in earlier tasks.)
