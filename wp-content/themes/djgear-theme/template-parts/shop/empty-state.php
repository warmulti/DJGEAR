<?php

declare(strict_types=1);

use DJGear\Theme\Template\HomepageData;

if (!defined('ABSPATH')) {
    exit;
}

$hasActiveFilters = !empty($args['has_active_filters']);
$clearUrl = (string) ($args['clear_url'] ?? HomepageData::shopUrl());
$isCategory = !empty($args['is_category']);
$shopUrl = HomepageData::shopUrl();
?>
<section class="djg-empty-state djg-shop-empty" aria-live="polite">
    <?php if ($hasActiveFilters) : ?>
        <h2><?php esc_html_e('No products match your selected filters', 'djgear-theme'); ?></h2>
        <p class="djg-page-lead">
            <?php esc_html_e('Try removing one or more filters or adjusting your price range to see available DJ equipment.', 'djgear-theme'); ?>
        </p>
        <div class="djg-actions-row">
            <a class="djg-btn djg-btn--primary" href="<?php echo esc_url($clearUrl); ?>">
                <?php esc_html_e('Clear all filters', 'djgear-theme'); ?>
            </a>
            <?php if ($isCategory) : ?>
                <a class="djg-btn djg-btn--secondary" href="<?php echo esc_url($shopUrl); ?>">
                    <?php esc_html_e('Browse all equipment', 'djgear-theme'); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <h2><?php esc_html_e('No products available yet', 'djgear-theme'); ?></h2>
        <p class="djg-page-lead">
            <?php esc_html_e('Products published in the WooCommerce catalog will appear here automatically.', 'djgear-theme'); ?>
        </p>
        <div class="djg-actions-row">
            <a class="djg-btn djg-btn--secondary" href="<?php echo esc_url(home_url('/')); ?>">
                <?php esc_html_e('Back to Home', 'djgear-theme'); ?>
            </a>
        </div>
    <?php endif; ?>
</section>
