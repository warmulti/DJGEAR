<?php

declare(strict_types=1);

namespace DJGear\Theme\Template;

/**
 * Read-only view-model data provider for the DJGear homepage (`front-page.php`).
 *
 * Strictly delegates all commerce, catalog, and skin queries to native WordPress,
 * WooCommerce, and existing DJGear plugin integration points. Zero business logic
 * or hardcoded IDs/products/brands.
 */
final class HomepageData
{
    public static function shopUrl(): string
    {
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('shop');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return home_url('/');
    }

    /**
     * Resolve the real existing customer-facing Custom Skins portal or configurator URL.
     *
     * Priority:
     * 1. Published WordPress page hosting `[djgear_skin_configurator]` shortcode.
     * 2. Existing WooCommerce My Account `/my-account/custom-skins/` endpoint registered by `djgear-custom-skins`.
     * 3. Empty string if `djgear-custom-skins` is inactive and no configurator page exists.
     */
    public static function customSkinsUrl(): string
    {
        $cacheKey = 'djgear_theme_custom_skins_page_url';
        $cached = wp_cache_get($cacheKey, 'djgear_theme');
        if (is_string($cached)) {
            return $cached;
        }

        $resolved = '';

        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            's'              => 'djgear_skin_configurator',
            'posts_per_page' => 5,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        foreach ($pages as $pageId) {
            $content = (string) get_post_field('post_content', (int) $pageId);
            if (has_shortcode($content, 'djgear_skin_configurator')) {
                $permalink = get_permalink((int) $pageId);
                if (is_string($permalink) && $permalink !== '') {
                    $resolved = $permalink;
                    break;
                }
            }
        }

        if (
            $resolved === ''
            && class_exists('DJGear\\CustomSkins\\Frontend\\CustomerPortal')
            && function_exists('wc_get_account_endpoint_url')
        ) {
            $endpointUrl = wc_get_account_endpoint_url('custom-skins');
            if (is_string($endpointUrl) && $endpointUrl !== '') {
                $resolved = $endpointUrl;
            }
        }

        wp_cache_set($cacheKey, $resolved, 'djgear_theme', 300);

        return $resolved;
    }

    /**
     * Resolve studio hero imagery: honors static front-page featured image when explicitly set,
     * otherwise uses the theme's self-hosted studio DJ console photograph.
     *
     * @return array{url: string, width: int, height: int, alt: string}
     */
    public static function heroImage(): array
    {
        $frontPageId = (int) get_option('page_on_front', 0);
        if ($frontPageId > 0 && has_post_thumbnail($frontPageId)) {
            $thumbId = (int) get_post_thumbnail_id($frontPageId);
            $img = wp_get_attachment_image_src($thumbId, 'djg-hero');
            if (is_array($img) && !empty($img[0])) {
                $alt = trim((string) get_post_meta($thumbId, '_wp_attachment_image_alt', true));
                return [
                    'url'    => (string) $img[0],
                    'width'  => max(1, (int) ($img[1] ?? 1600)),
                    'height' => max(1, (int) ($img[2] ?? 1000)),
                    'alt'    => $alt !== '' ? $alt : __('Professional DJ equipment setup at DJGear', 'djgear-theme'),
                ];
            }
        }

        $webpRel = '/assets/images/hero-studio-console.webp';
        $pngRel = '/assets/images/hero-studio-console.png';
        $useWebp = is_readable(DJGEAR_THEME_DIR . $webpRel);

        return [
            'url'    => DJGEAR_THEME_URI . ($useWebp ? $webpRel : $pngRel),
            'width'  => 1440,
            'height' => 804,
            'alt'    => __('Matte-black professional DJ controller and turntable console in a dark studio environment', 'djgear-theme'),
        ];
    }

    /**
     * Fetch real, non-empty WooCommerce product categories.
     *
     * @return list<array{id: int, name: string, slug: string, description: string, count: int, url: string, image_url: string, image_alt: string}>
     */
    public static function featuredCategories(int $limit = 6): array
    {
        if (!class_exists('WooCommerce') || !taxonomy_exists('product_cat')) {
            return [];
        }

        $defaultCatId = (int) get_option('default_product_cat', 0);
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'parent'     => 0,
            'number'     => $limit,
            'orderby'    => 'menu_order',
            'order'      => 'ASC',
        ];
        if ($defaultCatId > 0) {
            $args['exclude'] = [$defaultCatId];
        }

        $terms = get_terms($args);
        if (is_wp_error($terms) || !is_array($terms) || $terms === []) {
            // Fall back to any non-empty category (including subcategories) if top-level has none
            unset($args['parent']);
            $terms = get_terms($args);
        }

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $link = get_term_link($term);
            if (is_wp_error($link) || !is_string($link)) {
                continue;
            }

            $thumbId = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
            $imageUrl = '';
            $imageAlt = $term->name;
            if ($thumbId > 0) {
                $src = wp_get_attachment_image_url($thumbId, 'djg-card-4x3');
                if (is_string($src)) {
                    $imageUrl = $src;
                }
                $altMeta = trim((string) get_post_meta($thumbId, '_wp_attachment_image_alt', true));
                if ($altMeta !== '') {
                    $imageAlt = $altMeta;
                }
            }

