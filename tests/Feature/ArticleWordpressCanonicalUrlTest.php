<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleWordpressCanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_null_when_no_distribution_exists(): void
    {
        $article = $this->createArticle();

        $this->assertNull($article->wordpressCanonicalUrl());
    }

    public function test_it_returns_the_remote_url_of_a_synced_wordpress_distribution(): void
    {
        $article = $this->createArticle();
        $channel = $this->createChannel(['channel_type' => 'wordpress_rest']);
        $this->createDistribution($article, $channel, [
            'status' => 'synced',
            'remote_url' => 'https://litbtest.example.com/2026/08/13/my-article/',
        ]);

        $this->assertSame(
            'https://litbtest.example.com/2026/08/13/my-article/',
            $article->wordpressCanonicalUrl(),
        );
    }

    public function test_it_ignores_a_synced_distribution_on_a_non_wordpress_channel(): void
    {
        $article = $this->createArticle();
        $channel = $this->createChannel(['channel_type' => 'generic_http_api']);
        $this->createDistribution($article, $channel, [
            'status' => 'synced',
            'remote_url' => 'https://generic-target.example.com/article',
        ]);

        $this->assertNull($article->wordpressCanonicalUrl());
    }

    public function test_it_ignores_a_wordpress_distribution_that_is_not_synced_yet(): void
    {
        $article = $this->createArticle();
        $channel = $this->createChannel(['channel_type' => 'wordpress_rest']);
        $this->createDistribution($article, $channel, [
            'status' => 'queued',
            'remote_url' => null,
        ]);

        $this->assertNull($article->wordpressCanonicalUrl());
    }

    private function createArticle(): Article
    {
        $category = Category::query()->create([
            'name' => 'Canonical test',
            'slug' => 'canonical-test-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Canonical author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create([
            'title' => 'Canonical test article',
            'slug' => 'canonical-test-article-'.uniqid(),
            'excerpt' => 'Excerpt.',
            'content' => 'Body content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    private function createChannel(array $overrides): DistributionChannel
    {
        return DistributionChannel::query()->create(array_merge([
            'name' => 'Canonical target',
            'domain' => 'canonical-'.uniqid().'.example.com',
            'endpoint_url' => 'https://canonical-'.uniqid().'.example.com',
            'status' => 'active',
        ], $overrides));
    }

    private function createDistribution(Article $article, DistributionChannel $channel, array $overrides): ArticleDistribution
    {
        return ArticleDistribution::query()->create(array_merge([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'idempotency_key' => uniqid('canonical-'),
        ], $overrides));
    }
}
