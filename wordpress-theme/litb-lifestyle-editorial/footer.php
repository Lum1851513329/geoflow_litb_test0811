    </main>
    <footer class="ne-footer">
        <div class="ne-shell">
            <?php $litb_footer_tagline = get_bloginfo( 'description' ); ?>
            <?php if ( $litb_footer_tagline !== '' ) : ?>
                <p class="ne-footer-tagline"><?php echo esc_html( $litb_footer_tagline ); ?></p>
            <?php endif; ?>

            <?php
            $litb_footer_groups = [
                'Explore' => [ 'style-buying-guides', 'comparisons', 'inspiration-occasions' ],
                'Support' => [ 'faq-help-center', 'shipping-payment-returns' ],
            ];
            ?>
            <div class="ne-footer-columns">
                <?php foreach ( $litb_footer_groups as $litb_group_label => $litb_group_slugs ) : ?>
                    <div class="ne-footer-col">
                        <div class="ne-footer-col-title"><?php echo esc_html( $litb_group_label ); ?></div>
                        <?php foreach ( $litb_group_slugs as $litb_slug ) : ?>
                            <?php $litb_cat = get_category_by_slug( $litb_slug ); ?>
                            <?php if ( $litb_cat ) : ?>
                                <a href="<?php echo esc_url( get_category_link( $litb_cat ) ); ?>"><?php echo esc_html( $litb_cat->name ); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="ne-footer-col">
                    <div class="ne-footer-col-title">LightInTheBox</div>
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
                    <?php $litb_about_cat = get_category_by_slug( 'about-lightinthebox' ); ?>
                    <?php if ( $litb_about_cat ) : ?>
                        <a href="<?php echo esc_url( get_category_link( $litb_about_cat ) ); ?>"><?php echo esc_html( $litb_about_cat->name ); ?></a>
                    <?php endif; ?>
                    <a href="https://www.lightinthebox.com/" target="_blank" rel="noopener">Shop on LightInTheBox</a>
                </div>
            </div>

            <div class="ne-footer-inner">
                &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. All rights reserved.
            </div>
        </div>
    </footer>
    <?php wp_footer(); ?>
</body>
</html>
