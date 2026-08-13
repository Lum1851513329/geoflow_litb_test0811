<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncArticlesToDistributionChannelCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    public function test_it_syncs_published_task_less_articles_to_active_wordpress_channels_only(): void
    {
        $activeChannel = $this->createChannel(['status' => 'active', 'channel_type' => 'wordpress_rest']);
        $pausedChannel = $this->createChannel(['status' => 'paused', 'channel_type' => 'wordpress_rest']);
        $genericChannel = $this->createChannel(['status' => 'active', 'channel_type' => 'generic_http_api']);

        $eligible = $this->createArticle(['status' => 'published', 'published_at' => now(), 'task_id' => null]);
        $draft = $this->createArticle(['status' => 'draft', 'published_at' => null, 'task_id' => null]);

        $task = Task::query()->create(['name' => 'legacy task', 'status' => 'active', 'schedule_enabled' => 1]);
        $withTask = $this->createArticle(['status' => 'published', 'published_at' => now(), 'task_id' => $task->id]);

        $this->artisan('geoflow:distribution:sync-channel')->assertExitCode(0);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $eligible->id,
            'distribution_channel_id' => (int) $activeChannel->id,
        ]);
        $this->assertDatabaseMissing('article_distributions', ['article_id' => (int) $draft->id]);
        $this->assertDatabaseMissing('article_distributions', ['article_id' => (int) $withTask->id]);
        $this->assertDatabaseMissing('article_distributions', ['distribution_channel_id' => (int) $pausedChannel->id]);
        $this->assertDatabaseMissing('article_distributions', ['distribution_channel_id' => (int) $genericChannel->id]);
    }

    public function test_it_can_target_a_specific_channel_id(): void
    {
        $target = $this->createChannel(['status' => 'active', 'channel_type' => 'wordpress_rest']);
        $other = $this->createChannel(['status' => 'active', 'channel_type' => 'wordpress_rest']);
        $article = $this->createArticle(['status' => 'published', 'published_at' => now(), 'task_id' => null]);

        $this->artisan('geoflow:distribution:sync-channel', ['channel' => $target->id])->assertExitCode(0);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $target->id,
        ]);
        $this->assertDatabaseMissing('article_distributions', ['distribution_channel_id' => (int) $other->id]);
    }

    public function test_it_exits_cleanly_when_no_active_channels_exist(): void
    {
        $this->artisan('geoflow:distribution:sync-channel')->assertExitCode(0);

        $this->assertDatabaseCount('article_distributions', 0);
    }

    public function test_running_twice_does_not_duplicate_distribution_rows(): void
    {
        $this->createChannel(['status' => 'active', 'channel_type' => 'wordpress_rest']);
        $article = $this->createArticle(['status' => 'published', 'published_at' => now(), 'task_id' => null]);

        $this->artisan('geoflow:distribution:sync-channel')->assertExitCode(0);
        $this->artisan('geoflow:distribution:sync-channel')->assertExitCode(0);

        $this->assertSame(1, ArticleDistribution::query()->where('article_id', (int) $article->id)->count());
    }

    private function createArticle(array $overrides): Article
    {
        $category = Category::query()->create([
            'name' => 'Sync command test',
            'slug' => 'sync-command-test-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Sync command author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create(array_merge([
            'title' => 'Sync command article',
            'slug' => 'sync-command-article-'.uniqid(),
            'excerpt' => 'Excerpt.',
            'content' => 'Body content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'review_status' => 'approved',
        ], $overrides));
    }

    private function createChannel(array $overrides): DistributionChannel
    {
        return DistributionChannel::query()->create(array_merge([
            'name' => 'Sync command target',
            'domain' => 'sync-command-'.uniqid().'.example.com',
            'endpoint_url' => 'https://sync-command-'.uniqid().'.example.com',
        ], $overrides));
    }
}
