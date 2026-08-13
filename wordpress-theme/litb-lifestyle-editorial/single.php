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
