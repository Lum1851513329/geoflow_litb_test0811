<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LitbLifestyleEditorialThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_category_and_article_pages_render_under_the_new_theme(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'litb-lifestyle-editorial'],
        );
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-buying-guides']);
        $author = Author::query()->create(['name' => 'LightInTheBox Editorial Team', 'email' => 'editorial@lightinthebox.com']);
        $article = Article::query()->create([
            'title' => 'Wedding Dresses Guide',
            'slug' => 'wedding-dresses-guide',
            'content' => "## Direct answer and decision summary\nGuide content.",
            'excerpt' => 'A guide.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $this->get(route('site.home'))->assertOk()->assertViewIs('theme.litb-lifestyle-editorial.home');
        $this->get(route('site.category', $category->slug))->assertOk()->assertViewIs('theme.litb-lifestyle-editorial.category');
        $this->get(route('site.article', $article->slug))->assertOk()->assertViewIs('theme.litb-lifestyle-editorial.article');
    }
}
