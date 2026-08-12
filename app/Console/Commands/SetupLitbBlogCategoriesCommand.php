<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Models\ContentGenerationRun;
use Illuminate\Console\Command;

class SetupLitbBlogCategoriesCommand extends Command
{
    protected $signature = 'geoflow:litb-site:setup-categories';

    protected $description = 'Create the LITB blog category taxonomy and recategorize published LITB articles by content pillar.';

    /** @var array<string,array{name:string,sort_order:int,pillars:list<string>}> */
    private const CATEGORIES = [
        'style-buying-guides' => ['name' => 'Style & Buying Guides', 'sort_order' => 1, 'pillars' => ['category_authority', 'product_education']],
        'comparisons' => ['name' => 'Comparisons', 'sort_order' => 2, 'pillars' => ['product_comparison']],
        'inspiration-occasions' => ['name' => 'Inspiration & Occasions', 'sort_order' => 3, 'pillars' => ['style_occasion']],
        'new-arrivals' => ['name' => 'New Arrivals', 'sort_order' => 4, 'pillars' => ['new_arrivals']],
        'shipping-payment-returns' => ['name' => 'Shipping, Payment & Returns', 'sort_order' => 5, 'pillars' => ['customer_education']],
        'about-lightinthebox' => ['name' => 'About LightInTheBox', 'sort_order' => 6, 'pillars' => ['brand_company', 'official_news']],
        'faq-help-center' => ['name' => 'FAQ & Help Center', 'sort_order' => 7, 'pillars' => ['faq']],
    ];

    public function handle(): int
    {
        $categoryIdsBySlug = [];
        foreach (self::CATEGORIES as $slug => $definition) {
            $category = Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $definition['name'], 'sort_order' => $definition['sort_order']],
            );
            $categoryIdsBySlug[$slug] = $category->id;
        }

        $pillarToSlug = [];
        foreach (self::CATEGORIES as $slug => $definition) {
            foreach ($definition['pillars'] as $pillar) {
                $pillarToSlug[$pillar] = $slug;
            }
        }

        $recategorized = 0;
        ContentGenerationRun::query()
            ->whereNotNull('article_id')
            ->with(['article', 'contentBrief'])
            ->chunkById(50, function ($runs) use (&$recategorized, $pillarToSlug, $categoryIdsBySlug): void {
                foreach ($runs as $run) {
                    $article = $run->article;
                    $pillar = (string) ($run->contentBrief?->asset_provenance['pillar'] ?? '');
                    $slug = $pillarToSlug[$pillar] ?? null;
                    if (! $article instanceof Article || $slug === null) {
                        continue;
                    }
                    $targetCategoryId = $categoryIdsBySlug[$slug];
                    if ($article->category_id !== $targetCategoryId) {
                        $article->forceFill(['category_id' => $targetCategoryId])->save();
                        $recategorized++;
                    }
                }
            });

        $this->info('Categories ensured: '.count(self::CATEGORIES));
        $this->info('Articles recategorized: '.$recategorized);

        return self::SUCCESS;
    }
}
