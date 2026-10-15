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
