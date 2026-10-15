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
