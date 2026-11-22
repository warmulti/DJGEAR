<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
    </main>
    <?php get_template_part('template-parts/footer/site-footer'); ?>
</div>
<?php
get_template_part('template-parts/header/mobile-drawer');
get_template_part('template-parts/header/search-overlay');
if (class_exists('WooCommerce')) {
    get_template_part('template-parts/footer/mini-cart-drawer');
}
wp_footer();
?>
</body>
</html>
