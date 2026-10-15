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
