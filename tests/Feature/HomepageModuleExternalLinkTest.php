<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageModuleExternalLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_cta_band_link_gets_rel_noopener(): void
    {
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'litb-lifestyle-editorial']);
        SiteSetting::query()->create(['setting_key' => 'homepage_modules', 'setting_value' => json_encode([[
            'type' => 'cta_band', 'title' => 'Shop', 'body' => '', 'link_text' => 'Shop Now',
            'link_url' => 'https://www.lightinthebox.com', 'enabled' => true,
        ]])]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('rel="noopener"', false)
            ->assertSee('target="_blank"', false);
    }
}
