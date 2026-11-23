<?php

declare(strict_types=1);

/**
 * WooCommerce Single Product Template.
 *
 * Strictly presentation-focused: delegates product data preparation to `SingleProductData`,
 * preserves WooCommerce notices, native purchase/variation forms, structured data (`Product` JSON-LD),
 * native tabs/reviews, and HPOS compatibility.
 *
 * @version 1.6.4
 */

use DJGear\Theme\Template\SingleProductData;

if (!defined('ABSPATH')) {
    exit;
}

get_header('shop');

/**
 * Hook: woocommerce_before_main_content.
 *
 * @hooked WooCommerceSetup::openContentWrapper - 10
 * @hooked woocommerce_breadcrumb - 20
 * @hooked WC_Structured_Data::generate_website_data() - 30
 */
do_action('woocommerce_before_main_content');

while (have_posts()) :
    the_post();
    global $product;

    if (!$product instanceof \WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof \WC_Product) {
        continue;
    }

    /**
     * Hook: woocommerce_before_single_product.
     *
     * @hooked woocommerce_output_all_notices - 10
     */
    do_action('woocommerce_before_single_product');

    if (post_password_required()) {
        echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        continue;
    }

    $galleryItems = SingleProductData::galleryItems($product);
    $rating = SingleProductData::ratingData($product);
    $meta = SingleProductData::metaData($product);
    $compatibleDevices = SingleProductData::compatibleDevices($product);
    $skinsCallout = SingleProductData::customSkinsCallout($product, $compatibleDevices);
    $tabs = SingleProductData::productTabs();
    $related = SingleProductData::relatedProducts($product, 4);
    ?>
    <article id="product-<?php the_ID(); ?>" <?php wc_product_class('djg-single-product', $product); ?>>
        <div class="djg-product-hero">
            <?php
            get_template_part('template-parts/product/gallery', null, [
                'product' => $product,
                'items'   => $galleryItems,
            ]);

            get_template_part('template-parts/product/summary', null, [
                'product'            => $product,
                'rating'             => $rating,
                'meta'               => $meta,
                'compatible_devices' => $compatibleDevices,
                'skins_callout'      => $skinsCallout,
            ]);
            ?>
        </div>

        <?php
        if ($tabs !== []) {
            get_template_part('template-parts/product/tabs', null, [
                'tabs' => $tabs,
            ]);
        }

        if ($related !== []) {
            get_template_part('template-parts/product/related-products', null, [
                'products' => $related,
            ]);
        }
        ?>
    </article>

    <?php
    /**
     * Hook: woocommerce_after_single_product.
     */
    do_action('woocommerce_after_single_product');
endwhile;

/**
 * Hook: woocommerce_after_main_content.
 *
 * @hooked WooCommerceSetup::closeContentWrapper - 10
 */
do_action('woocommerce_after_main_content');

get_footer('shop');
