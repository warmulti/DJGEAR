<?php

declare(strict_types=1);

use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

/** @var list<array{label: string, remove_url: string}> $chips */
$chips = $args['chips'] ?? [];
$clearUrl = (string) ($args['clear_url'] ?? '');

if ($chips === []) {
    return;
}
?>
<div class="djg-active-filters" aria-label="<?php esc_attr_e('Active filters', 'djgear-theme'); ?>">
    <ul class="djg-active-filters__list">
        <?php foreach ($chips as $chip) : ?>
            <li>
                <a
                    class="djg-filter-chip"
                    href="<?php echo esc_url($chip['remove_url']); ?>"
                    aria-label="<?php
                    printf(
                        /* translators: %s: active filter label */
                        esc_attr__('Remove filter: %s', 'djgear-theme'),
                        esc_attr($chip['label'])
                    );
                    ?>"
                >
                    <span><?php echo esc_html($chip['label']); ?></span>
                    <?php Icons::render('close', 14); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($clearUrl !== '') : ?>
        <a class="djg-shop-clear-link" href="<?php echo esc_url($clearUrl); ?>">
            <?php esc_html_e('Clear all filters', 'djgear-theme'); ?>
        </a>
    <?php endif; ?>
</div>
