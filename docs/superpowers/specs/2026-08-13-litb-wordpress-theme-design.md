# LITB WordPress Theme Design

## Goal

Give the self-hosted WordPress site (`http://47.103.117.122`, receiving synced
LITB articles from GEOFlow — see
[2026-08-13-litb-wordpress-sync-design.md](2026-08-13-litb-wordpress-sync-design.md))
a frontend that visually and structurally matches GEOFlow's own
`litb-lifestyle-editorial` theme, instead of WordPress's default theme.
Since WordPress is now the SEO/GEO-canonical copy of every synced article
(GEOFlow's own article pages already point `<link rel="canonical">` at it),
the visual and structured-data quality of the WordPress frontend matters as
much as GEOFlow's did.

## Background

`resources/views/theme/litb-lifestyle-editorial/` (Laravel Blade, ~2,500
lines across `layout.blade.php`, `home.blade.php`, `article.blade.php`,
`category.blade.php`, partials, and a 2,084-line `theme.css`) is the source
design. Its real color palette (from `theme.css`, not the stale
`tokens.json`): `--ne-bg:#faf6f1` (cream), `--ne-ink:#2b2622` (dark brown),
`--ne-red:#b8896f` (terracotta accent), `--ne-green:#7c8a6e` (sage),
`--ne-line:#e8ddd2`. Typography: serif titles (Georgia/Times/Noto Serif SC
stack, from the design work referenced in project history as Playfair
Display for the title font, Inter for UI), sans-serif UI text.

WordPress categories already mirror GEOFlow's 7-category taxonomy exactly
(auto-created during article sync): Style & Buying Guides, Comparisons,
Inspiration & Occasions, Shipping/Payment & Returns, FAQ & Help Center,
About LightInTheBox, plus the unused default "Uncategorized". Synced article
content is **rendered HTML in `post_content`** (Markdown was converted to
HTML by GEOFlow's payload builder before sending), e.g.:

```html
<h2>Frequently Asked Questions</h2>
<h3>How long does LightInTheBox customer service take to respond?</h3>
<p>According to the official help documentation...</p>
<h3>Can I contact LightInTheBox via WhatsApp?</h3>
<p>Yes. LightInTheBox offers...</p>
```

This matters for FAQ structured data (Component 5 below): the Laravel side's
`ArticleFaqSchema::extract()` parses **Markdown** headings, which cannot be
reused as-is — the WordPress theme needs its own HTML-based extractor.

## Scope

In scope:
- A new classic (non-block/non-FSE) WordPress PHP theme,
  `litb-lifestyle-editorial`, covering `front-page.php`, `single.php`,
  `category.php`, `header.php`, `footer.php`, `functions.php`.
- Structured data (JSON-LD) on all three page types, matching what GEOFlow's
  own site emits: `WebSite`+`SearchAction` and `Organization` sitewide;
  `BlogPosting`/`NewsArticle` + `BreadcrumbList` + conditional `FAQPage` on
  articles; `CollectionPage`+`ItemList` + `BreadcrumbList` on category pages
  and the homepage.
- Homepage: brand banner + content-first lead section + activity carousel +
  latest article feed + sidebar (see Component 3), both editable via the
  native WordPress Customizer (no plugin).
- Reusing `theme.css`'s design tokens and layout classes as closely as
  possible; porting the handful of Tailwind utility classes used in the
  Blade version (e.g. `text-sm leading-7 text-gray-600`, `w-4 h-4`) into
  plain CSS rules in the new theme (no Tailwind dependency in WordPress).
- AI image-generation prompts for the banner and default activity-carousel
  images (user generates and uploads these themselves via the Customizer).

Out of scope:
- Porting GEOFlow's `homepage_modules` JSON-driven module system — no
  equivalent is built for WordPress. The homepage below is a fixed
  (hard-coded) template using native WordPress data (posts, categories,
  sticky-post flag) plus the two new Customizer-editable sections.
- A "hot articles" view-count carousel (WordPress has no native view
  tracking; adding one is out of scope here).
- Any change to GEOFlow itself, the sync pipeline, or the Blade theme.
- Multilingual support (the site is English-only today, matching the synced
  content).

## Component 1: Theme scaffold

```
wp-content/themes/litb-lifestyle-editorial/
├── style.css              # WP theme header comment + small overrides
├── functions.php          # setup, enqueue, Customizer registration, JSON-LD helper
├── header.php
├── footer.php
├── front-page.php
├── single.php
├── category.php
├── template-parts/
│   ├── article-card.php
│   ├── sidebar.php
│   └── faq-schema.php     # HTML-based FAQ extractor (Component 5)
└── assets/
    ├── theme.css           # ported from the Blade theme's theme.css
    └── theme.js            # mobile nav toggle, activity carousel, Lucide icon init
```

`functions.php` registers:
- Theme supports: `title-tag`, `post-thumbnails`, `html5`.
- One nav menu location (`primary`) — not used for the top nav itself (that
  stays category-driven like the Blade version, via `get_categories()`), but
  registered for completeness/future use.
- Enqueued `assets/theme.css`, `assets/theme.js`, and a bundled static copy
  of Lucide's icon script (same approach as the Blade version — no CDN
  dependency).
- A `litb_json_ld( array $data ): void` helper that `echo`s
  `'<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>'`,
  mirroring the Blade `<x-json-ld>` component.
- Sitewide `WebSite`+`SearchAction` and `Organization` JSON-LD, output via
  the `wp_head` action (equivalent to `layout.blade.php`'s sitewide schema).

## Component 2: `header.php` / `footer.php`

Direct port of `header.blade.php` / `footer.blade.php`:
- Slim top bar showing the site name.
- Brand mark + site title (falls back to a monogram if no custom logo is
  set via **Customize → Site Identity**, same fallback behavior as the
  Blade header).
- Primary nav: Home + up to 5 categories, via `get_categories(['orderby' =>
  'name', 'hide_empty' => true])`.
- Search form (`get_search_form()`-equivalent markup, styled to match).
- Mobile nav toggle (hamburger button + slide-down category list),
  reusing the same `.ne-channel-rail` markup/classes as the Blade version.
- Footer: copyright line (`© {year} {get_bloginfo('name')}`).

## Component 3: `front-page.php`

Structure, top to bottom (per the approved mockup — Option B: content
before the promotional carousel):

1. **Banner** — Customizer-editable title, subtitle, image, link (Component
   4). Renders only if a banner image is set.
2. **Lead content** — the most recent sticky post (WordPress's native
   "stick this post to the front page" feature stands in for GEOFlow's
   `is_featured` flag) if one exists, else the latest published post.
   Same visual treatment as the Blade `ne-home-lead` block: large title
   linking to the post, excerpt, "read more" link, plus up to 4 other
   sticky/recent post headlines alongside it.
3. **Activity carousel** — Customizer-editable, up to 5 slides, auto-
   rotating (Component 4).
4. **Latest article feed** — paginated `WP_Query` of all published posts
   (10 per page, matching the Blade version's page size), each rendered via
   `template-parts/article-card.php`.
5. **Sidebar** — reuses `template-parts/sidebar.php`: an "About this site"
   panel (site tagline + link home). GEOFlow's sidebar also showed a "hot
   articles" list; since WordPress has no view-count data, this WordPress
   sidebar shows the same 4 sticky/recent posts used in the lead section
   instead (still gives readers a secondary nav aid, no new tracking
   needed).

`CollectionPage` + `ItemList` JSON-LD (first 10 posts), matching
`home.blade.php`.

## Component 4: Banner & activity carousel (WordPress Customizer)

Registered in `functions.php` via `customize_register`, under a new panel
**"Homepage Banner & Activities"**, two sections:

**Section: Banner**
- `litb_banner_title` (text)
- `litb_banner_subtitle` (text)
- `litb_banner_image` (image, `WP_Customize_Image_Control`)
- `litb_banner_link` (URL)

**Section: Activity carousel** — 5 fixed slide slots (no repeater field;
plain Customizer has no native repeater, and adding one means a plugin
dependency, which is out of scope). Per slot *N* (1–5):
- `litb_activity_{N}_title` (text)
- `litb_activity_{N}_description` (text, short)
- `litb_activity_{N}_image` (image)
- `litb_activity_{N}_link` (URL)

Rendering: `front-page.php` loops slots 1–5, skips any slot with no image
set, and renders whatever remains (so leaving slots 3–5 empty just means a
3-slide carousel, no gap). If **zero** slots have an image, the whole
carousel section is omitted.

Carousel behavior (`assets/theme.js`, vanilla JS, no library): one slide
visible at a time (image + title + description + "explore" link, all
changing together per slide — not just the image), auto-advances every 5
seconds, pauses on hover/focus, dot navigation to jump directly to a slide.
Markup pattern mirrors the Blade version's existing `.ne-hot-carousel`
carousel (same dot-indicator structure), restyled with the activity
content instead of "hot articles."

Editing flow for the user: **Appearance → Customize → Homepage Banner &
Activities**, fill in text fields, click "Select image" to upload, "Publish."
No code or developer involvement needed for future banner/activity changes.

## Component 5: `single.php`

Port of `article.blade.php`:
- Breadcrumb (Home / Category / Title).
- Title, meta row (category, published date, author, view count — WordPress
  has no native view counter; this theme omits the view-count element
  rather than fabricate a number), excerpt.
- Post content (`the_content()`).
- Tags, if any (`get_the_tags()`).
- Related articles: same-category posts, excluding the current one, up to
  4 — main column + sidebar duplicate, matching the Blade layout.

JSON-LD:
- `BlogPosting` (or `NewsArticle` if the post is in the "About
  LightInTheBox" category and the title contains "update", matching the
  Blade version's exact rule) + `BreadcrumbList`, built the same way as
  `article.blade.php` lines 16–47 translate directly to PHP.
- `FAQPage`, only when the post's category slug is `faq-help-center`, using
  a new `template-parts/faq-schema.php` helper:

  ```php
  function litb_extract_faq_pairs( string $html ): array {
      $doc = new DOMDocument();
      libxml_use_internal_errors( true );
      $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
      libxml_clear_errors();
      $xpath = new DOMXPath( $doc );

      // Find the "Frequently Asked Questions" <h2>, then every <h3> that
      // follows it (up to the next <h2>), pairing each with the <p> tags
      // between it and the next <h3>/<h2>.
      // ...
  }
  ```

  This is a fresh implementation (HTML-tree walk, not the Laravel version's
  Markdown-line-scanning `ArticleFaqSchema`), because the input format
  differs as noted in Background.

## Component 6: `category.php`

Port of `category.blade.php`: page header (category name + description,
falling back to a generic description), category tab strip (same categories
as the header nav), paginated post list via `template-parts/article-card.php`,
sidebar. `CollectionPage`+`ItemList` (first 10 posts of the category) +
`BreadcrumbList` JSON-LD.

## Component 7: `template-parts/article-card.php`

Port of `article-card.blade.php`: category pill, published date, title
link, plain-text summary (WordPress's own `wp_trim_words( get_the_excerpt()
?: get_the_content(), ... )` stands in for the Blade version's
Markdown-stripping summary logic — post content is already-rendered HTML,
so `wp_strip_all_tags()` + `wp_trim_words()` is sufficient), "read more"
link, and a text-monogram thumbnail placeholder (category-initial letter),
matching the Blade version exactly (no real thumbnail images exist yet for
these AI-generated articles).

## Image prompts (for the user to generate and upload)

Both use the theme's real palette: cream background `#faf6f1`, dark-brown
ink `#2b2622`, terracotta accent `#b8896f`, sage `#7c8a6e`. Keep images
relatively text-free — titles/CTAs render as HTML overlay, not baked into
the image, so a busy image with its own text competes with the real
heading.

**Banner** (recommended 1600×600, ~2.7:1 wide):
> A warm, editorial lifestyle photograph for a fashion and home goods
> e-commerce blog banner. Soft natural lighting, muted terracotta and cream
> color palette (#b8896f, #faf6f1), a person or curated flat-lay of
> clothing/accessories styled minimally, generous negative space on the
> left third of the frame for a text headline overlay. No text in the
> image. Photographic, not illustrated. Aspect ratio 2.7:1.

**Activity slide** (recommended 1000×560, ~16:9), one prompt per theme —
generate 2–3 varying only the bracketed theme:

> A warm, editorial-style photograph illustrating [THEME], same muted
> terracotta/cream/sage color palette as a cohesive lifestyle blog series.
> Soft natural lighting, minimal styling, some empty space in one corner
> suitable for a small text caption overlay. No text in the image.
> Aspect ratio 16:9.
>
> Suggested [THEME] values (swap in, one per slide — pick 2–3):
> - "a seasonal outfit styling guide, layered clothing on a neutral backdrop"
> - "a customer-support / help-desk moment, someone calmly reviewing a
>   package or order confirmation on a phone or laptop"
> - "a shipping and delivery moment, a neatly packed parcel with soft
>   daylight, doorstep or table setting"

## Testing

WordPress theme code has no automated test runner in this project (the repo
has no PHPUnit/WP-CLI test harness for themes, and adding one is out of
scope here). Verification is manual, against the live site, mirroring how
the Blade theme and the sync pipeline were verified earlier in this
project:

- `functions.php` loads without PHP errors/warnings (check
  `wp-content/debug.log` or `WP_DEBUG` output after activation).
- Each of the 7 categories renders its `category.php` page without errors.
- A `faq-help-center` article and a non-FAQ article both render `single.php`
  correctly; `curl` the FAQ one and confirm `FAQPage` JSON-LD is present
  with the right Q/A pairs; confirm it's absent on the non-FAQ one.
- Homepage renders with the Banner/activity Customizer sections empty
  (both optional blocks correctly omitted) and again after filling in the
  Customizer fields (both render).
- Validate JSON-LD blocks are well-formed JSON (e.g. via `python3 -m
  json.tool` on extracted `<script type="application/ld+json">` contents,
  the same spot-check method used earlier in this project for GEOFlow's own
  JSON-LD).
- Confirm `theme.css` visual output roughly matches the Blade theme's
  screenshots/behavior at desktop and mobile widths.

## Global Constraints

- No new WordPress plugins — everything (Customizer fields, carousel,
  structured data) is built with WordPress core APIs and vanilla JS.
- No change to GEOFlow itself, the sync pipeline, or `docker-compose.yml`.
- No fabricated data: no view counts, no fake "hot" ranking — omit fields
  WordPress has no real data for rather than inventing numbers.
- Reuse `theme.css`'s existing class names and design tokens; do not
  introduce a second, divergent color/typography system.
