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
