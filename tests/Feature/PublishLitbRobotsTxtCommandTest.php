<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublishLitbRobotsTxtCommandTest extends TestCase
{
    private ?string $originalRobotsTxt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRobotsTxt = File::exists(public_path('robots.txt'))
            ? File::get(public_path('robots.txt'))
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalRobotsTxt !== null) {
            File::put(public_path('robots.txt'), $this->originalRobotsTxt);
        } else {
            File::delete(public_path('robots.txt'));
        }
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
