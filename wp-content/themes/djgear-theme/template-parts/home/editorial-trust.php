<?php

declare(strict_types=1);

use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$pillars = [
    [
        'icon'  => 'sliders',
        'title' => __('Structured DJ Equipment Catalog', 'djgear-theme'),
        'body'  => __('Equipment is organized by manufacturer, category, and technical specifications so you can identify compatible hardware accurately.', 'djgear-theme'),
    ],
    [
        'icon'  => 'layers',
        'title' => __('Commercial Custom Skin Workflow', 'djgear-theme'),
        'body'  => __('Custom Design orders include automated print-resolution validation and explicit customer proof approval before entering production.', 'djgear-theme'),
    ],
    [
        'icon'  => 'shield',
        'title' => __('Native WooCommerce Checkout & Tracking', 'djgear-theme'),
        'body'  => __('Hardware purchases and custom skin orders share a single WooCommerce cart, checkout, and My Account order history.', 'djgear-theme'),
    ],
    [
        'icon'  => 'globe',
        'title' => __('Built for Persian RTL & English LTR', 'djgear-theme'),
        'body'  => __('Every storefront flow is crafted natively for both Persian and English readers with clear typography and responsive mobile controls.', 'djgear-theme'),
    ],
];
?>
<section class="djg-home-section djg-home-editorial" aria-labelledby="djg-home-editorial-title">
    <div class="djg-container">
        <div class="djg-section-head">
            <div>
                <p class="djg-eyebrow"><?php esc_html_e('THE DJGEAR PLATFORM', 'djgear-theme'); ?></p>
                <h2 class="djg-section-title" id="djg-home-editorial-title">
                    <?php esc_html_e('Purpose-built for working DJs and studios', 'djgear-theme'); ?>
                </h2>
            </div>
        </div>

        <div class="djg-pillar-grid">
            <?php foreach ($pillars as $pillar) : ?>
                <article class="djg-pillar-card">
                    <span class="djg-pillar-card__icon" aria-hidden="true">
                        <?php Icons::render($pillar['icon'], 20); ?>
                    </span>
                    <h3 class="djg-pillar-card__title"><?php echo esc_html($pillar['title']); ?></h3>
                    <p class="djg-pillar-card__body"><?php echo esc_html($pillar['body']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
