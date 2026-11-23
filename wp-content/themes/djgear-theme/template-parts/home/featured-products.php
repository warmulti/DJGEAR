<?php

declare(strict_types=1);

use DJGear\Theme\Template\HomepageData;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$products = HomepageData::featuredProducts(8);
$shopUrl = HomepageData::shopUrl();
?>
<section class="djg-home-section djg-home-products" aria-labelledby="djg-home-products-title">
    <div class="djg-container">
        <div class="djg-section-head">
            <div>
                <p class="djg-eyebrow"><?php esc_html_e('FEATURED SELECTION', 'djgear-theme'); ?></p>
                <h2 class="djg-section-title" id="djg-home-products-title">
                    <?php esc_html_e('Featured DJ equipment', 'djgear-theme'); ?>
                </h2>
            </div>
            <a class="djg-section-link" href="<?php echo esc_url($shopUrl); ?>">
                <span><?php esc_html_e('Explore full catalog', 'djgear-theme'); ?></span>
                <?php Icons::render('chevron-right', 16, 'djg-icon-flip'); ?>
            </a>
        </div>

        <?php if ($products !== []) : ?>
            <div class="djg-product-grid">
                <?php
                foreach ($products as $product) {
                    get_template_part('template-parts/components/product-card', null, ['product' => $product]);
                }
                ?>
            </div>
        <?php else : ?>
            <div class="djg-home-empty">
                <p><?php esc_html_e('Published products from the WooCommerce catalog will appear here automatically once added to the store.', 'djgear-theme'); ?></p>
                <a class="djg-btn djg-btn--secondary" href="<?php echo esc_url($shopUrl); ?>">
                    <?php esc_html_e('Go to Shop', 'djgear-theme'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>
