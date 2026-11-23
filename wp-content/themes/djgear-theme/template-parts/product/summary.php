<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Template\HomepageData;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

/** @var \WC_Product|null $product */
$product = $args['product'] ?? null;
if (!$product instanceof \WC_Product) {
    return;
}

$rating = is_array($args['rating'] ?? null) ? $args['rating'] : null;
$meta = is_array($args['meta'] ?? null) ? $args['meta'] : [];
$compatibleDevices = is_array($args['compatible_devices'] ?? null) ? $args['compatible_devices'] : [];
$skinsCallout = is_array($args['skins_callout'] ?? null) ? $args['skins_callout'] : null;

$stock = HomepageData::productStockInfo($product);
$priceHtml = $product->get_price_html();
$shortDescription = apply_filters('woocommerce_short_description', $product->get_short_description());

$brands = is_array($meta['brands'] ?? null) ? $meta['brands'] : [];
$categories = is_array($meta['categories'] ?? null) ? $meta['categories'] : [];
$tags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
$sku = (string) ($meta['sku'] ?? '');
?>
<div class="djg-product-summary summary entry-summary">
    <?php if ($brands !== [] || $categories !== []) : ?>
        <div class="djg-product-summary__eyebrow">
            <?php if ($brands !== []) : ?>
                <a class="djg-product-summary__brand" href="<?php echo esc_url($brands[0]['url']); ?>">
                    <?php echo esc_html($brands[0]['name']); ?>
                </a>
            <?php endif; ?>
            <?php if ($brands !== [] && $categories !== []) : ?>
                <span class="djg-breadcrumbs__sep" aria-hidden="true">/</span>
            <?php endif; ?>
            <?php if ($categories !== []) : ?>
                <a class="djg-product-summary__cat" href="<?php echo esc_url($categories[0]['url']); ?>">
                    <?php echo esc_html($categories[0]['name']); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h1 class="djg-product-summary__title product_title entry-title">
        <?php echo esc_html($product->get_name()); ?>
    </h1>

    <?php if ($rating !== null) : ?>
        <div class="djg-product-rating">
            <div
                class="djg-stars"
                role="img"
                aria-label="<?php
                printf(
                    /* translators: 1: average rating out of 5 */
                    esc_attr__('Rated %s out of 5', 'djgear-theme'),
                    esc_attr((string) $rating['average'])
                );
                ?>"
            >
                <span class="djg-stars__track" aria-hidden="true">★★★★★</span>
                <span class="djg-stars__fill" aria-hidden="true" style="width: <?php echo esc_attr((string) $rating['percent']); ?>%">★★★★★</span>
            </div>
            <span class="djg-product-rating__score djg-tabular">
                <?php echo esc_html(number_format((float) $rating['average'], 1)); ?>
            </span>
            <a class="djg-product-rating__link" href="#tab-reviews" data-djg-open-tab="reviews">
                <?php
                printf(
                    /* translators: %s: localized review count */
                    esc_html(_n('(%s review)', '(%s reviews)', (int) $rating['review_count'], 'djgear-theme')),
                    esc_html(I18n::formatCount((int) $rating['review_count']))
                );
                ?>
            </a>
        </div>
    <?php endif; ?>

    <div class="djg-product-summary__pricing">
        <?php if ($priceHtml !== '') : ?>
            <div class="djg-product-summary__price price djg-tabular">
                <?php echo wp_kses_post($priceHtml); ?>
            </div>
        <?php endif; ?>
        <span class="djg-stock-pill djg-stock-pill--<?php echo esc_attr($stock['tone']); ?>">
            <?php echo esc_html($stock['label']); ?>
        </span>
    </div>

    <?php if (is_string($shortDescription) && trim($shortDescription) !== '') : ?>
        <div class="djg-product-summary__excerpt woocommerce-product-details__short-description">
            <?php echo wp_kses_post($shortDescription); ?>
        </div>
    <?php endif; ?>

    <div class="djg-product-summary__purchase">
        <?php
        // Native WooCommerce purchase form dispatcher:
        // handles Simple, Variable, Grouped, External, and Out-of-Stock products natively.
        if (function_exists('woocommerce_template_single_add_to_cart')) {
            woocommerce_template_single_add_to_cart();
        }
        ?>
    </div>

    <?php if ($compatibleDevices !== []) : ?>
        <div class="djg-product-compat" aria-label="<?php esc_attr_e('Compatible DJ equipment', 'djgear-theme'); ?>">
            <p class="djg-product-compat__label"><?php esc_html_e('Compatible DJ Equipment', 'djgear-theme'); ?></p>
            <ul class="djg-product-compat__list">
                <?php foreach ($compatibleDevices as $device) : ?>
                    <li class="djg-product-compat__item">
                        <span><?php echo esc_html($device['name']); ?></span>
                        <?php if ($device['model_code'] !== '') : ?>
                            <span class="djg-ltr-isolate djg-product-compat__code"><?php echo esc_html($device['model_code']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($skinsCallout !== null) : ?>
        <div class="djg-product-skins-callout<?php echo !empty($skinsCallout['is_skin_ready_match']) ? ' is-highlighted' : ''; ?>">
            <div class="djg-product-skins-callout__icon" aria-hidden="true">
                <?php Icons::render('layers', 20); ?>
            </div>
            <div class="djg-product-skins-callout__body">
                <p class="djg-product-skins-callout__title"><?php echo esc_html((string) $skinsCallout['title']); ?></p>
                <p class="djg-product-skins-callout__desc"><?php echo esc_html((string) $skinsCallout['description']); ?></p>
                <a class="djg-section-link djg-product-skins-callout__link" href="<?php echo esc_url((string) $skinsCallout['url']); ?>">
                    <span><?php echo esc_html((string) $skinsCallout['cta_label']); ?></span>
                    <?php Icons::render('chevron-right', 16, 'djg-icon-flip'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="djg-product-meta product_meta">
        <?php if (wc_product_sku_enabled() && ($sku !== '' || $product->is_type('variable'))) : ?>
            <div class="djg-product-meta__row sku_wrapper">
                <span class="djg-product-meta__label"><?php esc_html_e('SKU', 'djgear-theme'); ?></span>
                <span class="djg-product-meta__value djg-ltr-isolate sku">
                    <?php echo esc_html($sku !== '' ? $sku : __('N/A', 'djgear-theme')); ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($categories !== []) : ?>
            <div class="djg-product-meta__row posted_in">
                <span class="djg-product-meta__label"><?php esc_html_e('Categories', 'djgear-theme'); ?></span>
                <span class="djg-product-meta__value">
                    <?php
                    $catLinks = array_map(
                        static fn (array $c): string => '<a href="' . esc_url($c['url']) . '">' . esc_html($c['name']) . '</a>',
                        $categories
                    );
                    echo wp_kses_post(implode(', ', $catLinks));
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($brands !== []) : ?>
            <div class="djg-product-meta__row">
                <span class="djg-product-meta__label"><?php esc_html_e('Brand', 'djgear-theme'); ?></span>
                <span class="djg-product-meta__value">
                    <?php
                    $brandLinks = array_map(
                        static fn (array $b): string => '<a href="' . esc_url($b['url']) . '">' . esc_html($b['name']) . '</a>',
                        $brands
                    );
                    echo wp_kses_post(implode(', ', $brandLinks));
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($tags !== []) : ?>
            <div class="djg-product-meta__row tagged_as">
                <span class="djg-product-meta__label"><?php esc_html_e('Tags', 'djgear-theme'); ?></span>
                <span class="djg-product-meta__value">
                    <?php
                    $tagLinks = array_map(
                        static fn (array $t): string => '<a href="' . esc_url($t['url']) . '">' . esc_html($t['name']) . '</a>',
                        $tags
                    );
                    echo wp_kses_post(implode(', ', $tagLinks));
                    ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <?php
    /**
     * Hook: woocommerce_single_product_summary.
     *
     * Default visual outputs (priorities 5..50) are unhooked in `WooCommerceSetup` so this
     * preserves `WC_Structured_Data::generate_product_data` (priority 60) and any third-party
     * extension hooks with ZERO duplicate JSON-LD schema.
     */
    do_action('woocommerce_single_product_summary');
    ?>
</div>
