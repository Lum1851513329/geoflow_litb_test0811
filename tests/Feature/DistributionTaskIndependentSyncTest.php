<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistributionTaskIndependentSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    public function test_published_task_less_article_is_enqueued_to_the_given_channel(): void
    {
        $article = $this->createArticle(['status' => 'published', 'published_at' => now()]);
        $channel = $this->createChannel(['status' => 'active']);

        app(DistributionOrchestrator::class)->enqueueForArticleToChannel($article, $channel);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_draft_article_is_not_enqueued(): void
    {
        $article = $this->createArticle(['status' => 'draft', 'published_at' => null]);
        $channel = $this->createChannel(['status' => 'active']);

        app(DistributionOrchestrator::class)->enqueueForArticleToChannel($article, $channel);

        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_paused_channel_is_not_enqueued(): void
    {
        $article = $this->createArticle(['status' => 'published', 'published_at' => now()]);
        $channel = $this->createChannel(['status' => 'paused']);

        app(DistributionOrchestrator::class)->enqueueForArticleToChannel($article, $channel);

        $this->assertDatabaseCount('article_distributions', 0);
        Queue::assertNothingPushed();
    }

    public function test_re_enqueueing_updates_the_existing_distribution_row_instead_of_duplicating(): void
    {
        $article = $this->createArticle(['status' => 'published', 'published_at' => now()]);
        $channel = $this->createChannel(['status' => 'active']);
        $orchestrator = app(DistributionOrchestrator::class);

        $orchestrator->enqueueForArticleToChannel($article, $channel);
        $orchestrator->enqueueForArticleToChannel($article, $channel);

        $this->assertSame(1, ArticleDistribution::query()
            ->where('article_id', (int) $article->id)
            ->where('distribution_channel_id', (int) $channel->id)
            ->count());
    }

    private function createArticle(array $overrides): Article
    {
        $category = Category::query()->create([
            'name' => 'Task-independent sync',
            'slug' => 'task-independent-sync-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Sync author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create(array_merge([
            'title' => 'Task-independent sync article',
            'slug' => 'task-independent-sync-article-'.uniqid(),
            'excerpt' => 'Excerpt.',
            'content' => 'Body content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => null,
            'review_status' => 'approved',
        ], $overrides));
    }

    private function createChannel(array $overrides): DistributionChannel
    {
        return DistributionChannel::query()->create(array_merge([
            'name' => 'Task-independent target',
            'domain' => 'task-independent-'.uniqid().'.example.com',
            'endpoint_url' => 'https://task-independent-'.uniqid().'.example.com',
            'channel_type' => 'wordpress_rest',
            'status' => 'active',
        ], $overrides));
    }
}
