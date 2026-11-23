<?php

declare(strict_types=1);

use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

/** @var \WC_Product|null $product */
$product = $args['product'] ?? null;
/** @var list<array<string, mixed>> $items */
$items = is_array($args['items'] ?? null) ? $args['items'] : [];

if (!$product instanceof \WC_Product) {
    return;
}

$isOnSale = $product->is_on_sale();
$primary = $items[0] ?? null;
?>
<div class="djg-product-gallery woocommerce-product-gallery" data-djg-gallery>
    <div class="djg-product-gallery__stage">
        <?php if ($isOnSale) : ?>
            <span class="djg-product-card__badge djg-product-card__badge--sale">
                <?php esc_html_e('Sale', 'djgear-theme'); ?>
            </span>
        <?php endif; ?>

        <?php if (is_array($primary)) : ?>
            <figure class="djg-product-gallery__figure woocommerce-product-gallery__image">
                <img
                    class="djg-product-gallery__main wp-post-image"
                    data-djg-gallery-main
                    src="<?php echo esc_url((string) $primary['main_url']); ?>"
                    <?php if (!empty($primary['main_srcset'])) : ?>
                        srcset="<?php echo esc_attr((string) $primary['main_srcset']); ?>"
                        sizes="<?php echo esc_attr((string) $primary['main_sizes']); ?>"
                    <?php endif; ?>
                    data-full-src="<?php echo esc_url((string) $primary['full_url']); ?>"
                    alt="<?php echo esc_attr((string) $primary['alt']); ?>"
                    width="<?php echo (int) $primary['width']; ?>"
                    height="<?php echo (int) $primary['height']; ?>"
                    fetchpriority="high"
                    decoding="async"
                >
            </figure>

            <button
                type="button"
                class="djg-icon-btn djg-product-gallery__zoom"
                data-djg-lightbox-open
                aria-label="<?php esc_attr_e('Expand product image', 'djgear-theme'); ?>"
            >
                <?php Icons::render('search', 18); ?>
            </button>
        <?php else : ?>
            <div class="djg-product-gallery__empty" role="img" aria-label="<?php echo esc_attr($product->get_name()); ?>">
                <?php Icons::render('disc', 48); ?>
                <span><?php esc_html_e('Product image unavailable', 'djgear-theme'); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($items) > 1) : ?>
        <div
            class="djg-product-gallery__thumbs"
            role="listbox"
            aria-label="<?php esc_attr_e('Product image thumbnails', 'djgear-theme'); ?>"
        >
            <?php foreach ($items as $idx => $item) : ?>
                <button
                    type="button"
                    class="djg-product-gallery__thumb<?php echo $idx === 0 ? ' is-active' : ''; ?>"
                    role="option"
                    aria-selected="<?php echo $idx === 0 ? 'true' : 'false'; ?>"
                    aria-label="<?php
                    printf(
                        /* translators: %d: image number */
                        esc_attr__('Show product image %d', 'djgear-theme'),
                        $idx + 1
                    );
                    ?>"
                    data-djg-gallery-thumb
                    data-main-src="<?php echo esc_url((string) $item['main_url']); ?>"
                    data-main-srcset="<?php echo esc_attr((string) $item['main_srcset']); ?>"
                    data-full-src="<?php echo esc_url((string) $item['full_url']); ?>"
                    data-alt="<?php echo esc_attr((string) $item['alt']); ?>"
                >
                    <img
                        src="<?php echo esc_url((string) $item['thumb_url']); ?>"
                        alt=""
                        width="120"
                        height="120"
                        loading="lazy"
                        decoding="async"
                    >
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($primary)) : ?>
        <div
            class="djg-lightbox"
            id="djg-product-lightbox"
            data-djg-lightbox
            role="dialog"
            aria-modal="true"
            aria-label="<?php esc_attr_e('Expanded product image', 'djgear-theme'); ?>"
            hidden
        >
            <div class="djg-lightbox__backdrop" data-djg-lightbox-close></div>
            <div class="djg-lightbox__panel">
                <button
                    type="button"
                    class="djg-icon-btn djg-lightbox__close"
                    data-djg-lightbox-close
                    aria-label="<?php esc_attr_e('Close image viewer', 'djgear-theme'); ?>"
                >
                    <?php Icons::render('close', 20); ?>
                </button>
                <img
                    class="djg-lightbox__img"
                    data-djg-lightbox-img
                    src="<?php echo esc_url((string) $primary['full_url']); ?>"
                    alt="<?php echo esc_attr((string) $primary['alt']); ?>"
                >
            </div>
        </div>
    <?php endif; ?>
</div>
