<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array{title: string, description: string, thumbnail_url: string, thumbnail_alt: string, eyebrow: string, is_category: bool} $header */
$header = $args['header'] ?? [];
$total = max(0, (int) ($args['total'] ?? 0));
$hasThumb = !empty($header['thumbnail_url']);
?>
<header class="djg-shop-header<?php echo $hasThumb ? ' has-media' : ''; ?>">
    <div class="djg-shop-header__copy">
        <p class="djg-eyebrow"><?php echo esc_html((string) ($header['eyebrow'] ?? '')); ?></p>
        <div class="djg-shop-header__title-row">
            <h1 class="djg-shop-header__title"><?php echo esc_html((string) ($header['title'] ?? '')); ?></h1>
            <span class="djg-shop-header__count djg-tabular">
                <?php
                printf(
                    /* translators: %s: localized product count */
                    esc_html(_n('%s product', '%s products', $total, 'djgear-theme')),
                    esc_html(I18n::formatCount($total))
                );
                ?>
            </span>
        </div>
        <?php if (!empty($header['description'])) : ?>
            <p class="djg-shop-header__desc"><?php echo esc_html((string) $header['description']); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($hasThumb) : ?>
        <div class="djg-shop-header__media">
            <img
                src="<?php echo esc_url((string) $header['thumbnail_url']); ?>"
                alt="<?php echo esc_attr((string) ($header['thumbnail_alt'] ?? $header['title'] ?? '')); ?>"
                width="320"
                height="240"
                loading="eager"
                decoding="async"
            >
        </div>
    <?php endif; ?>
</header>
