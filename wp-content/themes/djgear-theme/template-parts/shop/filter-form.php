<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Template\ShopData;
use DJGear\Theme\WooCommerce\ShopQuery;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $model */
$model = $args['model'] ?? [];
$context = sanitize_html_class((string) ($args['context'] ?? 'sidebar'));
$params = is_array($model['params'] ?? null) ? $model['params'] : ShopQuery::currentParams();

$categories = is_array($model['categories'] ?? null) ? $model['categories'] : [];
$brands = is_array($model['brands'] ?? null) ? $model['brands'] : [];
$attributes = is_array($model['attributes'] ?? null) ? $model['attributes'] : [];
$priceBounds = is_array($model['price_bounds'] ?? null) ? $model['price_bounds'] : null;
$hasSaleProducts = !empty($model['has_sale_products']);
$hasAnyProducts = !empty($model['has_any_products']);

$defaultSort = (string) get_option('woocommerce_default_catalog_orderby', 'menu_order');
$formAction = ShopData::baseArchiveUrl();
$clearUrl = ShopData::clearFiltersUrl();
?>
<form
    class="djg-filter-form"
    method="get"
    action="<?php echo esc_url($formAction); ?>"
    data-djg-filter-form="<?php echo esc_attr($context); ?>"
>
    <?php if (is_search()) : ?>
        <input type="hidden" name="s" value="<?php echo esc_attr(get_search_query(false)); ?>">
        <input type="hidden" name="post_type" value="product">
    <?php endif; ?>

    <?php if (!empty($params['orderby']) && $params['orderby'] !== $defaultSort) : ?>
        <input type="hidden" name="<?php echo esc_attr(ShopQuery::PARAM_ORDERBY); ?>" value="<?php echo esc_attr((string) $params['orderby']); ?>">
    <?php endif; ?>

    <?php if ($categories !== []) : ?>
        <fieldset class="djg-filter-group">
            <legend class="djg-filter-group__legend">
                <?php echo esc_html((function_exists('is_product_category') && is_product_category()) ? __('Subcategories', 'djgear-theme') : __('Categories', 'djgear-theme')); ?>
            </legend>
            <ul class="djg-filter-list">
                <?php foreach ($categories as $cat) : ?>
                    <?php $inputId = $context . '-cat-' . sanitize_html_class($cat['slug']); ?>
                    <li>
                        <label class="djg-filter-check" for="<?php echo esc_attr($inputId); ?>">
                            <input
                                id="<?php echo esc_attr($inputId); ?>"
                                type="checkbox"
                                name="<?php echo esc_attr(ShopQuery::PARAM_CAT); ?>[]"
                                value="<?php echo esc_attr($cat['slug']); ?>"
                                <?php checked(!empty($cat['checked'])); ?>
                            >
                            <span class="djg-filter-check__label"><?php echo esc_html($cat['name']); ?></span>
                            <span class="djg-filter-check__count djg-tabular"><?php echo esc_html(I18n::formatCount((int) $cat['count'])); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>
    <?php endif; ?>

    <?php if ($brands !== []) : ?>
        <fieldset class="djg-filter-group">
            <legend class="djg-filter-group__legend"><?php esc_html_e('Brands', 'djgear-theme'); ?></legend>
            <ul class="djg-filter-list">
                <?php foreach ($brands as $brand) : ?>
                    <?php $inputId = $context . '-brand-' . sanitize_html_class($brand['slug']); ?>
                    <li>
                        <label class="djg-filter-check" for="<?php echo esc_attr($inputId); ?>">
                            <input
                                id="<?php echo esc_attr($inputId); ?>"
                                type="checkbox"
                                name="<?php echo esc_attr(ShopQuery::PARAM_BRAND); ?>[]"
                                value="<?php echo esc_attr($brand['slug']); ?>"
                                <?php checked(!empty($brand['checked'])); ?>
                            >
                            <span class="djg-filter-check__label"><?php echo esc_html($brand['name']); ?></span>
                            <span class="djg-filter-check__count djg-tabular"><?php echo esc_html(I18n::formatCount((int) $brand['count'])); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>
    <?php endif; ?>

    <?php if ($priceBounds !== null) : ?>
        <fieldset class="djg-filter-group">
            <legend class="djg-filter-group__legend"><?php esc_html_e('Price range', 'djgear-theme'); ?></legend>
            <div class="djg-price-inputs">
                <div class="djg-price-field">
                    <label class="djg-price-field__label" for="<?php echo esc_attr($context . '-min-price'); ?>">
                        <?php esc_html_e('Min', 'djgear-theme'); ?>
                    </label>
                    <input
                        id="<?php echo esc_attr($context . '-min-price'); ?>"
                        class="djg-input djg-tabular"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="any"
                        name="<?php echo esc_attr(ShopQuery::PARAM_MIN); ?>"
                        value="<?php echo $params['min_price'] !== null ? esc_attr((string) $params['min_price']) : ''; ?>"
                        placeholder="<?php echo esc_attr((string) $priceBounds['min']); ?>"
                    >
                </div>
                <div class="djg-price-field">
                    <label class="djg-price-field__label" for="<?php echo esc_attr($context . '-max-price'); ?>">
                        <?php esc_html_e('Max', 'djgear-theme'); ?>
                    </label>
                    <input
                        id="<?php echo esc_attr($context . '-max-price'); ?>"
                        class="djg-input djg-tabular"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="any"
                        name="<?php echo esc_attr(ShopQuery::PARAM_MAX); ?>"
                        value="<?php echo $params['max_price'] !== null ? esc_attr((string) $params['max_price']) : ''; ?>"
                        placeholder="<?php echo esc_attr((string) $priceBounds['max']); ?>"
                    >
                </div>
            </div>
        </fieldset>
    <?php endif; ?>

    <?php if ($hasAnyProducts) : ?>
        <fieldset class="djg-filter-group">
            <legend class="djg-filter-group__legend"><?php esc_html_e('Availability', 'djgear-theme'); ?></legend>
            <ul class="djg-filter-list">
                <li>
                    <label class="djg-filter-check" for="<?php echo esc_attr($context . '-instock'); ?>">
                        <input
                            id="<?php echo esc_attr($context . '-instock'); ?>"
                            type="checkbox"
                            name="<?php echo esc_attr(ShopQuery::PARAM_STOCK); ?>"
                            value="instock"
                            <?php checked(!empty($params['in_stock'])); ?>
                        >
                        <span class="djg-filter-check__label"><?php esc_html_e('In stock only', 'djgear-theme'); ?></span>
                    </label>
                </li>
                <?php if ($hasSaleProducts) : ?>
                    <li>
                        <label class="djg-filter-check" for="<?php echo esc_attr($context . '-onsale'); ?>">
                            <input
                                id="<?php echo esc_attr($context . '-onsale'); ?>"
                                type="checkbox"
                                name="<?php echo esc_attr(ShopQuery::PARAM_ON_SALE); ?>"
                                value="1"
                                <?php checked(!empty($params['on_sale'])); ?>
                            >
                            <span class="djg-filter-check__label"><?php esc_html_e('On sale', 'djgear-theme'); ?></span>
                        </label>
                    </li>
                <?php endif; ?>
            </ul>
        </fieldset>
    <?php endif; ?>

    <?php foreach ($attributes as $attr) : ?>
        <fieldset class="djg-filter-group">
            <legend class="djg-filter-group__legend"><?php echo esc_html((string) $attr['label']); ?></legend>
            <ul class="djg-filter-list">
                <?php foreach ($attr['terms'] as $term) : ?>
                    <?php $inputId = $context . '-attr-' . sanitize_html_class($attr['slug'] . '-' . $term['slug']); ?>
                    <li>
                        <label class="djg-filter-check" for="<?php echo esc_attr($inputId); ?>">
                            <input
                                id="<?php echo esc_attr($inputId); ?>"
                                type="checkbox"
                                name="<?php echo esc_attr((string) $attr['param']); ?>[]"
                                value="<?php echo esc_attr((string) $term['slug']); ?>"
                                <?php checked(!empty($term['checked'])); ?>
                            >
                            <span class="djg-filter-check__label"><?php echo esc_html((string) $term['name']); ?></span>
                            <span class="djg-filter-check__count djg-tabular"><?php echo esc_html(I18n::formatCount((int) $term['count'])); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>
    <?php endforeach; ?>

    <div class="djg-filter-form__actions">
        <button type="submit" class="djg-btn djg-btn--primary djg-btn--block">
            <?php esc_html_e('Apply filters', 'djgear-theme'); ?>
        </button>
        <a class="djg-btn djg-btn--secondary djg-btn--block" href="<?php echo esc_url($clearUrl); ?>">
            <?php esc_html_e('Clear filters', 'djgear-theme'); ?>
        </a>
    </div>
</form>
