<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePageCanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_page_uses_its_own_url_as_canonical_when_not_synced_to_wordpress(): void
    {
        $article = $this->createArticle();

        $response = $this->get(route('site.article', $article->slug));

        $response->assertOk();
        $response->assertSee('<link rel="canonical" href="'.route('site.article', $article->slug).'">', false);
    }

    public function test_article_page_uses_the_wordpress_url_as_canonical_once_synced(): void
    {
        $article = $this->createArticle();
        $channel = DistributionChannel::query()->create([
            'name' => 'WP canonical target',
            'domain' => 'wp-canonical.example.com',
            'endpoint_url' => 'https://wp-canonical.example.com',
            'channel_type' => 'wordpress_rest',
            'status' => 'active',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_url' => 'https://wp-canonical.example.com/2026/08/13/canonical-page-test/',
            'idempotency_key' => uniqid('wp-canonical-'),
        ]);

        $response = $this->get(route('site.article', $article->slug));

        $response->assertOk();
        $response->assertSee('<link rel="canonical" href="https://wp-canonical.example.com/2026/08/13/canonical-page-test/">', false);
        $response->assertDontSee('<link rel="canonical" href="'.route('site.article', $article->slug).'">', false);
    }

    private function createArticle(): Article
    {
        $category = Category::query()->create([
            'name' => 'Canonical page test',
            'slug' => 'canonical-page-test-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Canonical page author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create([
            'title' => 'Canonical page article',
            'slug' => 'canonical-page-test',
            'excerpt' => 'Excerpt.',
            'content' => 'Body content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }
}
