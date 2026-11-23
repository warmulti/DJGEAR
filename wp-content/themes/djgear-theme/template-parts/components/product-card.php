<?php

declare(strict_types=1);

use DJGear\Theme\Template\HomepageData;

if (!defined('ABSPATH')) {
    exit;
}

/** @var \WC_Product|null $product */
$product = $args['product'] ?? null;
if (!$product instanceof \WC_Product) {
    return;
}

$permalink = $product->get_permalink();
$name = $product->get_name();
$priceHtml = $product->get_price_html();
$isOnSale = $product->is_on_sale();
$stock = HomepageData::productStockInfo($product);
$action = HomepageData::productActionData($product);

$imageId = (int) $product->get_image_id();
$imageAlt = $imageId > 0 ? trim((string) get_post_meta($imageId, '_wp_attachment_image_alt', true)) : '';
if ($imageAlt === '') {
    $imageAlt = $name;
}
?>
<article class="djg-product-card">
    <a class="djg-product-card__media" href="<?php echo esc_url($permalink); ?>">
        <?php if ($isOnSale) : ?>
            <span class="djg-product-card__badge djg-product-card__badge--sale">
                <?php esc_html_e('Sale', 'djgear-theme'); ?>
            </span>
        <?php endif; ?>
        <?php
        if ($imageId > 0) {
            echo wp_get_attachment_image($imageId, 'woocommerce_thumbnail', false, [
                'class'    => 'djg-product-card__img',
                'alt'      => $imageAlt,
                'loading'  => 'lazy',
                'decoding' => 'async',
            ]);
        } else {
            echo '<span class="djg-product-card__placeholder" aria-hidden="true">' . esc_html__('DJGear', 'djgear-theme') . '</span>';
        }
        ?>
    </a>

    <div class="djg-product-card__body">
        <div class="djg-product-card__meta">
            <span class="djg-stock-pill djg-stock-pill--<?php echo esc_attr($stock['tone']); ?>">
                <?php echo esc_html($stock['label']); ?>
            </span>
        </div>

        <h3 class="djg-product-card__title">
            <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($name); ?></a>
        </h3>

        <div class="djg-product-card__footer">
            <?php if ($priceHtml !== '') : ?>
                <div class="djg-product-card__price djg-tabular">
                    <?php echo wp_kses_post($priceHtml); ?>
                </div>
            <?php endif; ?>

            <a
                href="<?php echo esc_url($action['url']); ?>"
                class="<?php echo esc_attr($action['classes']); ?>"
                data-quantity="1"
                data-product_id="<?php echo (int) $action['product_id']; ?>"
                data-product_sku="<?php echo esc_attr($action['sku']); ?>"
                aria-label="<?php echo esc_attr($action['aria_label']); ?>"
                rel="nofollow"
            >
                <?php echo esc_html($action['text']); ?>
            </a>
        </div>
    </div>
</article>
