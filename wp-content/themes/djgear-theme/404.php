<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();
$shopUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
?>
<div class="djg-container djg-page-shell">
    <section class="djg-empty-state djg-404">
        <p class="djg-eyebrow djg-ltr-isolate">404</p>
        <h1 class="djg-page-title"><?php esc_html_e('Page not found', 'djgear-theme'); ?></h1>
        <p class="djg-page-lead">
            <?php esc_html_e('The page or gear you are looking for may have moved or no longer exists.', 'djgear-theme'); ?>
        </p>
        <div class="djg-actions-row">
            <a class="djg-btn djg-btn--primary" href="<?php echo esc_url(home_url('/')); ?>">
                <?php esc_html_e('Back to Home', 'djgear-theme'); ?>
            </a>
            <?php if (is_string($shopUrl) && $shopUrl !== '') : ?>
                <a class="djg-btn djg-btn--secondary" href="<?php echo esc_url($shopUrl); ?>">
                    <?php esc_html_e('Browse Shop', 'djgear-theme'); ?>
                </a>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php
get_footer();
