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
