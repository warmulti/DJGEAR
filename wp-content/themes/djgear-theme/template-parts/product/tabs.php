<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, array{title: string, priority: int, callback: callable}> $tabs */
$tabs = is_array($args['tabs'] ?? null) ? $args['tabs'] : [];
if ($tabs === []) {
    return;
}

$tabKeys = array_keys($tabs);
$firstKey = $tabKeys[0];
?>
<section class="djg-product-tabs" data-djg-tabs aria-label="<?php esc_attr_e('Product details and reviews', 'djgear-theme'); ?>">
    <div class="djg-product-tabs__nav" role="tablist">
        <?php foreach ($tabs as $key => $tab) : ?>
            <?php
            $safeKey = sanitize_key($key);
            $isFirst = ($key === $firstKey);
            ?>
            <button
                type="button"
                class="djg-product-tabs__tab<?php echo $isFirst ? ' is-active' : ''; ?>"
                id="tab-title-<?php echo esc_attr($safeKey); ?>"
                role="tab"
                aria-selected="<?php echo $isFirst ? 'true' : 'false'; ?>"
                aria-controls="tab-<?php echo esc_attr($safeKey); ?>"
                tabindex="<?php echo $isFirst ? '0' : '-1'; ?>"
                data-djg-tab-trigger="<?php echo esc_attr($safeKey); ?>"
            >
                <?php echo esc_html((string) apply_filters('woocommerce_product_' . $safeKey . '_tab_title', $tab['title'], $key)); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="djg-product-tabs__panels">
        <?php foreach ($tabs as $key => $tab) : ?>
            <?php
            $safeKey = sanitize_key($key);
            $isFirst = ($key === $firstKey);
            ?>
            <div
                class="djg-product-tabs__panel djg-entry-content"
                id="tab-<?php echo esc_attr($safeKey); ?>"
                role="tabpanel"
                aria-labelledby="tab-title-<?php echo esc_attr($safeKey); ?>"
                data-djg-tab-panel="<?php echo esc_attr($safeKey); ?>"
                <?php echo $isFirst ? '' : 'hidden'; ?>
            >
                <?php call_user_func($tab['callback'], $key, $tab); ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
