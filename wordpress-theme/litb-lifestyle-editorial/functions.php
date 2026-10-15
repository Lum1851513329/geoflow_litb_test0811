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
        . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
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

/**
 * Echo a per-page <meta name="description"> in <head>. wp_head() fires
 * inside header.php, before single.php/category.php/front-page.php run,
 * so the description has to be computed here via conditional tags rather
 * than echoed directly from the templates.
 */
function litb_meta_description(): void {
    if ( is_singular( 'post' ) ) {
        $description = wp_trim_words( wp_strip_all_tags( get_the_excerpt() ?: get_the_content() ), 30, '…' );
    } elseif ( is_category() ) {
        $term = get_queried_object();
        $description = $term->description ?: get_bloginfo( 'description' );
    } else {
        $description = get_bloginfo( 'description' );
    }
    $description = trim( (string) $description );
    if ( $description === '' ) {
        return;
    }
    echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
}
add_action( 'wp_head', 'litb_meta_description' );

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
