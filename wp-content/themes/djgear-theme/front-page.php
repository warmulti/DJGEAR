<?php

declare(strict_types=1);

/**
 * DJGear Premium Dark Homepage Template.
 *
 * Strictly presentation composition via modular template parts.
 * All data queries live in `DJGear\Theme\Template\HomepageData` using native
 * WordPress, WooCommerce, and existing DJGear plugin integration points.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

get_template_part('template-parts/home/hero');
get_template_part('template-parts/home/featured-categories');
get_template_part('template-parts/home/featured-products');
get_template_part('template-parts/home/brands');
get_template_part('template-parts/home/custom-skins-promo');
get_template_part('template-parts/home/editorial-trust');
get_template_part('template-parts/home/final-cta');

get_footer();
