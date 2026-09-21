<?php

namespace App\Domain\ContentGeneration;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Search lightinthebox.com for products relevant to an article topic.
 *
 * Ported from scripts/collect_litb_product_evidence.py in the litbstyle rewrite project,
 * which produced the litbstyle.product-evidence.v1 artifacts. The extraction rules
 * (search-result URL pattern, og:title / og:image, JSON price/rating scalars, token-overlap
 * relevance) are kept identical so evidence captured here stays comparable with those files.
 *
 * Fetching goes through SafeOutboundHttpClient rather than a bare HTTP client: this is a
 * crawler, so it must inherit the SSRF guards, redirect limits and response size caps.
 */
final class LitbProductEvidenceCollector
{
    public const SCHEMA_VERSION = 'litbstyle.product-evidence.v1';

    private const STORE_SEARCH = 'https://www.lightinthebox.com/en/index.php';

    private const PRODUCT_URL_PATTERN = '~https://www\.lightinthebox\.com/en/p/[^"\s?<>]+_p\d+\.html~i';

    private const MAX_RESPONSE_BYTES = 2_000_000;

    /** Query-derivation stopwords, copied from the Python collector. */
    private const STOP = [
        'lightinthebox', 'from', 'that', 'actually', 'worth', 'trying', 'tested', 'review',
        'reviews', 'right', 'now', 'every', 'day', 'here', 'best', 'top', 'picks', 'under',
        'real', 'pics', 'honest', 'use', 'using',
    ];

    public function __construct(
        private readonly SafeOutboundHttpClient $client,
        private readonly Factory $http,
    ) {}

    /**
     * @return array{schema_version:string, query:string, search_url:string, captured_at:string, candidate_count:int, candidates:list<array<string,mixed>>, fetch_errors:list<array<string,string>>}
     */
    public function collect(string $topic, string $primaryKeyword = '', int $wanted = 3): array
    {
        $query = $this->deriveQuery($topic, $primaryKeyword);
        $searchUrl = self::STORE_SEARCH.'?'.http_build_query([
            'main_page' => 'advanced_search_result',
            'keyword' => $query,
        ]);

        $candidates = [];
        $errors = [];

        $searchHtml = $this->fetch($searchUrl);
        if ($searchHtml === null) {
            $errors[] = ['url' => $searchUrl, 'error' => 'search_fetch_failed'];

            return $this->result($query, $searchUrl, $candidates, $errors);
        }

        foreach ($this->productUrls($searchHtml, max(12, $wanted * 3)) as $url) {
            $html = $this->fetch($url);
            if ($html === null) {
                $errors[] = ['url' => $url, 'error' => 'product_fetch_failed'];

                continue;
            }
            $snapshot = $this->parseProductPage($url, $html);
            // A card cannot render without all four, so an incomplete snapshot is dropped
            // here rather than surfacing as a broken card later.
            if ($snapshot['product_id'] === '' || $snapshot['title'] === ''
                || $snapshot['source_image_url'] === '' || $snapshot['current_price'] === '') {
                continue;
            }
            $snapshot['relevance_score'] = $this->relevanceScore($query, $snapshot['title']);
            $candidates[] = $snapshot;
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['relevance_score'], $a['product_id']] <=> [$a['relevance_score'], $b['product_id']]);

