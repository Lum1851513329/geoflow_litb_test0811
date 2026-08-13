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
