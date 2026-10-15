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
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'litb-lifestyle-editorial']);
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
        $response->assertSee('"@type": "BlogPosting"', false);
        $response->assertDontSee('"@type": "NewsArticle"', false);
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"@type": "Organization"', false);
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
            ->assertSee('"@type": "NewsArticle"', false);
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
        $response->assertSee('"@type": "FAQPage"', false);
        $response->assertSee('Is LightInTheBox an official online store?', false);
    }

    public function test_category_page_includes_breadcrumb_schema(): void
    {
        $category = Category::query()->create(['name' => 'Comparisons', 'slug' => 'comparisons']);

        $this->get(route('site.category', $category->slug))
            ->assertOk()
            ->assertSee('"@type": "BreadcrumbList"', false);
    }
}
