<?php

namespace Tests\Feature;

use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use Database\Seeders\LitbPromptLibrarySeeder;
use Database\Seeders\LitbQuarterlyBriefSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LitbQuarterlyBriefSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_ninety_complete_strategy_ready_briefs_idempotently(): void
    {
        $this->seed(LitbPromptLibrarySeeder::class);
        $this->seed(LitbQuarterlyBriefSeeder::class);
        $this->seed(LitbQuarterlyBriefSeeder::class);

        $briefs = ContentBrief::query()->where('asset_key', 'like', 'litb-quarterly-2026-q3.%')->get();
        $this->assertCount(90, $briefs);
        $this->assertSame(90, ContentStrategyVersion::query()->whereIn('content_brief_id', $briefs->modelKeys())->count());
        $this->assertSame(90, ContentBlueprintVersion::query()->whereIn('content_brief_id', $briefs->modelKeys())->count());
        $this->assertSame(90, $briefs->where('status', ContentBrief::STATUS_STRATEGY_READY)->count());
        foreach ($briefs as $brief) {
            $this->assertNotEmpty($brief->asset_provenance['seo_title'] ?? null);
            $this->assertNotEmpty($brief->asset_provenance['prompt_preset_key'] ?? null);
        }
    }
}
