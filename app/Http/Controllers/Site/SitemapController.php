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
        $siteLastModified = Article::query()->published()->max('updated_at');
        $siteLastModified = $siteLastModified !== null
            ? (string) $siteLastModified
            : now()->toDateString();
        $siteLastModified = substr($siteLastModified, 0, 10);

        $urls = [
            ['loc' => route('site.home'), 'lastmod' => $siteLastModified],
            ['loc' => route('site.about'), 'lastmod' => $siteLastModified],
            ['loc' => route('site.archive'), 'lastmod' => $siteLastModified],
        ];

        $categories = Category::query()
            ->whereHas('articles', fn ($query) => $query->published())
            ->withMax(['articles as latest_article_at' => fn ($query) => $query->published()], 'updated_at')
            ->orderBy('sort_order')
            ->get();
        foreach ($categories as $category) {
            $urls[] = [
                'loc' => route('site.category', $category->slug),
                'lastmod' => $category->latest_article_at !== null
                    ? substr((string) $category->latest_article_at, 0, 10)
                    : $siteLastModified,
            ];
        }

        foreach (Article::query()->published()->orderByDesc('published_at')->get(['slug', 'updated_at']) as $article) {
            $urls[] = [
                'loc' => route('site.article', $article->slug),
                'lastmod' => optional($article->updated_at)->toDateString() ?? $siteLastModified,
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
