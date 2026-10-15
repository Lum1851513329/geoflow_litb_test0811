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