            $items[] = [
                'id'          => (int) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => wp_strip_all_tags($term->description),
                'count'       => (int) $term->count,
                'url'         => $link,
                'image_url'   => $imageUrl,
                'image_alt'   => $imageAlt,
            ];
        }

        return $items;
    }

    /**
     * Fetch visible published WooCommerce products (featured first, backfilled with latest visible products).
     *
     * @return list<\WC_Product>
     */
    public static function featuredProducts(int $limit = 8): array
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
            return [];
        }

        $products = [];
        $seenIds = [];

        $featured = wc_get_products([
            'status'     => 'publish',
            'visibility' => 'catalog',
            'featured'   => true,
            'limit'      => $limit,
            'orderby'    => 'menu_order',
            'order'      => 'ASC',
        ]);

        if (is_array($featured)) {
            foreach ($featured as $product) {
                if ($product instanceof \WC_Product && $product->is_visible()) {
                    $products[] = $product;
                    $seenIds[] = $product->get_id();
                }
            }
        }

        $remaining = $limit - count($products);
        if ($remaining > 0) {
            $recentArgs = [
                'status'     => 'publish',
                'visibility' => 'catalog',
                'limit'      => $remaining,
                'orderby'    => 'date',
                'order'      => 'DESC',
            ];
            if ($seenIds !== []) {
                $recentArgs['exclude'] = $seenIds;
            }

            $recent = wc_get_products($recentArgs);
            if (is_array($recent)) {
                foreach ($recent as $product) {
                    if ($product instanceof \WC_Product && $product->is_visible()) {
                        $products[] = $product;
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Fetch populated real brands from native WooCommerce `product_brand` (or active `djgear_brand` with count > 0).
     * Returns an empty list when no usable populated brand source exists so the section stays cleanly hidden.
     *
     * @return list<array{id: int, name: string, slug: string, count: int, url: string, logo_url: string}>
     */
    public static function brands(int $limit = 12): array
    {
        $taxonomies = ['product_brand', 'djgear_brand'];

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => $limit,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ]);

            if (is_wp_error($terms) || !is_array($terms) || $terms === []) {
                continue;
            }

            $items = [];
            foreach ($terms as $term) {
                if (!$term instanceof \WP_Term || (int) $term->count <= 0) {
                    continue;
                }
                $link = get_term_link($term);
                if (is_wp_error($link) || !is_string($link)) {
                    continue;
                }

                $thumbId = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
                $logoUrl = $thumbId > 0 ? (string) (wp_get_attachment_image_url($thumbId, 'medium') ?: '') : '';

                $items[] = [
                    'id'       => (int) $term->term_id,
                    'name'     => $term->name,
                    'slug'     => $term->slug,
                    'count'    => (int) $term->count,
                    'url'      => $link,
                    'logo_url' => $logoUrl,
                ];
            }

            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /**
     * Resolve WooCommerce stock display badge details for a product card.
     *
     * @return array{status: string, label: string, tone: string}
     */
    public static function productStockInfo(\WC_Product $product): array
    {
        $status = $product->get_stock_status();

        if ($status === 'outofstock' || !$product->is_in_stock()) {
            return [
                'status' => 'outofstock',
                'label'  => __('Out of stock', 'djgear-theme'),
                'tone'   => 'danger',
            ];
        }

        if ($status === 'onbackorder') {
            return [
                'status' => 'onbackorder',
                'label'  => __('Backorder', 'djgear-theme'),
                'tone'   => 'warning',
            ];
        }

        if ($product->managing_stock()) {
            $qty = $product->get_stock_quantity();
            $threshold = function_exists('wc_get_low_stock_amount') ? (int) wc_get_low_stock_amount($product) : 2;
            if ($qty !== null && $qty > 0 && $qty <= max(1, $threshold)) {
                return [
                    'status' => 'lowstock',
                    'label'  => __('Low stock', 'djgear-theme'),
                    'tone'   => 'warning',
                ];
            }
        }

        return [
            'status' => 'instock',
            'label'  => __('In stock', 'djgear-theme'),
            'tone'   => 'success',
        ];
    }

    /**
     * Build native WooCommerce add-to-cart link attributes so standard WC AJAX cart
     * and non-simple product routing work cleanly without custom cart logic.
     *
     * @return array{url: string, text: string, classes: string, product_id: int, sku: string, aria_label: string}
     */
    public static function productActionData(\WC_Product $product): array
    {
        $classes = ['djg-btn', 'djg-btn--secondary', 'djg-product-card__cta', 'button', 'product_type_' . $product->get_type()];

        // Only simple in-stock purchasable products receive direct AJAX add-to-cart classes;
        // variable, grouped, external, and out-of-stock products link directly to their product page.
        if ($product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock()) {
            $classes[] = 'add_to_cart_button';
            if ($product->supports('ajax_add_to_cart')) {
                $classes[] = 'ajax_add_to_cart';
            }
        }

        return [
            'url'        => $product->add_to_cart_url(),
            'text'       => $product->add_to_cart_text(),
            'classes'    => implode(' ', array_map('sanitize_html_class', $classes)),
            'product_id' => $product->get_id(),
            'sku'        => $product->get_sku(),
            'aria_label' => $product->add_to_cart_description(),
        ];
    }
}
