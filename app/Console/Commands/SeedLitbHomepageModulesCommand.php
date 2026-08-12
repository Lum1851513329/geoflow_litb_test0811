<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Console\Command;

class SeedLitbHomepageModulesCommand extends Command
{
    protected $signature = 'geoflow:litb-site:seed-homepage';

    protected $description = 'Write real, article-backed homepage modules and the LITB brand homepage style.';

    public function handle(): int
    {
        $modules = [];
        $sortOrder = 0;

        $modules[] = [
            'type' => 'hero', 'layout' => 'single', 'title' => 'LightInTheBox Style & Shopping Guides',
            'subtitle' => 'Evidence-based guidance for every LightInTheBox category',
            'body' => 'Practical, fact-checked guides to help you choose with confidence.',
            'enabled' => true, 'alignment' => 'left',
        ];

        $modules[] = [
            'type' => 'cta_band', 'layout' => 'single', 'title' => 'Featured This Season',
            'subtitle' => 'See current collections and offers on the official LightInTheBox store',
            'body' => '', 'link_text' => 'Shop LightInTheBox', 'link_url' => 'https://www.lightinthebox.com',
            'enabled' => true, 'alignment' => 'center',
        ];

        $categories = Category::query()
            ->whereIn('slug', ['style-buying-guides', 'comparisons', 'inspiration-occasions', 'shipping-payment-returns'])
            ->orderBy('sort_order')
            ->get();
        foreach ($categories as $category) {
            $articles = Article::query()->published()->where('category_id', $category->id)
                ->orderByDesc('published_at')->limit(6)->get(['title', 'excerpt', 'slug']);
            if ($articles->isEmpty()) {
                continue;
            }
            $rows = $articles->map(fn (Article $a) => implode('|', [
                $a->title,
                (string) $a->excerpt,
                route('site.article', $a->slug),
            ]))->implode("\n");
            $modules[] = [
                'type' => 'feature_grid', 'layout' => 'grid', 'title' => $category->name,
                'subtitle' => '', 'body' => $rows, 'enabled' => true, 'alignment' => 'left',
            ];
        }

        $trendingArticles = Article::query()->published()
            ->whereHas('category', fn ($q) => $q->whereIn('slug', ['style-buying-guides', 'comparisons']))
            ->orderByDesc('view_count')->orderByDesc('published_at')->limit(6)->get(['title', 'excerpt', 'slug']);
        if ($trendingArticles->isNotEmpty()) {
            $rows = $trendingArticles->map(fn (Article $a) => implode('|', [
                $a->title, (string) $a->excerpt, route('site.article', $a->slug),
            ]))->implode("\n");
            $modules[] = [
                'type' => 'feature_grid', 'layout' => 'grid', 'title' => 'Trending Guides',
                'subtitle' => 'Evergreen buying guides and comparisons', 'body' => $rows,
                'enabled' => true, 'alignment' => 'left',
            ];
        }

        $aboutCategory = Category::query()->where('slug', 'about-lightinthebox')->first();
        if ($aboutCategory) {
            $modules[] = [
                'type' => 'rich_text', 'layout' => 'split', 'title' => 'About LightInTheBox',
                'subtitle' => '', 'body' => 'LightInTheBox is a global online retailer. Read our brand story and official company information.',
                'link_text' => 'About LightInTheBox', 'link_url' => route('site.category', $aboutCategory->slug),
                'enabled' => true, 'alignment' => 'left',
            ];
        }

        $faqCategory = Category::query()->where('slug', 'faq-help-center')->first();
        if ($faqCategory) {
            $faqArticles = Article::query()->published()->where('category_id', $faqCategory->id)
                ->orderByDesc('published_at')->limit(6)->get(['title', 'excerpt', 'slug']);
            if ($faqArticles->isNotEmpty()) {
                $rows = $faqArticles->map(fn (Article $a) => implode('|', [$a->title, '', route('site.article', $a->slug)]))->implode("\n");
                $modules[] = [
                    'type' => 'feature_grid', 'layout' => 'grid', 'title' => 'Frequently Asked Questions',
                    'subtitle' => '', 'body' => $rows, 'enabled' => true, 'alignment' => 'left',
                ];
            }
        }

        $activeLeadForm = \App\Models\LeadForm::query()->where('status', \App\Models\LeadForm::STATUS_ACTIVE)->orderBy('name')->first();
        if ($activeLeadForm) {
            $modules[] = [
                'type' => 'lead_form', 'layout' => 'single', 'title' => 'Stay Updated',
                'subtitle' => 'Get new guides and official LightInTheBox updates.', 'body' => '',
                'lead_form_slug' => $activeLeadForm->slug, 'enabled' => true, 'alignment' => 'center',
            ];
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_style'],
            ['setting_value' => json_encode([
                'accent_color' => '#b8896f',
                'background_color' => '#faf6f1',
                'surface_color' => '#ffffff',
                'text_color' => '#2b2622',
                'muted_color' => '#6b5f56',
                'container_width' => 'default',
                'section_spacing' => 'relaxed',
                'radius' => 'soft',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        SiteSettingsBag::forget();

        $this->info('Homepage modules written: '.count($modules));

        return self::SUCCESS;
    }
}
