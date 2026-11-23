<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$model = $args['model'] ?? [];
$activeCount = max(0, (int) ($args['active_count'] ?? 0));
?>
<div
    class="djg-drawer djg-drawer--start djg-shop-filter-drawer"
    id="djg-shop-filter-drawer"
    data-djg-drawer="shop-filters"
    role="dialog"
    aria-modal="true"
    aria-labelledby="djg-shop-filter-drawer-title"
    hidden
>
    <div class="djg-drawer__backdrop" data-djg-drawer-close></div>
    <div class="djg-drawer__panel">
        <div class="djg-drawer__header">
            <span class="djg-drawer__title" id="djg-shop-filter-drawer-title">
                <?php esc_html_e('Filter Products', 'djgear-theme'); ?>
                <?php if ($activeCount > 0) : ?>
                    <span class="djg-filter-count-badge djg-tabular"><?php echo esc_html(I18n::formatCount($activeCount)); ?></span>
                <?php endif; ?>
            </span>
            <button
                type="button"
                class="djg-icon-btn"
                data-djg-drawer-close
                aria-label="<?php esc_attr_e('Close filters', 'djgear-theme'); ?>"
            >
                <?php Icons::render('close', 20); ?>
            </button>
        </div>

        <div class="djg-drawer__body">
            <?php
            get_template_part('template-parts/shop/filter-form', null, [
                'model'   => $model,
                'context' => 'drawer',
            ]);
            ?>
        </div>
    </div>
</div>
