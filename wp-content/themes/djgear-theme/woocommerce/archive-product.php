<?php

declare(strict_types=1);

/**
 * WooCommerce Shop, Product Category, Brand, Tag, and Product Search Archive Template.
 *
 * Strictly presentation-focused: delegates all query preparation to `ShopQuery` and
 * view-model assembly to `ShopData`, and reuses `template-parts/components/product-card.php`.
 *
 * @version 8.6.0
 */

use DJGear\Theme\Template\ShopData;

if (!defined('ABSPATH')) {
    exit;
}

global $wp_query;

$header = ShopData::archiveHeader();
$filterModel = ShopData::filterModel();
$activeChips = ShopData::activeFilterChips();
$hasFiltersAvailable = (
    $filterModel['categories'] !== []
    || $filterModel['brands'] !== []
    || $filterModel['attributes'] !== []
    || $filterModel['price_bounds'] !== null
    || $filterModel['has_any_products']
);

get_header('shop');

/**
 * Hook: woocommerce_before_main_content.
 *
 * @hooked WooCommerceSetup::openContentWrapper - 10
 * @hooked woocommerce_breadcrumb - 20
 * @hooked WC_Structured_Data::generate_website_data() - 30
 */
do_action('woocommerce_before_main_content');

get_template_part('template-parts/shop/archive-header', null, [
    'header' => $header,
    'total'  => (int) $wp_query->found_posts,
]);

/**
 * Hook: woocommerce_before_shop_loop.
 *
 * @hooked woocommerce_output_all_notices - 10
 */
do_action('woocommerce_before_shop_loop');

get_template_part('template-parts/shop/toolbar', null, [
    'query'                 => $wp_query,
    'active_count'          => count($activeChips),
    'has_filters_available' => $hasFiltersAvailable,
]);

if ($activeChips !== []) {
    get_template_part('template-parts/shop/active-filters', null, [
        'chips'     => $activeChips,
        'clear_url' => ShopData::clearFiltersUrl(),
    ]);
}
?>
<div class="djg-shop-layout<?php echo $hasFiltersAvailable ? ' has-sidebar' : ''; ?>">
    <?php if ($hasFiltersAvailable) : ?>
        <aside class="djg-shop-sidebar" aria-label="<?php esc_attr_e('Product Filters', 'djgear-theme'); ?>">
            <div class="djg-shop-sidebar__inner">
                <div class="djg-shop-sidebar__head">
                    <h2 class="djg-shop-sidebar__title"><?php esc_html_e('Filters', 'djgear-theme'); ?></h2>
                    <?php if ($activeChips !== []) : ?>
                        <a class="djg-shop-clear-link" href="<?php echo esc_url(ShopData::clearFiltersUrl()); ?>">
                            <?php esc_html_e('Clear all', 'djgear-theme'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php
                get_template_part('template-parts/shop/filter-form', null, [
                    'model'   => $filterModel,
                    'context' => 'sidebar',
                ]);
                ?>
            </div>
        </aside>
    <?php endif; ?>

    <div class="djg-shop-results">
        <?php if (woocommerce_product_loop()) : ?>
            <div class="djg-product-grid djg-shop-grid">
                <?php
                while (have_posts()) :
                    the_post();
                    $product = wc_get_product(get_the_ID());
                    if ($product instanceof \WC_Product && $product->is_visible()) {
                        get_template_part('template-parts/components/product-card', null, [
                            'product' => $product,
                        ]);
                    }
                endwhile;
                ?>
            </div>

            <?php
            $pagination = ShopData::paginationHtml($wp_query);
            if ($pagination !== '') :
                ?>
                <nav class="djg-shop-pagination" aria-label="<?php esc_attr_e('Products pagination', 'djgear-theme'); ?>">
                    <?php echo wp_kses_post($pagination); ?>
                </nav>
            <?php endif; ?>

            <?php
            /**
             * Hook: woocommerce_after_shop_loop.
             */
            do_action('woocommerce_after_shop_loop');
            ?>
        <?php else : ?>
            <?php
            get_template_part('template-parts/shop/empty-state', null, [
                'has_active_filters' => ($activeChips !== []),
                'clear_url'          => ShopData::clearFiltersUrl(),
                'is_category'        => $header['is_category'],
            ]);
            ?>
        <?php endif; ?>
    </div>
</div>
<?php
if ($hasFiltersAvailable) {
    get_template_part('template-parts/shop/filter-drawer', null, [
        'model'        => $filterModel,
        'active_count' => count($activeChips),
        'clear_url'    => ShopData::clearFiltersUrl(),
    ]);
}

/**
 * Hook: woocommerce_after_main_content.
 *
 * @hooked WooCommerceSetup::closeContentWrapper - 10
 */
do_action('woocommerce_after_main_content');

get_footer('shop');
