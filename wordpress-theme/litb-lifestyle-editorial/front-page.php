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
