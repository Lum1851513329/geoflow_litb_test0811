# LITB WordPress Theme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a classic PHP WordPress theme, `litb-lifestyle-editorial`, that visually and structurally matches GEOFlow's own `litb-lifestyle-editorial` Blade theme, deployed to the self-hosted WordPress test site at `http://47.103.117.122`.

**Architecture:** Nine files under a new theme directory, each a direct PHP port of the equivalent Blade file (same CSS classes, same JSON-LD shapes), plus a WordPress-Customizer-driven banner/activity-carousel section that has no Blade equivalent. No plugins; no build step — plain PHP + one ported CSS file + one small vanilla-JS file.

**Tech Stack:** WordPress core APIs only (Customizer API, `WP_Query`, `DOMDocument`/`DOMXPath` for FAQ parsing), PHP 8.3 (confirmed the WordPress container's version), vanilla JS, no npm/build tooling.

## Global Constraints

- No new WordPress plugins — Customizer fields, the carousel, and structured data are built with WordPress core APIs and vanilla JS only.
- No change to GEOFlow itself, the sync pipeline (`app/Services/GeoFlow/DistributionOrchestrator.php` etc.), or `docker-compose.yml`.
- No fabricated data: omit fields WordPress has no real data for (view counts, "hot" ranking) rather than inventing numbers.
- Reuse `theme.css`'s existing class names/tokens (`--ne-bg:#faf6f1`, `--ne-ink:#2b2622`, `--ne-red:#b8896f`, `--ne-green:#7c8a6e`, `--ne-line:#e8ddd2`) — do not introduce a second color/typography system.
- The theme has no automated test runner (no PHPUnit/WP-CLI test harness in this repo, and adding one is out of scope). Every task's verification step is a manual check against the live WordPress site over SSH — see each task's Step 2 and the conventions established earlier in this project (SSH as `lym@47.103.117.122`, WordPress runs in the `geoflow-wp` podman container, database in `geoflow-wp-db`).
- **Never write the server's `sudo` password as a literal string anywhere — not in commands, not in files, not in commit messages.** Every deploy/verification command in this plan reads it from the `LITB_WP_SUDO_PASS` environment variable (`echo \"$LITB_WP_SUDO_PASS\" | sudo -S ...`). Export it in your own shell before running any step that needs it (`export LITB_WP_SUDO_PASS='...'`); never paste the value into a Bash tool call itself, since that value is retained in tool-call history.
- Repo location: commit the theme source under `wordpress-theme/litb-lifestyle-editorial/` at the repo root (a new top-level directory, parallel to `app/`, `resources/`) — this is WordPress theme code, not a Laravel resource, so it does not belong under `resources/views/`. Each task's deploy step copies the relevant files from there to the server's `wp-content/themes/litb-lifestyle-editorial/` via `scp`.

---

### Task 1: Theme scaffold (`style.css`, `functions.php`, `index.php`)

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/style.css`
- Create: `wordpress-theme/litb-lifestyle-editorial/index.php`
- Create: `wordpress-theme/litb-lifestyle-editorial/functions.php`

**Interfaces:**
- Produces: `litb_json_ld( array $data ): void` — echoes a `<script type="application/ld+json">` block. Every later task's JSON-LD output calls this function.
- Produces: the `wp_head` action prints sitewide `WebSite`+`SearchAction` and `Organization` JSON-LD (no other task needs to touch this).

- [ ] **Step 1: Write `style.css`**

WordPress requires this exact comment-block format to recognize a theme (no other content is required in this file — actual styles live in `assets/theme.css`, enqueued separately):

```css
/*
Theme Name: LITB Lifestyle Editorial
Theme URI: https://47.103.117.122/
Author: LightInTheBox
Description: Editorial blog theme matching GEOFlow's litb-lifestyle-editorial design — warm cream/terracotta palette, serif headings, long-form reading layout.
Version: 1.0.0
Text Domain: litb-lifestyle-editorial
*/
```

- [ ] **Step 2: Write `index.php` (required fallback template)**

WordPress requires `index.php` to exist for a theme to be valid (it's the fallback when no more specific template matches). This theme always has a more specific template (`front-page.php`, `single.php`, `category.php`), so `index.php` only needs to be a safe minimal fallback:

```php
<?php get_header(); ?>
<div class="ne-shell ne-layout">
    <section class="ne-feed">
        <div class="ne-page-head">
            <h1 class="ne-page-title"><?php bloginfo( 'name' ); ?></h1>
        </div>
        <section class="ne-feed-card">
            <div class="ne-feed">
                <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
                    <?php get_template_part( 'template-parts/article-card' ); ?>
                <?php endwhile; else : ?>
                    <p><?php esc_html_e( 'No articles yet.', 'litb-lifestyle-editorial' ); ?></p>
                <?php endif; ?>
            </div>
        </section>
    </section>
</div>
<?php get_footer(); ?>
```

(`template-parts/article-card.php` is created in Task 3 — `index.php` is never actually hit in normal use since `front-page.php`/`single.php`/`category.php` cover every real page, but WordPress still requires the file to exist and it must not fatal-error if ever reached.)

- [ ] **Step 3: Write `functions.php`**

```php
<?php
/**
 * litb-lifestyle-editorial theme functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function litb_theme_setup(): void {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ] );
    register_nav_menus( [ 'primary' => __( 'Primary Menu', 'litb-lifestyle-editorial' ) ] );
}
add_action( 'after_setup_theme', 'litb_theme_setup' );

function litb_enqueue_assets(): void {
    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    wp_enqueue_style( 'litb-theme', $theme_uri . '/assets/theme.css', [], filemtime( $theme_dir . '/assets/theme.css' ) );
    wp_enqueue_script( 'litb-lucide', $theme_uri . '/assets/lucide.min.js', [], filemtime( $theme_dir . '/assets/lucide.min.js' ), true );
    wp_enqueue_script( 'litb-theme', $theme_uri . '/assets/theme.js', [ 'litb-lucide' ], filemtime( $theme_dir . '/assets/theme.js' ), true );
}
add_action( 'wp_enqueue_scripts', 'litb_enqueue_assets' );

/**
 * Echo a JSON-LD <script> block. Every structured-data emitter in this
 * theme calls this — see single.php, category.php, front-page.php.
 */
function litb_json_ld( array $data ): void {
    echo '<script type="application/ld+json">'
        . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        . '</script>' . "\n";
}

function litb_sitewide_json_ld(): void {
    $website_schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => get_bloginfo( 'name' ),
        'url' => home_url( '/' ),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => home_url( '/' ) . '?s={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
    litb_json_ld( $website_schema );

    $custom_logo_id = get_theme_mod( 'custom_logo' );
    $logo_url = $custom_logo_id ? wp_get_attachment_image_url( $custom_logo_id, 'full' ) : '';
    $organization_schema = array_filter( [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => get_bloginfo( 'name' ),
        'url' => home_url( '/' ),
        'logo' => $logo_url ?: null,
    ], static fn ( $value ) => $value !== null );
    litb_json_ld( $organization_schema );
}
add_action( 'wp_head', 'litb_sitewide_json_ld' );
```

- [ ] **Step 4: Deploy and activate on the server**

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp mkdir -p /var/www/html/wp-content/themes/litb-lifestyle-editorial"
scp wordpress-theme/litb-lifestyle-editorial/style.css wordpress-theme/litb-lifestyle-editorial/index.php wordpress-theme/litb-lifestyle-editorial/functions.php lym@47.103.117.122:/tmp/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/style.css geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/style.css && echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/index.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/index.php && echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/functions.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/functions.php"
```

`assets/theme.css`, `assets/theme.js`, `assets/lucide.min.js` don't exist yet (Task 2 creates the first two, this step copies a bundled Lucide script) — `functions.php`'s `filemtime()` calls will fatal-error without them, so create empty placeholders now so the theme can activate without error, to be overwritten in Task 2:

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp mkdir -p /var/www/html/wp-content/themes/litb-lifestyle-editorial/assets && echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp touch /var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/theme.css /var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/theme.js /var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/lucide.min.js"
```

Activate the theme:

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp bash -c \"cat > /tmp/activate.php <<'PHP'
<?php
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';
switch_theme('litb-lifestyle-editorial');
echo get_option('template') . PHP_EOL;
PHP
php /tmp/activate.php && rm /tmp/activate.php\""
```

Expected output: `litb-lifestyle-editorial`.

- [ ] **Step 5: Verify sitewide JSON-LD renders**

```bash
curl -s http://47.103.117.122/ | grep -A2 'application/ld+json' | head -20
```

Expected: two `<script type="application/ld+json">` blocks — one `"@type":"WebSite"`, one `"@type":"Organization"` — both valid JSON (pipe the extracted content through `python3 -m json.tool` to confirm no syntax errors, the same spot-check method used to verify JSON-LD earlier in this project).

- [ ] **Step 6: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/style.css wordpress-theme/litb-lifestyle-editorial/index.php wordpress-theme/litb-lifestyle-editorial/functions.php
git commit -m "feat: scaffold litb-lifestyle-editorial WordPress theme"
```

---

### Task 2: `header.php`, `footer.php`, and ported CSS/JS assets

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/header.php`
- Create: `wordpress-theme/litb-lifestyle-editorial/footer.php`
- Create: `wordpress-theme/litb-lifestyle-editorial/assets/theme.css` (ported from `resources/views/theme/litb-lifestyle-editorial/assets/theme.css`)
- Create: `wordpress-theme/litb-lifestyle-editorial/assets/theme.js`
- Create: `wordpress-theme/litb-lifestyle-editorial/assets/lucide.min.js` (copy of `public/js/lucide.min.js`)

**Interfaces:**
- Consumes: `litb_enqueue_assets()` from Task 1 (already references these exact filenames).
- Produces: every later template (`index.php` from Task 1, `single.php`/`category.php`/`front-page.php` in later tasks) calls `get_header()` / `get_footer()`, which load these files. CSS class names available to later tasks: `.ne-shell`, `.ne-header`, `.ne-globalbar`, `.ne-topnav`, `.ne-search`, `.ne-footer`, `.ne-channel-rail`, `.ne-channel` (all already defined in the ported `theme.css`, unchanged from the Blade version).

- [ ] **Step 1: Copy and adapt `theme.css`**

```bash
cp resources/views/theme/litb-lifestyle-editorial/assets/theme.css wordpress-theme/litb-lifestyle-editorial/assets/theme.css
```

Edit the copied file: it has no Blade-specific syntax (it's already plain CSS — the Blade files only ever `<link>` to it), so no `@if`/`{{ }}` cleanup is needed. Do one find-and-replace pass for the handful of Tailwind utility classes referenced *outside* this file, in the Blade partials (`text-sm leading-7 text-gray-600`, `w-4 h-4`) — since those utility classes won't exist without Tailwind loaded, append these plain-CSS equivalents to the end of the copied `theme.css`:

```css
.ne-utility-text-sm { font-size: 14px; line-height: 1.75; color: #4b5563; }
.ne-icon-sm { width: 16px; height: 16px; }
```

(Task 3+ templates use these two classes instead of the Tailwind ones wherever the Blade source used them.)

- [ ] **Step 2: Write `assets/theme.js`**

Ported from `public/themes/litb-lifestyle-editorial/theme.js`, keeping the existing `initLucideIcons` pattern and the existing carousel-toggle pattern (reused verbatim for the Task 7 activity carousel, renamed to `data-activity-*`):

```js
(function () {
    function initLucideIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function initActivityCarousel() {
        document.querySelectorAll('[data-activity-carousel]').forEach(function (carousel) {
            const slides = Array.from(carousel.querySelectorAll('[data-activity-slide]'));
            const dots = Array.from(carousel.querySelectorAll('[data-activity-dot]'));

            if (slides.length <= 1) {
                return;
            }

            let activeIndex = Math.max(0, slides.findIndex(function (slide) {
                return slide.classList.contains('is-active');
            }));
            let timer = null;

            const activate = function (index) {
                activeIndex = index;
                slides.forEach(function (slide, slideIndex) {
                    slide.classList.toggle('is-active', slideIndex === index);
                });
                dots.forEach(function (dot, dotIndex) {
                    dot.classList.toggle('is-active', dotIndex === index);
                });
            };

            const start = function () {
                timer = window.setInterval(function () {
                    activate((activeIndex + 1) % slides.length);
                }, 5000);
            };
            const stop = function () {
                if (timer) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };

            dots.forEach(function (dot, index) {
                dot.addEventListener('click', function () {
                    activate(index);
                });
            });

            carousel.addEventListener('mouseenter', stop);
            carousel.addEventListener('mouseleave', start);
            carousel.addEventListener('focusin', stop);
            carousel.addEventListener('focusout', start);

            start();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLucideIcons();
        initActivityCarousel();
    });
})();
```

- [ ] **Step 3: Copy Lucide**

```bash
cp public/js/lucide.min.js wordpress-theme/litb-lifestyle-editorial/assets/lucide.min.js
```

- [ ] **Step 4: Write `header.php`**

Ported from `resources/views/theme/litb-lifestyle-editorial/partials/header.blade.php` + the `<head>` portion of `layout.blade.php` (SEO meta tags, sitewide JSON-LD already handled by `wp_head` from Task 1):

```php
<?php
$litb_is_home = is_front_page();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'ne-body' ); ?>>
<header class="ne-header">
    <div class="ne-globalbar">
        <div class="ne-shell ne-globalbar-row">
            <span><?php bloginfo( 'name' ); ?></span>
        </div>
    </div>
    <div class="ne-shell">
        <div class="ne-header-row">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ne-brand" aria-label="<?php bloginfo( 'name' ); ?>">
                <?php if ( has_custom_logo() ) : ?>
                    <?php the_custom_logo(); ?>
                <?php else : ?>
                    <span class="ne-brand-mark"><?php echo esc_html( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ); ?></span>
                    <span><?php bloginfo( 'name' ); ?></span>
                <?php endif; ?>
            </a>

            <nav class="ne-topnav" aria-label="Primary">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="<?php echo $litb_is_home ? 'is-active' : ''; ?>">Home</a>
                <?php foreach ( get_categories( [ 'orderby' => 'name', 'hide_empty' => true, 'number' => 5 ] ) as $litb_cat ) : ?>
                    <a href="<?php echo esc_url( get_category_link( $litb_cat ) ); ?>" class="<?php echo is_category( $litb_cat->term_id ) ? 'is-active' : ''; ?>"><?php echo esc_html( $litb_cat->name ); ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="ne-search" role="search">
                <input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="Search articles">
                <button type="submit">Search</button>
            </form>

            <button type="button" class="ne-mobile-menu" onclick="document.getElementById('neMobileNav')?.classList.toggle('hidden')" aria-label="Categories">
                <i data-lucide="menu" class="ne-icon-sm"></i>
            </button>
        </div>
        <div id="neMobileNav" class="hidden pb-4">
            <div class="ne-channel-rail !sticky !top-auto">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ne-channel <?php echo $litb_is_home ? 'is-active' : ''; ?>">Home</a>
                <?php foreach ( get_categories( [ 'orderby' => 'name', 'hide_empty' => true ] ) as $litb_cat ) : ?>
                    <a href="<?php echo esc_url( get_category_link( $litb_cat ) ); ?>" class="ne-channel <?php echo is_category( $litb_cat->term_id ) ? 'is-active' : ''; ?>"><?php echo esc_html( $litb_cat->name ); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</header>
<main class="ne-main">
```

(`<meta name="description">` and other per-page SEO tags are emitted by each template via `wp_head`-independent inline `<title>`/meta — WordPress's `title-tag` support from Task 1 already handles `<title>`; a `meta description` tag is added per-template in Tasks 4/6/9 since it varies by page, matching how the Blade version computed `$pageDescription` per-controller rather than in the shared layout.)

- [ ] **Step 5: Write `footer.php`**

Ported from `footer.blade.php`:

```php
    </main>
    <footer class="ne-footer">
        <div class="ne-shell">
            <div class="ne-footer-inner">
                &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. All rights reserved.
            </div>
        </div>
    </footer>
    <?php wp_footer(); ?>
</body>
</html>
```

- [ ] **Step 6: Deploy**

```bash
scp wordpress-theme/litb-lifestyle-editorial/header.php wordpress-theme/litb-lifestyle-editorial/footer.php lym@47.103.117.122:/tmp/
scp wordpress-theme/litb-lifestyle-editorial/assets/theme.css wordpress-theme/litb-lifestyle-editorial/assets/theme.js wordpress-theme/litb-lifestyle-editorial/assets/lucide.min.js lym@47.103.117.122:/tmp/assets/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/header.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/header.php
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/footer.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/footer.php
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/assets/theme.css geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/theme.css
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/assets/theme.js geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/theme.js
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/assets/lucide.min.js geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/lucide.min.js"
```

(Create `/tmp/assets/` on the remote host first with `ssh lym@47.103.117.122 "mkdir -p /tmp/assets"` if it doesn't already exist from Task 1.)

- [ ] **Step 7: Verify**

```bash
curl -s http://47.103.117.122/ | grep -E 'ne-header|ne-topnav|ne-footer'
curl -s -o /dev/null -w '%{http_code}\n' http://47.103.117.122/wp-content/themes/litb-lifestyle-editorial/assets/theme.css
```

Expected: header/footer markup present in the HTML; CSS file returns `200`.

- [ ] **Step 8: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/header.php wordpress-theme/litb-lifestyle-editorial/footer.php wordpress-theme/litb-lifestyle-editorial/assets/
git commit -m "feat: port header/footer chrome and theme.css/theme.js to WordPress"
```

---

### Task 3: `template-parts/article-card.php` and `template-parts/sidebar.php`

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/template-parts/article-card.php`
- Create: `wordpress-theme/litb-lifestyle-editorial/template-parts/sidebar.php`

**Interfaces:**
- Consumes: nothing new from earlier tasks besides CSS classes from Task 2.
- Produces: `get_template_part( 'template-parts/article-card' )` — expects to be called inside `the_post()`/`have_posts()` loop context (uses `get_the_*()` global-post functions, no explicit `$article` parameter, matching WordPress template-part convention). `get_template_part( 'template-parts/sidebar', null, [ 'show_feed_panel' => bool ] )` — takes an `args` array (WordPress 5.5+ `get_template_part()` third argument), consumed by `category.php` (Task 6) and `front-page.php` (Task 7).

- [ ] **Step 1: Write `template-parts/article-card.php`**

Ported from `article-card.blade.php`:

```php
<?php
$litb_category = get_the_category();
$litb_category = $litb_category[0] ?? null;
$litb_category_name = $litb_category ? $litb_category->name : 'All articles';
$litb_initial = mb_substr( $litb_category_name, 0, 1 );
$litb_summary = wp_trim_words( wp_strip_all_tags( get_the_excerpt() ?: get_the_content() ), 30, '…' );
?>
<article class="ne-article-card">
    <div>
        <div class="ne-card-meta">
            <?php if ( $litb_category ) : ?>
                <a href="<?php echo esc_url( get_category_link( $litb_category ) ); ?>" class="ne-pill"><?php echo esc_html( $litb_category_name ); ?></a>
            <?php endif; ?>
            <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
        </div>
        <h2 class="ne-article-title">
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
        </h2>
        <?php if ( $litb_summary !== '' ) : ?>
            <p class="ne-article-summary"><?php echo esc_html( $litb_summary ); ?></p>
        <?php endif; ?>
        <a href="<?php the_permalink(); ?>" class="ne-card-action">
            Read more
            <i data-lucide="arrow-right" class="ne-icon-sm"></i>
        </a>
    </div>
    <a href="<?php the_permalink(); ?>" class="ne-thumb" aria-hidden="true">
        <?php echo esc_html( $litb_initial ); ?>
    </a>
</article>
```

- [ ] **Step 2: Write `template-parts/sidebar.php`**

Ported from `sidebar.blade.php`. WordPress has no view-count data, so the "hot articles" list is always the recent-posts list (per the design spec's Component 3 note — no fabricated ranking):

```php
<?php
/**
 * @var array $args  Optional. { show_feed_panel?: bool }
 */
$litb_show_feed_panel = ! empty( $args['show_feed_panel'] );
$litb_sidebar_posts = get_posts( [ 'numberposts' => 6, 'post_status' => 'publish' ] );
?>
<aside class="ne-sidebar">
    <?php if ( $litb_show_feed_panel ) : ?>
        <section class="ne-panel ne-feed-panel">
            <div class="ne-page-kicker"><?php bloginfo( 'name' ); ?></div>
            <h2 class="ne-feed-panel-title"><?php bloginfo( 'description' ); ?></h2>
        </section>
    <?php endif; ?>

    <section class="ne-panel">
        <div class="ne-section-title">
            <span class="ne-title-row">Recent articles</span>
        </div>
        <div class="ne-hot-list">
            <?php if ( $litb_sidebar_posts ) : ?>
                <?php foreach ( $litb_sidebar_posts as $litb_index => $litb_post ) : ?>
                    <a href="<?php echo esc_url( get_permalink( $litb_post ) ); ?>" class="ne-hot-item">
                        <span class="ne-hot-index"><?php echo esc_html( $litb_index + 1 ); ?></span>
                        <span><?php echo esc_html( get_the_title( $litb_post ) ); ?></span>
                    </a>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="ne-utility-text-sm">No articles yet.</p>
            <?php endif; ?>
        </div>
    </section>
</aside>
```

- [ ] **Step 3: Deploy**

```bash
ssh lym@47.103.117.122 "mkdir -p /tmp/template-parts"
scp wordpress-theme/litb-lifestyle-editorial/template-parts/article-card.php wordpress-theme/litb-lifestyle-editorial/template-parts/sidebar.php lym@47.103.117.122:/tmp/template-parts/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp mkdir -p /var/www/html/wp-content/themes/litb-lifestyle-editorial/template-parts
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/template-parts/article-card.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/template-parts/article-card.php
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/template-parts/sidebar.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/template-parts/sidebar.php"
```

- [ ] **Step 4: Verify (via `index.php`'s fallback loop from Task 1, which already calls `article-card`)**

```bash
curl -s http://47.103.117.122/ | grep -E 'ne-article-card|ne-article-title'
```

Expected: at least one `ne-article-card` block present (the homepage still falls back to `index.php`'s loop until Task 6 adds `front-page.php`).

- [ ] **Step 5: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/template-parts/
git commit -m "feat: add article-card and sidebar WordPress template parts"
```

---

### Task 4: `template-parts/faq-schema.php` (HTML-based FAQ extractor)

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/template-parts/faq-schema.php`

**Interfaces:**
- Produces: `litb_extract_faq_pairs( string $html ): array` — returns `list<array{question: string, answer: string}>`. Consumed by `single.php` in Task 5.

- [ ] **Step 1: Write the extractor**

This walks the rendered HTML, finds the `<h2>` whose text is "Frequently Asked Questions" (case-insensitive), then for every `<h3>` after it (until the next `<h2>` or end of document) collects the sibling `<p>` tags into that question's answer:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @return list<array{question: string, answer: string}>
 */
function litb_extract_faq_pairs( string $html ): array {
    if ( trim( $html ) === '' ) {
        return [];
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
    libxml_clear_errors();

    $xpath = new DOMXPath( $doc );
    $headings = $xpath->query( '//h2 | //h3' );
    if ( $headings === false || $headings->length === 0 ) {
        return [];
    }

    $in_faq_section = false;
    $pairs = [];
    $current_question = null;
    $current_answer_parts = [];

    $flush = static function () use ( &$pairs, &$current_question, &$current_answer_parts ): void {
        if ( $current_question !== null && $current_answer_parts !== [] ) {
            $pairs[] = [
                'question' => $current_question,
                'answer' => trim( implode( ' ', $current_answer_parts ) ),
            ];
        }
        $current_question = null;
        $current_answer_parts = [];
    };

    foreach ( $headings as $heading ) {
        $tag = strtolower( $heading->nodeName );
        $text = trim( $heading->textContent );

        if ( $tag === 'h2' ) {
            $flush();
            $in_faq_section = ( stripos( $text, 'frequently asked questions' ) !== false );
            continue;
        }

        // $tag === 'h3'
        if ( ! $in_faq_section ) {
            continue;
        }
        $flush();
        $current_question = $text;

        for ( $node = $heading->nextSibling; $node !== null; $node = $node->nextSibling ) {
            if ( $node->nodeType === XML_ELEMENT_NODE && in_array( strtolower( $node->nodeName ), [ 'h2', 'h3' ], true ) ) {
                break;
            }
            if ( $node->nodeType === XML_ELEMENT_NODE && strtolower( $node->nodeName ) === 'p' ) {
                $current_answer_parts[] = trim( $node->textContent );
            }
        }
    }
    $flush();

    return $pairs;
}
```

- [ ] **Step 2: Verify against a real synced FAQ article**

Deploy the file first:

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/template-parts/faq-schema.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/template-parts/faq-schema.php"
```

(Copy it to `/tmp/template-parts/` via `scp` first, same pattern as Task 3 Step 3.)

Then run it against a real FAQ post's stored content, using the same "temp debug script via `docker exec`" pattern already used earlier in this project (e.g. the `authtest.php`/`debugauth.php` checks during Application Password setup):

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp bash -c \"cat > /tmp/faqtest.php <<'PHP'
<?php
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';
require '/var/www/html/wp-content/themes/litb-lifestyle-editorial/template-parts/faq-schema.php';
\\\$posts = get_posts(['category_name' => 'faq-help-center', 'numberposts' => 1]);
\\\$pairs = litb_extract_faq_pairs(\\\$posts[0]->post_content);
echo count(\\\$pairs) . ' pairs found' . PHP_EOL;
foreach (\\\$pairs as \\\$pair) { echo '- ' . \\\$pair['question'] . PHP_EOL; }
PHP
php /tmp/faqtest.php && rm /tmp/faqtest.php\""
```

Expected: at least 2 question/answer pairs printed, matching the real questions seen earlier in the "How long does LightInTheBox customer service take to respond?" article (confirmed via the WP REST API during the design phase).

- [ ] **Step 3: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/template-parts/faq-schema.php
git commit -m "feat: add HTML-based FAQ structured-data extractor"
```

---

### Task 5: `single.php`

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/single.php`

**Interfaces:**
- Consumes: `litb_json_ld()` (Task 1), `litb_extract_faq_pairs()` (Task 4), `template-parts/article-card` CSS classes (Task 3, used for related-articles rendering but inlined here rather than via `get_template_part` since the Blade version's related-article markup differs from the card layout — index/title only, no summary).
- Produces: nothing new consumed elsewhere.

- [ ] **Step 1: Write `single.php`**

Ported from `article.blade.php`:

```php
<?php get_header(); ?>
<?php
while ( have_posts() ) :
    the_post();

    $litb_category = get_the_category();
    $litb_category = $litb_category[0] ?? null;
    $litb_is_faq_category = $litb_category && $litb_category->slug === 'faq-help-center';
    $litb_is_news_category = $litb_category && $litb_category->slug === 'about-lightinthebox' && stripos( get_the_title(), 'update' ) !== false;

    $litb_custom_logo_id = get_theme_mod( 'custom_logo' );
    $litb_logo_url = $litb_custom_logo_id ? wp_get_attachment_image_url( $litb_custom_logo_id, 'full' ) : '';
    $litb_publisher = array_filter( [
        '@type' => 'Organization',
        'name' => get_bloginfo( 'name' ),
        'logo' => $litb_logo_url ?: null,
    ], static fn ( $v ) => $v !== null );

    $litb_article_schema = array_filter( [
        '@context' => 'https://schema.org',
        '@type' => $litb_is_news_category ? 'NewsArticle' : 'BlogPosting',
        'headline' => get_the_title(),
        'description' => wp_trim_words( wp_strip_all_tags( get_the_excerpt() ?: get_the_content() ), 30, '…' ),
        'datePublished' => get_the_date( 'c' ),
        'dateModified' => get_the_modified_date( 'c' ),
        'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink() ],
        'author' => [ '@type' => 'Person', 'name' => get_the_author() ],
        'publisher' => $litb_publisher,
        'articleSection' => $litb_category ? $litb_category->name : null,
        'keywords' => implode( ', ', wp_list_pluck( get_the_tags() ?: [], 'name' ) ) ?: null,
    ], static fn ( $v ) => $v !== null && $v !== [] );
    litb_json_ld( $litb_article_schema );

    $litb_breadcrumb_items = [
        [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ],
    ];
    if ( $litb_category ) {
        $litb_breadcrumb_items[] = [ '@type' => 'ListItem', 'position' => 2, 'name' => $litb_category->name, 'item' => get_category_link( $litb_category ) ];
    }
    $litb_breadcrumb_items[] = [ '@type' => 'ListItem', 'position' => count( $litb_breadcrumb_items ) + 1, 'name' => get_the_title(), 'item' => get_permalink() ];
    litb_json_ld( [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $litb_breadcrumb_items,
    ] );

    if ( $litb_is_faq_category ) {
        require_once get_stylesheet_directory() . '/template-parts/faq-schema.php';
        $litb_faq_pairs = litb_extract_faq_pairs( get_the_content() );
        if ( $litb_faq_pairs !== [] ) {
            litb_json_ld( [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map( static fn ( array $pair ) => [
                    '@type' => 'Question',
                    'name' => $pair['question'],
                    'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $pair['answer'] ],
                ], $litb_faq_pairs ),
            ] );
        }
    }

    $litb_related = get_posts( [
        'category__in' => $litb_category ? [ $litb_category->term_id ] : [],
        'post__not_in' => [ get_the_ID() ],
        'numberposts' => 4,
    ] );
?>
    <div class="ne-shell ne-article-layout">
        <main class="ne-post-column">
            <nav class="ne-breadcrumb" aria-label="Breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
                <?php if ( $litb_category ) : ?>
                    <span>/</span>
                    <a href="<?php echo esc_url( get_category_link( $litb_category ) ); ?>"><?php echo esc_html( $litb_category->name ); ?></a>
                <?php endif; ?>
                <span>/</span>
                <span><?php the_title(); ?></span>
            </nav>

            <article class="ne-article-main">
                <h1 class="ne-article-h1"><?php the_title(); ?></h1>

                <div class="ne-post-info">
                    <?php if ( $litb_category ) : ?>
                        <a href="<?php echo esc_url( get_category_link( $litb_category ) ); ?>"><?php echo esc_html( $litb_category->name ); ?></a>
                    <?php endif; ?>
                    <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'Y-m-d H:i' ) ); ?></time>
                    <span><?php the_author(); ?></span>
                </div>

                <div class="ne-prose">
                    <?php the_content(); ?>
                </div>

                <?php $litb_tags = get_the_tags(); ?>
                <?php if ( $litb_tags ) : ?>
                    <div class="ne-tag-list">
                        <?php foreach ( $litb_tags as $litb_tag ) : ?>
                            <span><?php echo esc_html( $litb_tag->name ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <?php if ( $litb_related ) : ?>
                <section class="ne-related-block">
                    <div class="ne-section-title"><span class="ne-title-row">Related articles</span></div>
                    <div class="ne-related-grid">
                        <?php foreach ( $litb_related as $litb_index => $litb_related_post ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $litb_related_post ) ); ?>" class="ne-related-card">
                                <span class="ne-related-index"><?php echo esc_html( $litb_index + 1 ); ?></span>
                                <span><?php echo esc_html( get_the_title( $litb_related_post ) ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>

        <aside class="ne-post-aside">
            <?php if ( $litb_related ) : ?>
                <section class="ne-panel">
                    <div class="ne-section-title"><span class="ne-title-row">Related articles</span></div>
                    <div class="ne-hot-list">
                        <?php foreach ( $litb_related as $litb_index => $litb_related_post ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $litb_related_post ) ); ?>" class="ne-hot-item">
                                <span class="ne-hot-index"><?php echo esc_html( $litb_index + 1 ); ?></span>
                                <span><?php echo esc_html( get_the_title( $litb_related_post ) ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="ne-panel">
                <div class="ne-section-title"><span class="ne-title-row"><?php bloginfo( 'name' ); ?></span></div>
                <p class="ne-utility-text-sm"><?php bloginfo( 'description' ); ?></p>
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ne-card-action">Home <i data-lucide="arrow-right" class="ne-icon-sm"></i></a>
            </section>
        </aside>
    </div>
<?php endwhile; ?>
<?php get_footer(); ?>
```

- [ ] **Step 2: Deploy**

```bash
scp wordpress-theme/litb-lifestyle-editorial/single.php lym@47.103.117.122:/tmp/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/single.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/single.php"
```

- [ ] **Step 3: Verify — FAQ article**

```bash
curl -s "http://47.103.117.122/wp-json/wp/v2/posts?categories=$(curl -s http://47.103.117.122/wp-json/wp/v2/categories | python3 -c "import json,sys;d=json.load(sys.stdin);print([c['id'] for c in d if 'FAQ' in c['name']][0])")&per_page=1" | python3 -c "import json,sys; print(json.load(sys.stdin)[0]['link'])"
```

Take the printed URL and:

```bash
curl -s '<url-from-above>' | grep -c 'application/ld+json'
curl -s '<url-from-above>' | grep -A5 '"@type":"FAQPage"'
```

Expected: 3 JSON-LD blocks (BlogPosting, BreadcrumbList, FAQPage) and the `FAQPage` block contains real question/answer pairs.

- [ ] **Step 4: Verify — non-FAQ article has no FAQPage block**

```bash
curl -s "http://47.103.117.122/wp-json/wp/v2/posts?categories=$(curl -s http://47.103.117.122/wp-json/wp/v2/categories | python3 -c "import json,sys;d=json.load(sys.stdin);print([c['id'] for c in d if c['name']=='Comparisons'][0])")&per_page=1" | python3 -c "import json,sys; print(json.load(sys.stdin)[0]['link'])"
curl -s '<url-from-above>' | grep -c 'application/ld+json'
```

Expected: 2 JSON-LD blocks (BlogPosting + BreadcrumbList only, no FAQPage).

- [ ] **Step 5: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/single.php
git commit -m "feat: add single.php with BlogPosting/NewsArticle/BreadcrumbList/FAQPage schema"
```

---

### Task 6: `category.php`

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/category.php`

**Interfaces:**
- Consumes: `litb_json_ld()` (Task 1), `get_template_part( 'template-parts/article-card' )` (Task 3), `get_template_part( 'template-parts/sidebar' )` (Task 3).

- [ ] **Step 1: Write `category.php`**

Ported from `category.blade.php`:

```php
<?php
get_header();
$litb_category = get_queried_object();
$litb_schema_items = [];
$litb_query_for_schema = new WP_Query( [ 'cat' => $litb_category->term_id, 'posts_per_page' => 10 ] );
foreach ( $litb_query_for_schema->posts as $litb_index => $litb_post ) {
    $litb_schema_items[] = [
        '@type' => 'ListItem',
        'position' => $litb_index + 1,
        'url' => get_permalink( $litb_post ),
        'name' => get_the_title( $litb_post ),
    ];
}
litb_json_ld( [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $litb_category->name,
    'description' => $litb_category->description ?: get_bloginfo( 'description' ),
    'url' => get_category_link( $litb_category ),
    'mainEntity' => [ '@type' => 'ItemList', 'itemListElement' => $litb_schema_items ],
] );
litb_json_ld( [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ],
        [ '@type' => 'ListItem', 'position' => 2, 'name' => $litb_category->name, 'item' => get_category_link( $litb_category ) ],
    ],
] );
?>
<div class="ne-shell ne-layout">
    <section class="ne-feed">
        <div class="ne-page-head ne-category-head">
            <div class="ne-page-kicker"><?php bloginfo( 'name' ); ?> · Categories</div>
            <h1 class="ne-page-title"><?php echo esc_html( $litb_category->name ); ?></h1>
            <p class="ne-page-desc"><?php echo esc_html( $litb_category->description ?: get_bloginfo( 'description' ) ); ?></p>
            <div class="ne-category-tabs" aria-label="Categories">
                <?php foreach ( get_categories( [ 'orderby' => 'name', 'hide_empty' => true ] ) as $litb_tab_cat ) : ?>
                    <a href="<?php echo esc_url( get_category_link( $litb_tab_cat ) ); ?>" class="<?php echo $litb_tab_cat->term_id === $litb_category->term_id ? 'is-active' : ''; ?>"><?php echo esc_html( $litb_tab_cat->name ); ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <section class="ne-feed-card">
            <div class="ne-section-title"><span class="ne-title-row"><?php echo esc_html( $litb_category->name ); ?></span></div>
            <div class="ne-feed">
                <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
                    <?php get_template_part( 'template-parts/article-card' ); ?>
                <?php endwhile; else : ?>
                    <div class="ne-empty-state">No articles in this category yet.</div>
                <?php endif; ?>
            </div>
        </section>

        <div class="mt-3">
            <?php the_posts_pagination(); ?>
        </div>
    </section>

    <?php get_template_part( 'template-parts/sidebar' ); ?>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 2: Add the `.ne-empty-state` class**

`theme.css` (Task 2) already styles empty-state markup for a different selector in the Blade version (an inline Tailwind utility class block: `rounded-2xl border border-dashed ...`). Append a plain-CSS equivalent to `assets/theme.css`:

```css
.ne-empty-state {
    border: 1px dashed var(--ne-line);
    border-radius: 16px;
    background: #fff;
    padding: 40px;
    text-align: center;
    color: var(--ne-subtle);
}
```

- [ ] **Step 3: Deploy**

```bash
scp wordpress-theme/litb-lifestyle-editorial/category.php lym@47.103.117.122:/tmp/
scp wordpress-theme/litb-lifestyle-editorial/assets/theme.css lym@47.103.117.122:/tmp/assets/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/category.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/category.php
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/assets/theme.css geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/theme.css"
```

- [ ] **Step 4: Verify**

```bash
curl -s http://47.103.117.122/category/comparisons/ | grep -c 'application/ld+json'
curl -s http://47.103.117.122/category/comparisons/ | grep -c 'ne-article-card'
```

Expected: 2 JSON-LD blocks; article-card count matches the category's real post count (12, per the earlier category listing) or the pagination page size, whichever is smaller.

- [ ] **Step 5: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/category.php wordpress-theme/litb-lifestyle-editorial/assets/theme.css
git commit -m "feat: add category.php with CollectionPage/BreadcrumbList schema"
```

---

### Task 7: `front-page.php` (lead content + latest feed, no banner/carousel yet)

**Files:**
- Create: `wordpress-theme/litb-lifestyle-editorial/front-page.php`

**Interfaces:**
- Consumes: `litb_json_ld()` (Task 1), `template-parts/article-card` and `template-parts/sidebar` (Task 3).
- Produces: the `<div class="ne-shell ne-layout">…</div>` structure that Task 8 inserts the banner/carousel markup into, right after `get_header()` and before this div.

- [ ] **Step 1: Write `front-page.php`**

Ported from `home.blade.php`, using WordPress's native "sticky posts" feature as the `is_featured` equivalent:

```php
<?php
get_header();

$litb_sticky_ids = get_option( 'sticky_posts' );
$litb_lead = null;
$litb_headlines = [];
if ( ! empty( $litb_sticky_ids ) ) {
    $litb_sticky_posts = get_posts( [ 'post__in' => $litb_sticky_ids, 'numberposts' => 5, 'orderby' => 'post__in' ] );
    $litb_lead = $litb_sticky_posts[0] ?? null;
    $litb_headlines = array_slice( $litb_sticky_posts, 1, 4 );
}
if ( ! $litb_lead ) {
    $litb_recent = get_posts( [ 'numberposts' => 5 ] );
    $litb_lead = $litb_recent[0] ?? null;
    $litb_headlines = array_slice( $litb_recent, 1, 4 );
}

$litb_query = new WP_Query( [ 'paged' => max( 1, get_query_var( 'paged' ) ), 'posts_per_page' => 10 ] );

$litb_schema_items = [];
foreach ( $litb_query->posts as $litb_index => $litb_post ) {
    $litb_schema_items[] = [
        '@type' => 'ListItem',
        'position' => $litb_index + 1,
        'url' => get_permalink( $litb_post ),
        'name' => get_the_title( $litb_post ),
    ];
}
litb_json_ld( [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => get_bloginfo( 'name' ),
    'description' => get_bloginfo( 'description' ),
    'url' => home_url( '/' ),
    'mainEntity' => [ '@type' => 'ItemList', 'itemListElement' => $litb_schema_items ],
] );
?>
<div class="ne-shell ne-layout">
    <section class="ne-feed">
        <?php if ( $litb_lead ) : ?>
            <section class="ne-home-lead">
                <div class="ne-home-lead-main">
                    <div class="ne-page-kicker"><?php bloginfo( 'description' ); ?></div>
                    <h1><a href="<?php echo esc_url( get_permalink( $litb_lead ) ); ?>"><?php echo esc_html( get_the_title( $litb_lead ) ); ?></a></h1>
                    <?php $litb_lead_excerpt = get_the_excerpt( $litb_lead ); ?>
                    <p><?php echo esc_html( $litb_lead_excerpt ?: get_bloginfo( 'description' ) ); ?></p>
                    <a href="<?php echo esc_url( get_permalink( $litb_lead ) ); ?>" class="ne-card-action">Read more</a>
                </div>
                <div class="ne-home-headlines">
                    <div class="ne-mini-title">Featured</div>
                    <?php if ( $litb_headlines ) : ?>
                        <?php foreach ( $litb_headlines as $litb_headline ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $litb_headline ) ); ?>"><?php echo esc_html( get_the_title( $litb_headline ) ); ?></a>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <span><?php bloginfo( 'description' ); ?></span>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="ne-feed-card">
            <div class="ne-section-title"><span class="ne-title-row">Latest articles</span></div>
            <div class="ne-feed">
                <?php if ( $litb_query->have_posts() ) : while ( $litb_query->have_posts() ) : $litb_query->the_post(); ?>
                    <?php get_template_part( 'template-parts/article-card' ); ?>
                <?php endwhile; wp_reset_postdata(); else : ?>
                    <div class="ne-empty-state">No articles yet.</div>
                <?php endif; ?>
            </div>
        </section>

        <div class="mt-3">
            <?php
            echo paginate_links( [
                'total' => $litb_query->max_num_pages,
                'current' => max( 1, get_query_var( 'paged' ) ),
            ] );
            ?>
        </div>
    </section>

    <?php get_template_part( 'template-parts/sidebar', null, [ 'show_feed_panel' => true ] ); ?>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 2: Deploy**

```bash
scp wordpress-theme/litb-lifestyle-editorial/front-page.php lym@47.103.117.122:/tmp/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/front-page.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/front-page.php"
```

WordPress only uses `front-page.php` when the reading setting "Your homepage displays" is set to "A static page," OR — for a "Your latest posts" setting — `front-page.php` is used automatically if present, taking priority over `home.php`/`index.php`. No settings change needed; confirm the current setting doesn't override this:

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp bash -c \"cat > /tmp/checksetting.php <<'PHP'
<?php
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';
echo get_option('show_on_front') . PHP_EOL;
PHP
php /tmp/checksetting.php && rm /tmp/checksetting.php\""
```

Expected: `posts` (WordPress's default) — `front-page.php` is used either way per WordPress's template hierarchy, so no change needed regardless of the value.

- [ ] **Step 3: Verify**

```bash
curl -s http://47.103.117.122/ | grep -c 'application/ld+json'
curl -s http://47.103.117.122/ | grep -c 'ne-article-card'
```

Expected: 3 JSON-LD blocks now (sitewide WebSite + Organization from Task 1, plus this page's CollectionPage); 10 article cards (one page of the latest feed).

- [ ] **Step 4: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/front-page.php
git commit -m "feat: add front-page.php with lead content and latest feed"
```

---

### Task 8: Banner + activity carousel (Customizer)

**Files:**
- Modify: `wordpress-theme/litb-lifestyle-editorial/functions.php` (add Customizer registration)
- Modify: `wordpress-theme/litb-lifestyle-editorial/front-page.php` (insert banner/carousel markup)
- Modify: `wordpress-theme/litb-lifestyle-editorial/assets/theme.css` (add banner/carousel styles)

**Interfaces:**
- Consumes: `litb_banner_title`/`litb_banner_subtitle`/`litb_banner_image`/`litb_banner_link` and `litb_activity_{1..5}_title`/`_description`/`_image`/`_link` theme mods (registered here, read here — no other task touches these).
- Produces: `data-activity-carousel`/`data-activity-slide`/`data-activity-dot` DOM hooks, consumed by `assets/theme.js`'s `initActivityCarousel()` (already written in Task 2).

- [ ] **Step 1: Add Customizer registration to `functions.php`**

Append to the end of the file:

```php
function litb_customize_register( WP_Customize_Manager $wp_customize ): void {
    $wp_customize->add_panel( 'litb_homepage_panel', [
        'title' => __( 'Homepage Banner & Activities', 'litb-lifestyle-editorial' ),
        'priority' => 30,
    ] );

    // --- Banner ---
    $wp_customize->add_section( 'litb_banner_section', [
        'title' => __( 'Banner', 'litb-lifestyle-editorial' ),
        'panel' => 'litb_homepage_panel',
    ] );
    $wp_customize->add_setting( 'litb_banner_title', [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
    $wp_customize->add_control( 'litb_banner_title', [ 'label' => __( 'Banner title', 'litb-lifestyle-editorial' ), 'section' => 'litb_banner_section', 'type' => 'text' ] );
    $wp_customize->add_setting( 'litb_banner_subtitle', [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
    $wp_customize->add_control( 'litb_banner_subtitle', [ 'label' => __( 'Banner subtitle', 'litb-lifestyle-editorial' ), 'section' => 'litb_banner_section', 'type' => 'text' ] );
    $wp_customize->add_setting( 'litb_banner_image', [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ] );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'litb_banner_image', [ 'label' => __( 'Banner image', 'litb-lifestyle-editorial' ), 'section' => 'litb_banner_section' ] ) );
    $wp_customize->add_setting( 'litb_banner_link', [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ] );
    $wp_customize->add_control( 'litb_banner_link', [ 'label' => __( 'Banner link URL', 'litb-lifestyle-editorial' ), 'section' => 'litb_banner_section', 'type' => 'url' ] );

    // --- Activity carousel (5 fixed slots) ---
    $wp_customize->add_section( 'litb_activity_section', [
        'title' => __( 'Activity carousel', 'litb-lifestyle-editorial' ),
        'panel' => 'litb_homepage_panel',
    ] );
    for ( $i = 1; $i <= 5; $i++ ) {
        $wp_customize->add_setting( "litb_activity_{$i}_title", [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
        $wp_customize->add_control( "litb_activity_{$i}_title", [ 'label' => sprintf( __( 'Slide %d title', 'litb-lifestyle-editorial' ), $i ), 'section' => 'litb_activity_section', 'type' => 'text' ] );
        $wp_customize->add_setting( "litb_activity_{$i}_description", [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
        $wp_customize->add_control( "litb_activity_{$i}_description", [ 'label' => sprintf( __( 'Slide %d description', 'litb-lifestyle-editorial' ), $i ), 'section' => 'litb_activity_section', 'type' => 'text' ] );
        $wp_customize->add_setting( "litb_activity_{$i}_image", [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ] );
        $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, "litb_activity_{$i}_image", [ 'label' => sprintf( __( 'Slide %d image', 'litb-lifestyle-editorial' ), $i ), 'section' => 'litb_activity_section' ] ) );
        $wp_customize->add_setting( "litb_activity_{$i}_link", [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ] );
        $wp_customize->add_control( "litb_activity_{$i}_link", [ 'label' => sprintf( __( 'Slide %d link URL', 'litb-lifestyle-editorial' ), $i ), 'section' => 'litb_activity_section', 'type' => 'url' ] );
    }
}
add_action( 'customize_register', 'litb_customize_register' );
```

- [ ] **Step 2: Insert banner/carousel markup into `front-page.php`**

Insert immediately after `get_header();` and before `<div class="ne-shell ne-layout">`:

```php
<?php
$litb_banner_image = get_theme_mod( 'litb_banner_image', '' );
if ( $litb_banner_image !== '' ) :
    $litb_banner_link = get_theme_mod( 'litb_banner_link', '' ) ?: home_url( '/' );
?>
<a href="<?php echo esc_url( $litb_banner_link ); ?>" class="ne-shell ne-banner" style="background-image:url('<?php echo esc_url( $litb_banner_image ); ?>')">
    <div class="ne-banner-copy">
        <?php $litb_banner_title = get_theme_mod( 'litb_banner_title', '' ); ?>
        <?php if ( $litb_banner_title !== '' ) : ?><h2><?php echo esc_html( $litb_banner_title ); ?></h2><?php endif; ?>
        <?php $litb_banner_subtitle = get_theme_mod( 'litb_banner_subtitle', '' ); ?>
        <?php if ( $litb_banner_subtitle !== '' ) : ?><p><?php echo esc_html( $litb_banner_subtitle ); ?></p><?php endif; ?>
    </div>
</a>
<?php endif; ?>
```

Insert the activity carousel between the `</section>` closing the `.ne-home-lead`/latest-feed lead block and the sidebar `get_template_part` call — i.e., right after the `<section class="ne-feed-card">…</section>` block that renders the latest feed, still inside `<section class="ne-feed">`:

```php
<?php
$litb_activity_slides = [];
for ( $i = 1; $i <= 5; $i++ ) {
    $image = get_theme_mod( "litb_activity_{$i}_image", '' );
    if ( $image === '' ) {
        continue;
    }
    $litb_activity_slides[] = [
        'title' => get_theme_mod( "litb_activity_{$i}_title", '' ),
        'description' => get_theme_mod( "litb_activity_{$i}_description", '' ),
        'image' => $image,
        'link' => get_theme_mod( "litb_activity_{$i}_link", '' ) ?: home_url( '/' ),
    ];
}
?>
<?php if ( $litb_activity_slides !== [] ) : ?>
    <div class="ne-activity-carousel" data-activity-carousel>
        <?php foreach ( $litb_activity_slides as $litb_index => $litb_slide ) : ?>
            <a href="<?php echo esc_url( $litb_slide['link'] ); ?>" class="ne-activity-slide <?php echo $litb_index === 0 ? 'is-active' : ''; ?>" data-activity-slide style="background-image:url('<?php echo esc_url( $litb_slide['image'] ); ?>')">
                <div class="ne-activity-copy">
                    <?php if ( $litb_slide['title'] !== '' ) : ?><h3><?php echo esc_html( $litb_slide['title'] ); ?></h3><?php endif; ?>
                    <?php if ( $litb_slide['description'] !== '' ) : ?><p><?php echo esc_html( $litb_slide['description'] ); ?></p><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if ( count( $litb_activity_slides ) > 1 ) : ?>
            <div class="ne-activity-dots" aria-hidden="true">
                <?php foreach ( $litb_activity_slides as $litb_index => $litb_slide ) : ?>
                    <button type="button" class="<?php echo $litb_index === 0 ? 'is-active' : ''; ?>" data-activity-dot></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
```

- [ ] **Step 3: Add banner/carousel CSS to `assets/theme.css`**

```css
.ne-banner {
    display: block;
    margin-top: 16px;
    border-radius: 12px;
    background-size: cover;
    background-position: center;
    min-height: 220px;
    padding: 32px;
    display: flex;
    align-items: flex-end;
}

.ne-banner-copy {
    background: linear-gradient(180deg, transparent, rgba(43, 38, 34, 0.75));
    padding: 20px;
    border-radius: 8px;
    color: #fff;
    max-width: 480px;
}

.ne-banner-copy h2 {
    font-family: var(--ne-title-font, Georgia, serif);
    font-size: 28px;
    margin: 0 0 8px;
}

.ne-activity-carousel {
    position: relative;
    margin: 20px 0;
    border-radius: 12px;
    overflow: hidden;
    min-height: 180px;
}

.ne-activity-slide {
    display: none;
    background-size: cover;
    background-position: center;
    min-height: 180px;
    align-items: flex-end;
    padding: 20px;
}

.ne-activity-slide.is-active {
    display: flex;
}

.ne-activity-copy {
    background: rgba(43, 38, 34, 0.7);
    color: #fff;
    padding: 12px 16px;
    border-radius: 8px;
}

.ne-activity-copy h3 {
    margin: 0 0 4px;
    font-size: 18px;
}

.ne-activity-copy p {
    margin: 0;
    font-size: 14px;
}

.ne-activity-dots {
    position: absolute;
    bottom: 12px;
    right: 16px;
    display: flex;
    gap: 6px;
}

.ne-activity-dots button {
    width: 8px;
    height: 8px;
    border: 0;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.6);
}

.ne-activity-dots button.is-active {
    width: 20px;
    background: #fff;
}
```

- [ ] **Step 4: Deploy**

```bash
scp wordpress-theme/litb-lifestyle-editorial/functions.php wordpress-theme/litb-lifestyle-editorial/front-page.php lym@47.103.117.122:/tmp/
scp wordpress-theme/litb-lifestyle-editorial/assets/theme.css lym@47.103.117.122:/tmp/assets/
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/functions.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/functions.php
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/front-page.php geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/front-page.php
echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker cp /tmp/assets/theme.css geoflow-wp:/var/www/html/wp-content/themes/litb-lifestyle-editorial/assets/theme.css"
```

- [ ] **Step 5: Verify — empty state (no Customizer values set yet)**

```bash
curl -s http://47.103.117.122/ | grep -c 'ne-banner\|ne-activity-carousel'
```

Expected: `0` (both sections correctly omitted when no image is set — confirms the `if` guards work before asking the user to fill in the Customizer).

- [ ] **Step 6: Manual verification after the user fills in the Customizer**

This step can't be scripted (it requires uploading real images through the WordPress admin UI). After the user (or you, walking them through it) sets at least the banner image and one activity slide's image via **Appearance → Customize → Homepage Banner & Activities → Publish**:

```bash
curl -s http://47.103.117.122/ | grep -c 'ne-banner\|ne-activity-carousel'
```

Expected: `2` or more (banner present, at least one activity slide present).

- [ ] **Step 7: Commit**

```bash
git add wordpress-theme/litb-lifestyle-editorial/functions.php wordpress-theme/litb-lifestyle-editorial/front-page.php wordpress-theme/litb-lifestyle-editorial/assets/theme.css
git commit -m "feat: add Customizer-driven banner and activity carousel to homepage"
```

---

### Task 9: Final verification pass

**Files:** none (verification only).

- [ ] **Step 1: Confirm no PHP errors/warnings**

```bash
ssh lym@47.103.117.122 "echo \"$LITB_WP_SUDO_PASS\" | sudo -S docker exec geoflow-wp bash -c \"tail -50 /var/www/html/wp-content/debug.log 2>/dev/null || echo 'no debug log (WP_DEBUG likely off, which is fine for this test env)'\""
```

If the log shows theme-related fatal errors or warnings (filter for `litb-lifestyle-editorial` in the path), fix them before proceeding — this is the same kind of check used throughout this project (grep-based log inspection) rather than assuming success.

- [ ] **Step 2: Re-run the full spec Testing checklist**

- All 7 real categories (`http://47.103.117.122/category/{slug}/` for each of: `style-buying-guides`, `comparisons`, `inspiration-occasions`, `shipping-payment-returns`, `faq-help-center`, `about-lightinthebox`) return `200` and render at least one article card:

```bash
for slug in style-buying-guides comparisons inspiration-occasions shipping-payment-returns faq-help-center about-lightinthebox; do
  echo "$slug: $(curl -s -o /dev/null -w '%{http_code}' http://47.103.117.122/category/$slug/)"
done
```

- Homepage, one FAQ article, and one non-FAQ article all produce valid JSON (pipe each extracted `<script type="application/ld+json">` block through `python3 -c "import json,sys; json.loads(sys.argv[1])"` style validation, or save to a file and run `python3 -m json.tool` on it, per the project's established JSON-LD spot-check method).
- Confirm `curl -s http://47.103.117.122/` and a sampled article page both include the `.ne-header`/`.ne-footer` chrome and load `assets/theme.css` with a `200`.

- [ ] **Step 3: Confirm the GEOFlow canonical link still resolves correctly**

Since GEOFlow's own article pages point `<link rel="canonical">` at these WordPress URLs (see the sync design doc), confirm at least one previously-verified canonical URL still loads correctly under the new theme (it's the same URL — the theme change doesn't alter WordPress permalinks, only the rendered page — but this closes the loop end-to-end):

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://47.103.117.122/2026/08/13/is-lightinthebox-an-official-online-store/
```

Expected: `200`.

- [ ] **Step 4: Report the image-generation prompts to the user again for convenience**

Point back to the "Image prompts" section of `docs/superpowers/specs/2026-08-13-litb-wordpress-theme-design.md` — no action needed here beyond confirming the file is easy to find, since the user generates and uploads these images themselves via the Customizer (Task 8, Step 6 is where they'd actually do this).
