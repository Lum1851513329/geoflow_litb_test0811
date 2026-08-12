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
