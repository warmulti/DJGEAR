<?php

declare(strict_types=1);

namespace DJGear\Theme\Template;

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\WooCommerce\ShopQuery;

/**
 * Read-only view-model provider for WooCommerce Shop and Product Category/Brand archives.
 *
 * All taxonomy discovery, price bounds, active filter chips, sorting preservation, and
 * pagination URLs are prepared here so archive templates remain clean and presentation-focused.
 */
final class ShopData
{
    /**
     * Build clean header data for Shop, Product Category, Brand, Tag, or Product Search archive.
     *
     * @return array{
     *   title: string,
     *   description: string,
     *   thumbnail_url: string,
     *   thumbnail_alt: string,
     *   eyebrow: string,
     *   is_category: bool
     * }
     */
    public static function archiveHeader(): array
    {
        $title = function_exists('woocommerce_page_title') ? (string) woocommerce_page_title(false) : __('Shop', 'djgear-theme');
        $description = '';
        $thumbnailUrl = '';
        $thumbnailAlt = $title;
        $eyebrow = __('DJGEAR STOREFRONT', 'djgear-theme');
        $isCategory = false;

        if (function_exists('is_product_category') && is_product_category()) {
            $isCategory = true;
            $eyebrow = __('PRODUCT CATEGORY', 'djgear-theme');
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $title = $term->name;
                $description = trim(wp_strip_all_tags((string) term_description($term)));
                $thumbId = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
                if ($thumbId > 0) {
                    $src = wp_get_attachment_image_url($thumbId, 'djg-card-4x3');
                    if (is_string($src)) {
                        $thumbnailUrl = $src;
                    }
                    $altMeta = trim((string) get_post_meta($thumbId, '_wp_attachment_image_alt', true));
                    $thumbnailAlt = $altMeta !== '' ? $altMeta : $term->name;
                }
            }
        } elseif (is_tax('product_brand')) {
            $eyebrow = __('MANUFACTURER', 'djgear-theme');
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $title = $term->name;
                $description = trim(wp_strip_all_tags((string) term_description($term)));
                $thumbId = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
                if ($thumbId > 0) {
                    $src = wp_get_attachment_image_url($thumbId, 'medium');
                    if (is_string($src)) {
                        $thumbnailUrl = $src;
                    }
                    $thumbnailAlt = $term->name;
                }
            }
        } elseif (function_exists('is_product_tag') && is_product_tag()) {
            $eyebrow = __('PRODUCT TAG', 'djgear-theme');
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $title = $term->name;
                $description = trim(wp_strip_all_tags((string) term_description($term)));
            }
        } elseif (function_exists('is_shop') && is_shop()) {
            $shopId = function_exists('wc_get_page_id') ? (int) wc_get_page_id('shop') : 0;
            if ($shopId > 0) {
                $rawExcerpt = trim(wp_strip_all_tags((string) get_post_field('post_excerpt', $shopId)));
                if ($rawExcerpt !== '') {
                    $description = $rawExcerpt;
                }
            }
            if ($description === '') {
                $description = __('Explore professional DJ equipment, studio essentials, and compatible accessories across the DJGear catalog.', 'djgear-theme');
            }
        }

        return [
            'title'         => $title,
            'description'   => $description,
            'thumbnail_url' => $thumbnailUrl,
            'thumbnail_alt' => $thumbnailAlt,
            'eyebrow'       => $eyebrow,
            'is_category'   => $isCategory,
        ];
    }

    /**
     * Canonical base URL for the current archive without pagination or filter parameters.
     */
    public static function baseArchiveUrl(): string
    {
        if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $link = get_term_link($term);
                if (!is_wp_error($link) && is_string($link)) {
                    return $link;
                }
            }
        }

        if (is_search()) {
            return add_query_arg(
                [
                    's'         => get_search_query(false),
                    'post_type' => 'product',
                ],
                home_url('/')
            );
        }

        return HomepageData::shopUrl();
    }

    /**
     * Discover real, populated filter options for the current archive.
     * Never invents taxonomies, brands, attributes, or empty filter sections.
     *
     * @return array{
     *   categories: list<array{slug: string, name: string, count: int, checked: bool}>,
     *   brands: list<array{slug: string, name: string, count: int, checked: bool}>,
     *   attributes: list<array{slug: string, param: string, label: string, terms: list<array{slug: string, name: string, count: int, checked: bool}>}>,
     *   price_bounds: ?array{min: float, max: float},
     *   has_sale_products: bool,
     *   has_any_products: bool,
     *   params: array{
     *     categories: list<string>,
     *     brands: list<string>,
     *     min_price: ?float,
     *     max_price: ?float,
     *     in_stock: bool,
     *     on_sale: bool,
     *     attributes: array<string, list<string>>,
     *     orderby: string
     *   }
     * }
     */
    public static function filterModel(): array
    {
        $params = ShopQuery::currentParams();

        // 1. Categories: top-level non-empty categories on Shop, or non-empty child categories on a Category archive
        $categories = [];
        if (taxonomy_exists('product_cat')) {
            $catArgs = [
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'number'     => 50,
                'orderby'    => 'menu_order',
                'order'      => 'ASC',
            ];
            $defaultCatId = (int) get_option('default_product_cat', 0);
            if ($defaultCatId > 0) {
                $catArgs['exclude'] = [$defaultCatId];
            }

            if (function_exists('is_product_category') && is_product_category()) {
                $currentCat = get_queried_object();
                if ($currentCat instanceof \WP_Term) {
                    $catArgs['parent'] = (int) $currentCat->term_id;
                }
            }

            $catTerms = get_terms($catArgs);
            if (!is_wp_error($catTerms) && is_array($catTerms)) {
                foreach ($catTerms as $term) {
                    if (!$term instanceof \WP_Term || (int) $term->count <= 0) {
                        continue;
                    }
                    $categories[] = [
                        'slug'    => $term->slug,
                        'name'    => $term->name,
                        'count'   => (int) $term->count,
                        'checked' => in_array($term->slug, $params['categories'], true),
                    ];
                }
            }
        }

        // 2. Brands: native WooCommerce `product_brand` ONLY when populated (never shown on brand's own archive)
        $brands = [];
        if (taxonomy_exists('product_brand') && !is_tax('product_brand')) {
            $brandTerms = get_terms([
                'taxonomy'   => 'product_brand',
                'hide_empty' => true,
                'number'     => 50,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);
            if (!is_wp_error($brandTerms) && is_array($brandTerms)) {
                foreach ($brandTerms as $term) {
                    if (!$term instanceof \WP_Term || (int) $term->count <= 0) {
                        continue;
                    }
                    $brands[] = [
                        'slug'    => $term->slug,
                        'name'    => $term->name,
                        'count'   => (int) $term->count,
                        'checked' => in_array($term->slug, $params['brands'], true),
                    ];
                }
            }
        }

        // 3. Dynamic WooCommerce Product Attributes (`pa_*`) — ONLY populated attributes
        $attributes = [];
        foreach (ShopQuery::registeredAttributeTaxonomies() as $attrSlug => $meta) {
            $attrTerms = get_terms([
                'taxonomy'   => $meta['taxonomy'],
                'hide_empty' => true,
                'number'     => 50,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);
            if (is_wp_error($attrTerms) || !is_array($attrTerms) || $attrTerms === []) {
                continue;
            }

            $selected = $params['attributes'][$attrSlug] ?? [];
            $termItems = [];
            foreach ($attrTerms as $term) {
                if (!$term instanceof \WP_Term || (int) $term->count <= 0) {
                    continue;
                }
                $termItems[] = [
                    'slug'    => $term->slug,
                    'name'    => $term->name,
                    'count'   => (int) $term->count,
                    'checked' => in_array($term->slug, $selected, true),
                ];
            }

            if ($termItems !== []) {
                $attributes[] = [
                    'slug'  => $attrSlug,
                    'param' => ShopQuery::ATTR_PREFIX . $attrSlug,
                    'label' => $meta['label'],
                    'terms' => $termItems,
                ];
            }
        }

        // 4. Indexed Price Bounds & Store Population Check
        $priceBounds = self::indexedPriceBounds();
        $hasAnyProducts = ($priceBounds !== null) || self::hasPublishedProducts();

        // 5. On-sale filter availability
        $hasSaleProducts = false;
        if (function_exists('wc_get_product_ids_on_sale')) {
            $saleIds = array_filter(array_map('intval', wc_get_product_ids_on_sale()));
            $hasSaleProducts = ($saleIds !== []);
        }

        return [
            'categories'        => $categories,
            'brands'            => $brands,
            'attributes'        => $attributes,
            'price_bounds'      => $priceBounds,
            'has_sale_products' => $hasSaleProducts,
            'has_any_products'  => $hasAnyProducts,
            'params'            => $params,
        ];
    }

    /**
     * Build removable active filter chips preserving all other filters & sorting.
     *
     * @return list<array{label: string, remove_url: string}>
     */
    public static function activeFilterChips(): array
    {
        $params = ShopQuery::currentParams();
        $baseUrl = self::baseArchiveUrl();
        $chips = [];

        foreach ($params['categories'] as $slug) {
            $term = get_term_by('slug', $slug, 'product_cat');
            $label = $term instanceof \WP_Term ? $term->name : $slug;
            $next = $params;
            $next['categories'] = array_values(array_diff($params['categories'], [$slug]));
            $chips[] = [
                'label'      => sprintf('%s: %s', __('Category', 'djgear-theme'), $label),
                'remove_url' => self::buildUrlWithParams($baseUrl, $next),
            ];
        }

        foreach ($params['brands'] as $slug) {
            $term = get_term_by('slug', $slug, 'product_brand');
            $label = $term instanceof \WP_Term ? $term->name : $slug;
            $next = $params;
            $next['brands'] = array_values(array_diff($params['brands'], [$slug]));
            $chips[] = [
                'label'      => sprintf('%s: %s', __('Brand', 'djgear-theme'), $label),
                'remove_url' => self::buildUrlWithParams($baseUrl, $next),
            ];
        }

        if ($params['min_price'] !== null) {
            $next = $params;
            $next['min_price'] = null;
            $formatted = function_exists('wc_price') ? wp_strip_all_tags(wc_price($params['min_price'])) : (string) $params['min_price'];
            $chips[] = [
                'label'      => sprintf('%s: %s', __('Min price', 'djgear-theme'), html_entity_decode($formatted, ENT_QUOTES, 'UTF-8')),
                'remove_url' => self::buildUrlWithParams($baseUrl, $next),
            ];
        }

        if ($params['max_price'] !== null) {
            $next = $params;
            $next['max_price'] = null;
            $formatted = function_exists('wc_price') ? wp_strip_all_tags(wc_price($params['max_price'])) : (string) $params['max_price'];
            $chips[] = [
                'label'      => sprintf('%s: %s', __('Max price', 'djgear-theme'), html_entity_decode($formatted, ENT_QUOTES, 'UTF-8')),
                'remove_url' => self::buildUrlWithParams($baseUrl, $next),
            ];
        }

        if ($params['in_stock']) {
            $next = $params;
            $next['in_stock'] = false;
            $chips[] = [
                'label'      => __('In stock only', 'djgear-theme'),
                'remove_url' => self::buildUrlWithParams($baseUrl, $next),
            ];
        }

        if ($params['on_sale']) {
            $next = $params;
            $next['on_sale'] = false;
            $chips[] = [
                'label'      => __('On sale', 'djgear-theme'),
                'remove_url' => self::buildUrlWithParams($baseUrl, $next),
            ];
        }

        $attrMeta = ShopQuery::registeredAttributeTaxonomies();
        foreach ($params['attributes'] as $attrSlug => $termSlugs) {
            $meta = $attrMeta[$attrSlug] ?? null;
            if ($meta === null) {
                continue;
            }
            foreach ($termSlugs as $termSlug) {
                $term = get_term_by('slug', $termSlug, $meta['taxonomy']);
                $label = $term instanceof \WP_Term ? $term->name : $termSlug;
                $next = $params;
                $next['attributes'][$attrSlug] = array_values(array_diff($termSlugs, [$termSlug]));
                if ($next['attributes'][$attrSlug] === []) {
                    unset($next['attributes'][$attrSlug]);
                }
                $chips[] = [
                    'label'      => sprintf('%s: %s', $meta['label'], $label),
                    'remove_url' => self::buildUrlWithParams($baseUrl, $next),
                ];
            }
        }

        return $chips;
    }

    /**
     * URL to clear all active filters while preserving current sort choice if non-default.
     */
    public static function clearFiltersUrl(): string
    {
        $params = ShopQuery::currentParams();
        $baseUrl = self::baseArchiveUrl();
        $defaultSort = (string) get_option('woocommerce_default_catalog_orderby', 'menu_order');

        if ($params['orderby'] !== '' && $params['orderby'] !== $defaultSort) {
            return add_query_arg(ShopQuery::PARAM_ORDERBY, $params['orderby'], $baseUrl);
        }

        return $baseUrl;
    }

    /**
     * Format an accessible, localized result count string for the current archive query.
     */
    public static function resultCountLabel(\WP_Query $query): string
    {
        $total = max(0, (int) $query->found_posts);
        if ($total === 0) {
            return __('No products found', 'djgear-theme');
        }

        $perPage = max(1, (int) $query->get('posts_per_page'));
        $current = max(1, (int) $query->get('paged'));

        if ($total <= $perPage) {
            return sprintf(
                /* translators: %s: localized total product count */
                _n('Showing %s product', 'Showing all %s products', $total, 'djgear-theme'),
                I18n::formatCount($total)
            );
        }

        $first = ($perPage * ($current - 1)) + 1;
        $last = min($total, $perPage * $current);

        return sprintf(
            /* translators: 1: first item number, 2: last item number, 3: total results */
            __('Showing %1$s–%2$s of %3$s products', 'djgear-theme'),
            I18n::formatCount($first),
            I18n::formatCount($last),
            I18n::formatCount($total)
        );
    }

    /**
     * Render WooCommerce-compatible pagination preserving active GET filter and sort parameters.
     */
    public static function paginationHtml(\WP_Query $query): string
    {
        $totalPages = max(1, (int) $query->max_num_pages);
        if ($totalPages <= 1) {
            return '';
        }

        $current = max(1, (int) $query->get('paged'));
        $params = ShopQuery::currentParams();
        $addArgs = self::queryArgsFromParams($params);
        if (is_search()) {
            $addArgs['s'] = get_search_query(false);
            $addArgs['post_type'] = 'product';
        }

        $rawBase = explode('?', get_pagenum_link(999999999, false))[0];
        $base = esc_url_raw(str_replace('999999999', '%#%', $rawBase));

        $links = paginate_links([
            'base'      => $base,
            'format'    => '',
            'add_args'  => $addArgs !== [] ? $addArgs : false,
            'current'   => $current,
            'total'     => $totalPages,
            'prev_text' => esc_html__('Previous', 'djgear-theme'),
            'next_text' => esc_html__('Next', 'djgear-theme'),
            'type'      => 'list',
            'end_size'  => 1,
            'mid_size'  => 2,
        ]);

        return is_string($links) ? $links : '';
    }

    /**
     * @param array{
     *   categories: list<string>,
     *   brands: list<string>,
     *   min_price: ?float,
     *   max_price: ?float,
     *   in_stock: bool,
     *   on_sale: bool,
     *   attributes: array<string, list<string>>,
     *   orderby: string
     * } $params
     * @return array<string, string|list<string>>
     */
    public static function queryArgsFromParams(array $params, bool $includeOrderby = true): array
    {
        $args = [];

        if ($params['categories'] !== []) {
            $args[ShopQuery::PARAM_CAT] = $params['categories'];
        }
        if ($params['brands'] !== []) {
            $args[ShopQuery::PARAM_BRAND] = $params['brands'];
        }
        if ($params['min_price'] !== null) {
            $args[ShopQuery::PARAM_MIN] = (string) $params['min_price'];
        }
        if ($params['max_price'] !== null) {
            $args[ShopQuery::PARAM_MAX] = (string) $params['max_price'];
        }
        if ($params['in_stock']) {
            $args[ShopQuery::PARAM_STOCK] = 'instock';
        }
        if ($params['on_sale']) {
            $args[ShopQuery::PARAM_ON_SALE] = '1';
        }
        foreach ($params['attributes'] as $attrSlug => $slugs) {
            if ($slugs !== []) {
                $args[ShopQuery::ATTR_PREFIX . $attrSlug] = $slugs;
            }
        }

        if ($includeOrderby) {
            $defaultSort = (string) get_option('woocommerce_default_catalog_orderby', 'menu_order');
            if ($params['orderby'] !== '' && $params['orderby'] !== $defaultSort) {
                $args[ShopQuery::PARAM_ORDERBY] = $params['orderby'];
            }
        }

        return $args;
    }

    /**
     * @param array{
     *   categories: list<string>,
     *   brands: list<string>,
     *   min_price: ?float,
     *   max_price: ?float,
     *   in_stock: bool,
     *   on_sale: bool,
     *   attributes: array<string, list<string>>,
     *   orderby: string
     * } $params
     */
    private static function buildUrlWithParams(string $baseUrl, array $params): string
    {
        $args = self::queryArgsFromParams($params, true);
        if ($args === []) {
            return $baseUrl;
        }

        return add_query_arg($args, $baseUrl);
    }

    /**
     * Single indexed query on WooCommerce's `wc_product_meta_lookup` table (cached for 60s)
     * to discover real catalog min/max prices without scanning postmeta.
     *
     * @return ?array{min: float, max: float}
     */
    private static function indexedPriceBounds(): ?array
    {
        $cacheKey = 'djgear_theme_wc_price_bounds';
        $cached = wp_cache_get($cacheKey, 'djgear_theme');
        if (is_array($cached)) {
            return $cached['has_bounds'] ? ['min' => (float) $cached['min'], 'max' => (float) $cached['max']] : null;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return null;
        }

        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $posts = $wpdb->posts;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT MIN(l.min_price) AS min_p, MAX(l.max_price) AS max_p
                 FROM {$lookup} AS l
                 INNER JOIN {$posts} AS p ON p.ID = l.product_id
                 WHERE p.post_type = %s AND p.post_status = %s AND l.max_price > 0",
                'product',
                'publish'
            ),
            ARRAY_A
        );

        if (!is_array($row) || $row['min_p'] === null || $row['max_p'] === null) {
            wp_cache_set($cacheKey, ['has_bounds' => false], 'djgear_theme', 60);
            return null;
        }

        $min = floor((float) $row['min_p']);
        $max = ceil((float) $row['max_p']);

        wp_cache_set($cacheKey, ['has_bounds' => true, 'min' => $min, 'max' => $max], 'djgear_theme', 60);

        return ['min' => $min, 'max' => $max];
    }

    private static function hasPublishedProducts(): bool
    {
        $counts = wp_count_posts('product');

        return is_object($counts) && isset($counts->publish) && (int) $counts->publish > 0;
    }
}
