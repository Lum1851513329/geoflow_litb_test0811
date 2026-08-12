<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedLitbHomepageModulesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_module_per_populated_category_plus_hero_and_about_and_is_idempotent(): void
    {
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $styleCategory = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-buying-guides', 'sort_order' => 1]);
        $emptyCategory = Category::query()->create(['name' => 'New Arrivals', 'slug' => 'new-arrivals', 'sort_order' => 4]);
        Article::query()->create([
            'title' => 'Wedding Dresses Guide', 'slug' => 'wedding-dresses-guide',
            'content' => 'Body', 'excerpt' => 'Excerpt', 'category_id' => $styleCategory->id, 'author_id' => $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);

        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();

        $modules = json_decode(SiteSetting::where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $this->assertNotEmpty($modules);
        $types = array_column($modules, 'type');
        $this->assertContains('hero', $types);
        $this->assertContains('cta_band', $types);
        $titles = array_column($modules, 'title');
        $this->assertContains('Style & Buying Guides', $titles);
        $this->assertContains('Trending Guides', $titles);
        $this->assertNotContains('New Arrivals', $titles, 'Empty categories must not produce an empty shelf.');

        $style = json_decode(SiteSetting::where('setting_key', 'homepage_style')->value('setting_value'), true);
        $this->assertSame('#b8896f', $style['accent_color']);

        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();
        $this->assertSame(1, SiteSetting::where('setting_key', 'homepage_modules')->count());
    }

    public function test_it_adds_a_newsletter_module_only_when_an_active_lead_form_exists(): void
    {
        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();
        $modulesWithoutForm = json_decode(SiteSetting::where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $this->assertNotContains('lead_form', array_column($modulesWithoutForm, 'type'));

        \App\Models\LeadForm::query()->create([
            'name' => 'Subscribe', 'slug' => 'subscribe', 'status' => \App\Models\LeadForm::STATUS_ACTIVE,
        ]);
        SiteSetting::query()->where('setting_key', 'homepage_modules')->delete();
        $this->artisan('geoflow:litb-site:seed-homepage')->assertSuccessful();
        $modulesWithForm = json_decode(SiteSetting::where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $this->assertContains('lead_form', array_column($modulesWithForm, 'type'));
    }
}
