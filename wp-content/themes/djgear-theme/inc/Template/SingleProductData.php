<?php

declare(strict_types=1);

namespace DJGear\Theme\Template;

/**
 * Read-only view-model data provider for WooCommerce Single Product pages.
 *
 * Uses native WooCommerce APIs exclusively — zero business logic, zero direct SQL,
 * and zero hardcoded product IDs, prices, stock, ratings, or private paths.
 */
final class SingleProductData
{
    /**
     * Collect deduplicated gallery images (featured image first, followed by gallery attachments).
     *
     * @return list<array{
     *   id: int,
     *   main_url: string,
     *   main_srcset: string,
     *   main_sizes: string,
     *   thumb_url: string,
     *   full_url: string,
     *   width: int,
     *   height: int,
     *   alt: string,
     *   is_featured: bool
     * }>
     */
    public static function galleryItems(\WC_Product $product): array
    {
        $ids = [];
        $featuredId = (int) $product->get_image_id();
        if ($featuredId > 0) {
            $ids[] = $featuredId;
        }

        foreach ($product->get_gallery_image_ids() as $gid) {
            $gidInt = (int) $gid;
            if ($gidInt > 0 && !in_array($gidInt, $ids, true)) {
                $ids[] = $gidInt;
            }
        }

        $items = [];
        $productName = $product->get_name();

        foreach ($ids as $index => $attachmentId) {
            $main = wp_get_attachment_image_src($attachmentId, 'woocommerce_single');
            if (!is_array($main) || empty($main[0])) {
                continue;
            }

            $thumb = wp_get_attachment_image_url($attachmentId, 'woocommerce_gallery_thumbnail');
            $full = wp_get_attachment_image_url($attachmentId, 'full');
            $srcset = wp_get_attachment_image_srcset($attachmentId, 'woocommerce_single');
            $sizes = wp_get_attachment_image_sizes($attachmentId, 'woocommerce_single');
            $alt = trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
            if ($alt === '') {
                $alt = $index === 0
                    ? $productName
                    : sprintf(
                        /* translators: 1: product name, 2: image number */
                        __('%1$s — view %2$d', 'djgear-theme'),
                        $productName,
                        $index + 1
                    );
            }

            $items[] = [
                'id'          => $attachmentId,
                'main_url'    => (string) $main[0],
                'main_srcset' => is_string($srcset) ? $srcset : '',
                'main_sizes'  => is_string($sizes) ? $sizes : '(min-width: 1024px) 560px, 100vw',
                'thumb_url'   => is_string($thumb) && $thumb !== '' ? $thumb : (string) $main[0],
                'full_url'    => is_string($full) && $full !== '' ? $full : (string) $main[0],
                'width'       => max(1, (int) ($main[1] ?? 900)),
                'height'      => max(1, (int) ($main[2] ?? 900)),
                'alt'         => $alt,
                'is_featured' => ($index === 0),
            ];
        }

        return $items;
    }

    /**
     * Return real WooCommerce rating summary ONLY when ratings are enabled and reviews/ratings exist.
     *
     * @return ?array{average: float, rating_count: int, review_count: int, percent: float}
     */
    public static function ratingData(\WC_Product $product): ?array
    {
        if (!function_exists('wc_review_ratings_enabled') || !wc_review_ratings_enabled()) {
            return null;
        }

        $ratingCount = (int) $product->get_rating_count();
        $reviewCount = (int) $product->get_review_count();
        $average = (float) $product->get_average_rating();

        if ($ratingCount <= 0 || $average <= 0.0) {
            return null;
        }

        return [
            'average'      => round($average, 2),
            'rating_count' => $ratingCount,
            'review_count' => $reviewCount,
            'percent'      => min(100.0, max(0.0, ($average / 5.0) * 100.0)),
        ];
    }

    /**
     * Collect sanitized SKU, categories, brands, and tags via native WordPress/WooCommerce APIs.
     *
     * @return array{
     *   sku: string,
     *   categories: list<array{name: string, url: string}>,
     *   brands: list<array{name: string, url: string}>,
     *   tags: list<array{name: string, url: string}>
     * }
     */
    public static function metaData(\WC_Product $product): array
    {
        $productId = $product->get_id();

        return [
            'sku'        => trim($product->get_sku()),
            'categories' => self::termLinks($productId, 'product_cat'),
            'brands'     => taxonomy_exists('product_brand') ? self::termLinks($productId, 'product_brand') : [],
            'tags'       => self::termLinks($productId, 'product_tag'),
        ];
    }