        return $this->result($query, $searchUrl, $candidates, $errors);
    }

    /**
     * Editorial framing that appears in Semrush topic labels but never in a product listing.
     * "Clothing Retail and Shopping" is a topic; "clothing" is the product keyword.
     */
    private const EDITORIAL = [
        'shopping', 'retail', 'idea', 'ideas', 'deal', 'deals', 'sale', 'sales', 'clearance',
        'guide', 'guides', 'tip', 'tips', 'trend', 'trends', 'overview', 'understanding',
        'difference', 'differences', 'collection', 'collections', 'option', 'options',
        'affordable', 'unique', 'essential', 'essentials', 'complete', 'ultimate', 'popular',
        'online', 'store', 'stores', 'shop', 'brand', 'brands', 'style', 'styles', 'fashion',
        'inspiration', 'occasion', 'occasions', 'buying', 'purchase', 'recommendation',
        'recommendations', 'comparison', 'comparisons', 'versus', 'guidance',
        // Abstract nouns that read naturally in a topic label but never appear in a product
        // listing. Leaving one in caps the achievable score: "mother's celebrations gifts"
        // could only ever reach 0.67 because no listing contains "celebration".
        'celebration', 'celebrations', 'tradition', 'traditions', 'meaning', 'history',
        'culture', 'insight', 'insights', 'overview', 'explained', 'everything', 'need',
        'know', 'ultimate', 'various', 'different', 'types', 'type',
    ];

    /**
     * Reduce a Semrush topic label to a product keyword usable as a store search.
     *
     * Semrush topic-gap rows are cluster labels ("Kids' Christmas Toy and Gift Ideas 2024"),
     * not shopping keywords. Passing one to the store search matches poorly and, because the
     * relevance score needs every query token to appear in the product title, also caps the
     * achievable score. This drops connectors, editorial framing, years, prices and age
     * ranges, then keeps the leading product tokens.
     */
    public function extractProductKeyword(string $topic): string
    {
        $text = html_entity_decode($topic, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~\bunder\s*[€£$]?\d+(?:\.\d+)?\b~i', ' ', $text) ?? $text;
        $text = preg_replace('~[€£$]\s*\d+(?:\.\d+)?~', ' ', $text) ?? $text;
        $text = preg_replace('~\b\d+\s*-\s*\d+\s*year\s*olds?\b~i', ' ', $text) ?? $text;
        $text = preg_replace('~\b20\d{2}\b~', ' ', $text) ?? $text;
        $text = preg_replace('~\b(\d+)(st|nd|rd|th)\b~i', ' ', $text) ?? $text;

        preg_match_all("~[A-Za-z][A-Za-z'’-]*~u", $text, $matches);
        $tokens = [];
        foreach ($matches[0] ?? [] as $raw) {
            $token = mb_strtolower(str_replace(['’'], "'", $raw));
            if (in_array($token, ['and', 'or', 'for', 'with', 'the', 'of', 'to', 'in', 'on', 'a', 'an'], true)) {
                continue;
            }
            if (in_array($token, self::EDITORIAL, true) || in_array($token, self::STOP, true)) {
                continue;
            }
            if (! in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        // Nothing concrete survived (a purely editorial label); fall back to the raw topic
        // so the caller still issues a search rather than silently skipping the article.
        if ($tokens === []) {
            return trim($topic);
        }

        // Three tokens, not four. relevanceScore is overlap/total, so at four tokens a
        // three-of-four match scores 0.75 and fails a 0.8 threshold - only an exact match
        // passes either way, and an exact match is far likelier on three tokens.
        $limit = max(1, (int) config('geoflow.litb_product_keyword_tokens', 3));

        return implode(' ', array_slice($tokens, 0, $limit));
    }

    /**
     * Prefer an explicit keyword; otherwise strip brand/price/year noise out of the topic
     * and keep the first four meaningful tokens.
     */
    public function deriveQuery(string $topic, string $primaryKeyword = ''): string
    {
        $primary = trim($primaryKeyword);
        $brandHeavy = preg_match('~light\s*in\s*the\s*box|lightinthebox~i', $primary) === 1;
        // A Semrush import sets primary_keyword to the topic label itself, so a keyword that
        // still looks like a topic gets reduced rather than passed through verbatim.
        if ($primary !== '' && ! $brandHeavy) {
            return $this->extractProductKeyword($primary);
        }

        $title = html_entity_decode($topic, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('~\([^)]*lightinthebox[^)]*\)~i', ' ', $title) ?? $title;
        $title = preg_replace('~\b(?:from|on|at)\s+lightinthebox\b~i', ' ', $title) ?? $title;
        $title = preg_replace('~\bunder\s*[€£$]?\d+(?:\.\d+)?\b~i', ' ', $title) ?? $title;
        $title = preg_replace('~\b20\d{2}\b~', ' ', $title) ?? $title;

        preg_match_all("~[A-Za-z][A-Za-z'-]+~", $title, $matches);
        $tokens = array_values(array_filter(
            array_map('mb_strtolower', $matches[0] ?? []),
            static fn (string $token): bool => ! in_array($token, self::STOP, true)
        ));
        if (count($tokens) > 2 && in_array($tokens[0], ['how', 'what', 'why', 'are', 'is'], true)) {
            array_shift($tokens);
        }

        return trim(implode(' ', array_slice($tokens, 0, 4)));
    }

    /** @return list<string> */
    private function productUrls(string $html, int $limit): array
    {
        preg_match_all(self::PRODUCT_URL_PATTERN, $html, $matches);
        $urls = [];
        foreach ($matches[0] ?? [] as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
            if (count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }

    /** @return array<string,mixed> */
    private function parseProductPage(string $url, string $html): array
    {
        preg_match('~_p(\d+)\.html$~', parse_url($url, PHP_URL_PATH) ?: '', $idMatch);
        $title = $this->metaContent($html, 'og:title');
        $price = $this->firstJsonScalar($html, 'price');
        if ($price === '' && preg_match('~\$\s*([0-9]+(?:\.[0-9]+)?)~', $title, $priceMatch) === 1) {
            $price = $priceMatch[1];
        }
        $reviews = $this->firstJsonScalar($html, 'reviewCount');

        return [
            'product_id' => $idMatch[1] ?? '',
            'status' => 'candidate',
            'title' => html_entity_decode(trim(preg_replace('~\s+20\d{2}\s*-\s*[€£$].*$~', '', $title) ?? $title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'product_url' => $url,
            'source_image_url' => html_entity_decode($this->metaContent($html, 'og:image'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'current_price' => $price,
            'currency' => 'USD',
            'market' => 'US',
            'rating' => $this->firstJsonScalar($html, 'ratingValue'),
            'review_count' => $reviews === '' ? 0 : (int) (float) $reviews,
            'page_sha256' => hash('sha256', $html),
        ];
    }

    /**
     * Token overlap, ignoring grammatical filler.
     *
     * The score is |query ∩ title| / |query|, so every query token must appear in the title
     * to reach 1.0. Topic-style phrases carry words like "and"/"for"/"with" that never occur
     * in a product listing, which capped "men's and women's wallets" at 0.75 and made a
     * 0.8 threshold unreachable no matter how good the match was.
     */
    private const SCORE_FILLER = ['and', 'for', 'with', 'the', 'from', 'your', 'that', 'this', 'are', 'you'];

    public function relevanceScore(string $query, string $title): float
    {
        $queryTokens = $this->scoreTokens($query);
        if ($queryTokens === []) {
            return 0.0;
        }
        $overlap = array_intersect($queryTokens, $this->scoreTokens($title));

        return round(count($overlap) / count($queryTokens), 4);
    }

    /**
     * Tokenise and singularise for comparison.
     *
     * Extracted product keywords are almost always plural ("wallets", "slippers",
     * "leggings") while listings are often singular ("Leather Wallet RFID"), and
     * possessives differ too ("men's" vs "Mens"). Comparing raw tokens scored those pairs
     * 0.00 and silently suppressed good matches, so both sides are normalised first.
     *
     * @return list<string>
     */
    private function scoreTokens(string $text): array
    {
        preg_match_all('~[a-z0-9]+~', mb_strtolower(str_replace(['’', "'"], '', $text)), $matches);

        $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            if (mb_strlen($token) > 2 && ! in_array($token, self::SCORE_FILLER, true)) {
                $tokens[] = $this->singularise($token);
            }
        }

        return array_values(array_unique($tokens));
    }

    private function singularise(string $token): string
    {
        if (str_ends_with($token, 'ies') && mb_strlen($token) > 4) {
            return mb_substr($token, 0, -3).'y';
        }
        if (preg_match('~(ss|sh|ch|x|z)es$~', $token) === 1) {
            return mb_substr($token, 0, -2);
        }
        if (str_ends_with($token, 's') && ! str_ends_with($token, 'ss') && mb_strlen($token) > 3) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    private function metaContent(string $html, string $property): string
    {
        $pattern = '~<meta\s+property=["\']'.preg_quote($property, '~').'["\']\s+content=["\']([^"\']*)["\']~i';

        return preg_match($pattern, $html, $match) === 1 ? trim($match[1]) : '';
    }

    private function firstJsonScalar(string $html, string $key): string
    {
        $pattern = '~["\']'.preg_quote($key, '~').'["\']\s*:\s*["\']?([0-9]+(?:\.[0-9]+)?)~i';

        return preg_match($pattern, $html, $match) === 1 ? $match[1] : '';
    }

    /**
     * Fetch with retry and pacing.
     *
     * The store throttles sustained crawling: the same query returns 12 products on a cold
     * run and 0 while a batch is in flight. The Python collector this was ported from
     * retried three times with incremental backoff; dropping that made a throttled response
     * look like "this topic has no products", which is a silent and very misleading failure.
     * The inter-request delay keeps a 100-article batch from tripping the throttle at all.
     */
    private function fetch(string $url): ?string
    {
        $attempts = max(1, (int) config('geoflow.litb_product_fetch_attempts', 3));
        $delayMs = max(0, (int) config('geoflow.litb_product_fetch_delay_ms', 400));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            try {
                $request = $this->http->timeout(20)
                    ->connectTimeout(5)
                    ->withHeaders([
                        'User-Agent' => 'GEOFlow-LITB-Product-Evidence/1.0',
                        'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ]);
                $response = $this->client->get($request, $url, self::MAX_RESPONSE_BYTES);
                if ($response->status() === 200) {
                    return $response->body();
                }
            } catch (Throwable) {
                // fall through to backoff
            }
            if ($attempt < $attempts) {
                usleep($attempt * 1_000_000);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function result(string $query, string $searchUrl, array $candidates, array $errors): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'query' => $query,
            'search_url' => $searchUrl,
            'captured_at' => now()->toIso8601String(),
            'candidate_count' => count($candidates),
            'candidates' => array_values($candidates),
            'fetch_errors' => array_values($errors),
        ];
    }
}
