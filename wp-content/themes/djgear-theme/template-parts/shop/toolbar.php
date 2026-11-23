<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Template\Icons;
use DJGear\Theme\Template\ShopData;
use DJGear\Theme\WooCommerce\ShopQuery;

if (!defined('ABSPATH')) {
    exit;
}

/** @var \WP_Query|null $query */
$query = $args['query'] ?? null;
if (!$query instanceof \WP_Query) {
    return;
}

$activeCount = max(0, (int) ($args['active_count'] ?? 0));
$hasFiltersAvailable = (bool) ($args['has_filters_available'] ?? false);
$sortOptions = ShopQuery::supportedSortOptions();
$params = ShopQuery::currentParams();
$preservedFilterArgs = ShopData::queryArgsFromParams($params, false);
$formAction = ShopData::baseArchiveUrl();
?>
<div class="djg-shop-toolbar">
    <div class="djg-shop-toolbar__start">
        <?php if ($hasFiltersAvailable) : ?>
            <button
                type="button"
                class="djg-btn djg-btn--secondary djg-shop-filter-toggle"
                data-djg-drawer-open="shop-filters"
                aria-controls="djg-shop-filter-drawer"
                aria-expanded="false"
            >
                <?php Icons::render('sliders', 18); ?>
                <span><?php esc_html_e('Filters', 'djgear-theme'); ?></span>
                <?php if ($activeCount > 0) : ?>
                    <span class="djg-filter-count-badge djg-tabular" aria-label="<?php esc_attr_e('Active filters', 'djgear-theme'); ?>">
                        <?php echo esc_html(I18n::formatCount($activeCount)); ?>
                    </span>
                <?php endif; ?>
            </button>
        <?php endif; ?>

        <p class="djg-shop-result-count" role="status" aria-live="polite">
            <?php echo esc_html(ShopData::resultCountLabel($query)); ?>
        </p>
    </div>

    <form class="djg-shop-sort-form" method="get" action="<?php echo esc_url($formAction); ?>">
        <?php
        if (is_search()) :
            ?>
            <input type="hidden" name="s" value="<?php echo esc_attr(get_search_query(false)); ?>">
            <input type="hidden" name="post_type" value="product">
            <?php
        endif;

        foreach ($preservedFilterArgs as $key => $value) :
            if (is_array($value)) :
                foreach ($value as $item) :
                    ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>[]" value="<?php echo esc_attr($item); ?>">
                    <?php
                endforeach;
            else :
                ?>
                <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php
            endif;
        endforeach;
        ?>
        <label class="djg-shop-sort-label" for="djg-shop-orderby">
            <?php esc_html_e('Sort by', 'djgear-theme'); ?>
        </label>
        <select
            id="djg-shop-orderby"
            class="djg-input djg-shop-sort-select"
            name="<?php echo esc_attr(ShopQuery::PARAM_ORDERBY); ?>"
            data-djg-sort-select
            aria-label="<?php esc_attr_e('Sort products', 'djgear-theme'); ?>"
        >
            <?php foreach ($sortOptions as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($params['orderby'], $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript>
            <button type="submit" class="djg-btn djg-btn--secondary">
                <?php esc_html_e('Apply', 'djgear-theme'); ?>
            </button>
        </noscript>
    </form>
</div>