    /**
     * Retrieve WooCommerce product tabs via the canonical `woocommerce_product_tabs` filter.
     *
     * @return array<string, array{title: string, priority: int, callback: callable}>
     */
    public static function productTabs(): array
    {
        $tabs = apply_filters('woocommerce_product_tabs', []);
        if (!is_array($tabs)) {
            return [];
        }

        $valid = [];
        foreach ($tabs as $key => $tab) {
            if (
                is_string($key)
                && is_array($tab)
                && isset($tab['title'], $tab['callback'])
                && is_string($tab['title'])
                && is_callable($tab['callback'])
            ) {
                $valid[$key] = [
                    'title'    => $tab['title'],
                    'priority' => isset($tab['priority']) ? (int) $tab['priority'] : 10,
                    'callback' => $tab['callback'],
                ];
            }
        }

        return $valid;
    }

    /**
     * Fetch visible related products without N+1 queries.
     *
     * @return list<\WC_Product>
     */
    public static function relatedProducts(\WC_Product $product, int $limit = 4): array
    {
        if (!function_exists('wc_get_related_products') || !function_exists('wc_get_products')) {
            return [];
        }

        $ids = wc_get_related_products($product->get_id(), $limit);
        if (!is_array($ids) || $ids === []) {
            return [];
        }

        $products = wc_get_products([
            'include'    => array_values(array_map('intval', $ids)),
            'status'     => 'publish',
            'visibility' => 'catalog',
            'limit'      => $limit,
            'orderby'    => 'post__in',
        ]);

        if (!is_array($products)) {
            return [];
        }

        $visible = [];
        foreach ($products as $candidate) {
            if ($candidate instanceof \WC_Product && $candidate->is_visible() && $candidate->get_id() !== $product->get_id()) {
                $visible[] = $candidate;
            }
        }

        return $visible;
    }

