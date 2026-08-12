<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [
            ['loc' => route('site.home'), 'lastmod' => now()->toDateString()],
            ['loc' => route('site.about'), 'lastmod' => now()->toDateString()],
            ['loc' => route('site.archive'), 'lastmod' => now()->toDateString()],
        ];

        foreach (Category::query()->orderBy('sort_order')->get() as $category) {
            $urls[] = ['loc' => route('site.category', $category->slug), 'lastmod' => now()->toDateString()];
        }

        foreach (Article::query()->published()->orderByDesc('published_at')->get(['slug', 'updated_at']) as $article) {
            $urls[] = [
                'loc' => route('site.article', $article->slug),
                'lastmod' => optional($article->updated_at)->toDateString() ?? now()->toDateString(),
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.e($url['loc']).'</loc><lastmod>'.$url['lastmod'].'</lastmod></url>'."\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
