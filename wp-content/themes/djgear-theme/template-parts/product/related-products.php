<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var list<\WC_Product> $products */
$products = is_array($args['products'] ?? null) ? $args['products'] : [];
if ($products === []) {
    return;
}
?>
<section class="djg-related-products" aria-labelledby="djg-related-products-title">
    <div class="djg-related-products__head">
        <p class="djg-eyebrow"><?php esc_html_e('YOU MAY ALSO LIKE', 'djgear-theme'); ?></p>
        <h2 class="djg-related-products__title" id="djg-related-products-title">
            <?php esc_html_e('Related DJ equipment', 'djgear-theme'); ?>
        </h2>
    </div>

    <div class="djg-product-grid">
        <?php
        foreach ($products as $relatedProduct) {
            get_template_part('template-parts/components/product-card', null, [
                'product' => $relatedProduct,
            ]);
        }
        ?>
    </div>
</section>