    /**
     * Read-only lookup of active DJ devices linked to this WooCommerce product in `djgear-device-catalog`.
     * Passes every record through `PublicCatalogSerializer::deviceSummary()` so zero private/internal fields leak.
     *
     * @return list<array{id: int, name: string, model_code: string, skin_ready: bool}>
     */
    public static function compatibleDevices(\WC_Product $product): array
    {
        if (
            !function_exists('djgear_core')
            || !class_exists('DJGear\\DeviceCatalog\\Repositories\\CatalogDetailsRepository')
            || !class_exists('DJGear\\DeviceCatalog\\Security\\PublicCatalogSerializer')
        ) {
            return [];
        }

        $wcId = $product->get_id();
        $cacheKey = 'djgear_theme_wc_devices_' . $wcId;
        $cached = wp_cache_get($cacheKey, 'djgear_theme');
        if (is_array($cached)) {
            return $cached;
        }

        $items = [];
        try {
            $container = djgear_core()->container();
            $repoClass = 'DJGear\\DeviceCatalog\\Repositories\\CatalogDetailsRepository';
            if ($container->has($repoClass)) {
                $repo = $container->get($repoClass);
                if (is_object($repo) && method_exists($repo, 'findDevicesForWooCommerceProduct')) {
                    $raw = $repo->findDevicesForWooCommerceProduct($wcId, true);
                    $isRtl = is_rtl();
                    if (is_array($raw)) {
                        foreach ($raw as $device) {
                            if (!is_array($device)) {
                                continue;
                            }
                            $summary = \DJGear\DeviceCatalog\Security\PublicCatalogSerializer::deviceSummary($device);
                            $name = $isRtl
                                ? (string) ($summary['name_fa'] ?: $summary['name_en'] ?: '')
                                : (string) ($summary['name_en'] ?: $summary['name_fa'] ?: '');
                            if ($name === '') {
                                continue;
                            }
                            $items[] = [
                                'id'         => (int) ($summary['id'] ?? 0),
                                'name'       => $name,
                                'model_code' => (string) ($summary['model_code'] ?? ''),
                                'skin_ready' => !empty($summary['skin_ready']),
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Gracefully degrade if device catalog module is unavailable
            $items = [];
        }

        wp_cache_set($cacheKey, $items, 'djgear_theme', 60);

        return $items;
    }

    /**
     * Build a truthful, non-duplicative Custom Skins portal link context for this product.
     *
     * @param list<array{id: int, name: string, model_code: string, skin_ready: bool}> $compatibleDevices
     * @return ?array{url: string, title: string, description: string, cta_label: string, is_skin_ready_match: bool}
     */
    public static function customSkinsCallout(\WC_Product $product, array $compatibleDevices): ?array
    {
        $skinsUrl = HomepageData::customSkinsUrl();
        if ($skinsUrl === '') {
            return null;
        }

        $skinReadyDevices = array_values(array_filter(
            $compatibleDevices,
            static fn (array $d): bool => !empty($d['skin_ready'])
        ));

        if ($skinReadyDevices !== []) {
            $firstDevice = $skinReadyDevices[0];
            $targetUrl = $skinsUrl;
            // Only append device_id hint when pointing to a dedicated configurator page (never alter My Account endpoint URL)
            if (!str_contains($skinsUrl, 'my-account') && $firstDevice['id'] > 0) {
                $targetUrl = add_query_arg('device_id', $firstDevice['id'], $skinsUrl);
            }

            return [
                'url'                 => $targetUrl,
                'title'               => __('Custom Skin available for this device', 'djgear-theme'),
                'description'         => sprintf(
                    /* translators: %s: compatible DJ device name */
                    __('Personalize your %s with a Standard finish or Custom Design artwork through the DJGear Custom Skins portal.', 'djgear-theme'),
                    $firstDevice['name']
                ),
                'cta_label'           => __('Configure Custom Skin', 'djgear-theme'),
                'is_skin_ready_match' => true,
            ];
        }

        // Check whether this WooCommerce product is itself linked as a base product for an active SkinVariant
        if (self::isActiveSkinVariantBaseProduct($product->get_id())) {
            return [
                'url'                 => $skinsUrl,
                'title'               => __('Order through Custom Skins Portal', 'djgear-theme'),
                'description'         => __('Select your DJ device model, finish, and Standard or Custom Design plan in the dedicated Custom Skins workflow.', 'djgear-theme'),
                'cta_label'           => __('Open Custom Skins Portal', 'djgear-theme'),
                'is_skin_ready_match' => true,
            ];
        }

        return [
            'url'                 => $skinsUrl,
            'title'               => __('Custom DJ Equipment Skins', 'djgear-theme'),
            'description'         => __('Explore compatible DJ devices and custom skin finishes in the DJGear Custom Skins portal.', 'djgear-theme'),
            'cta_label'           => __('Explore Custom Skins', 'djgear-theme'),
            'is_skin_ready_match' => false,
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private static function termLinks(int $productId, string $taxonomy): array
    {
        $terms = get_the_terms($productId, $taxonomy);
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $link = get_term_link($term);
            if (!is_wp_error($link) && is_string($link)) {
                $out[] = [
                    'name' => $term->name,
                    'url'  => $link,
                ];
            }
        }

        return $out;
    }

    private static function isActiveSkinVariantBaseProduct(int $wcProductId): bool
    {
        if (
            $wcProductId <= 0
            || !function_exists('djgear_core')
            || !class_exists('DJGear\\CustomSkins\\Repositories\\SkinVariantRepository')
        ) {
            return false;
        }

        $cacheKey = 'djgear_theme_is_skin_base_' . $wcProductId;
        $cached = wp_cache_get($cacheKey, 'djgear_theme');
        if (is_int($cached)) {
            return $cached === 1;
        }

        $matched = false;
        try {
            $container = djgear_core()->container();
            $repoClass = 'DJGear\\CustomSkins\\Repositories\\SkinVariantRepository';
            if ($container->has($repoClass)) {
                $repo = $container->get($repoClass);
                if (is_object($repo) && method_exists($repo, 'listAll')) {
                    $variants = $repo->listAll(true);
                    if (is_array($variants)) {
                        foreach ($variants as $variant) {
                            if (is_array($variant) && (int) ($variant['wc_product_id'] ?? 0) === $wcProductId) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $matched = false;
        }

        wp_cache_set($cacheKey, $matched ? 1 : 0, 'djgear_theme', 60);

        return $matched;
    }
}
