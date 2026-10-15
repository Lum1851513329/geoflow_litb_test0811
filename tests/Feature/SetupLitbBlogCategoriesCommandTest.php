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
