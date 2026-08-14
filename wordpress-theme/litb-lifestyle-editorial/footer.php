    </main>
    <footer class="ne-footer">
        <div class="ne-shell">
            <nav class="ne-footer-nav" aria-label="Footer">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
                <?php foreach ( get_categories( [ 'orderby' => 'name', 'hide_empty' => true ] ) as $litb_footer_cat ) : ?>
                    <a href="<?php echo esc_url( get_category_link( $litb_footer_cat ) ); ?>"><?php echo esc_html( $litb_footer_cat->name ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="ne-footer-inner">
                &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. All rights reserved.
            </div>
        </div>
    </footer>
    <?php wp_footer(); ?>
</body>
</html>
