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
