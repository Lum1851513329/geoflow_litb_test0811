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
