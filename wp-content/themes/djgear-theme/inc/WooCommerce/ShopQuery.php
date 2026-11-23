<?php

declare(strict_types=1);

namespace DJGear\Theme\WooCommerce;

/**
 * Native WooCommerce archive query filtering & strict GET parameter sanitization.
 *
 * Hooks strictly into `woocommerce_product_query` for frontend Shop and Product
 * taxonomy archives — never touches admin queries, REST requests, single products,
 * or non-WooCommerce queries. Prevents arbitrary taxonomy/meta injection by
 * enforcing strict allowlists against registered WooCommerce taxonomies.
 */
final class ShopQuery
{
    public const PARAM_CAT      = 'filter_cat';
    public const PARAM_BRAND    = 'filter_brand';
    public const PARAM_STOCK    = 'filter_stock';
    public const PARAM_ON_SALE  = 'filter_on_sale';
    public const PARAM_MIN      = 'min_price';
    public const PARAM_MAX      = 'max_price';
    public const PARAM_ORDERBY  = 'orderby';
    public const ATTR_PREFIX    = 'filter_attr_';

    public function register(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_product_query', [$this, 'applyArchiveFilters'], 20, 1);
        add_filter('woocommerce_catalog_orderby', [$this, 'filterSupportedSortOptions']);
        add_filter('loop_shop_per_page', static fn (): int => 12, 20);
    }

    /**
     * Allowlist supported WooCommerce sorting options dynamically (only exposing rating when enabled).
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    public function filterSupportedSortOptions(array $options): array
    {
        $allowed = self::supportedSortOptions();

        return array_intersect_key($allowed, $options) ?: $allowed;
    }

    /**
     * @return array<string, string>
     */
    public static function supportedSortOptions(): array
    {
        $options = [
            'menu_order' => __('Default sorting', 'djgear-theme'),
            'popularity' => __('Sort by popularity', 'djgear-theme'),
        ];

        if (function_exists('wc_review_ratings_enabled') && wc_review_ratings_enabled()) {
            $options['rating'] = __('Sort by average rating', 'djgear-theme');
        }

        $options['date']       = __('Sort by latest', 'djgear-theme');
        $options['price']      = __('Price: low to high', 'djgear-theme');
        $options['price-desc'] = __('Price: high to low', 'djgear-theme');

        return $options;
    }

    /**
     * Discover all real registered WooCommerce attribute taxonomies (`pa_*`).
     *
     * @return array<string, array{slug: string, taxonomy: string, label: string}>
     */
    public static function registeredAttributeTaxonomies(): array
    {
        if (!function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        $map = [];
        $raw = wc_get_attribute_taxonomies();
        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $attr) {
            if (!is_object($attr) || empty($attr->attribute_name)) {
                continue;
            }
            $slug = sanitize_title((string) $attr->attribute_name);
            $taxonomy = 'pa_' . $slug;
            if ($slug === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $label = !empty($attr->attribute_label) ? (string) $attr->attribute_label : ucfirst($slug);
            $map[$slug] = [
                'slug'     => $slug,
                'taxonomy' => $taxonomy,
                'label'    => $label,
            ];
        }

        return $map;
    }

    /**
     * Parse and strictly sanitize all supported GET filter parameters.
     *
     * @return array{
     *   categories: list<string>,
     *   brands: list<string>,
     *   min_price: ?float,
     *   max_price: ?float,
     *   in_stock: bool,
     *   on_sale: bool,
     *   attributes: array<string, list<string>>,
     *   orderby: string
     * }
     */
    public static function currentParams(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET archive filtering
        $categories = self::parseSlugList($_GET[self::PARAM_CAT] ?? null, 'product_cat');
        $brands = taxonomy_exists('product_brand')
            ? self::parseSlugList($_GET[self::PARAM_BRAND] ?? null, 'product_brand')
            : [];

        $minPrice = self::parsePrice($_GET[self::PARAM_MIN] ?? null);
        $maxPrice = self::parsePrice($_GET[self::PARAM_MAX] ?? null);
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
        }

        $stockRaw = isset($_GET[self::PARAM_STOCK]) && is_scalar($_GET[self::PARAM_STOCK])
            ? sanitize_key((string) wp_unslash($_GET[self::PARAM_STOCK]))
            : '';
        $inStock = ($stockRaw === 'instock');

        $saleRaw = isset($_GET[self::PARAM_ON_SALE]) && is_scalar($_GET[self::PARAM_ON_SALE])
            ? sanitize_key((string) wp_unslash($_GET[self::PARAM_ON_SALE]))
            : '';
        $onSale = ($saleRaw === '1');

        $attributes = [];
        foreach (self::registeredAttributeTaxonomies() as $slug => $meta) {
            $paramKey = self::ATTR_PREFIX . $slug;
            $terms = self::parseSlugList($_GET[$paramKey] ?? null, $meta['taxonomy']);
            if ($terms !== []) {
                $attributes[$slug] = $terms;
            }
        }

        $supportedSort = self::supportedSortOptions();
        $defaultSort = (string) get_option('woocommerce_default_catalog_orderby', 'menu_order');
        if (!isset($supportedSort[$defaultSort])) {
            $defaultSort = 'menu_order';
        }

        $orderbyRaw = isset($_GET[self::PARAM_ORDERBY]) && is_scalar($_GET[self::PARAM_ORDERBY])
            ? sanitize_key((string) wp_unslash($_GET[self::PARAM_ORDERBY]))
            : '';
        $orderby = isset($supportedSort[$orderbyRaw]) ? $orderbyRaw : $defaultSort;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return [
            'categories' => $categories,
            'brands'     => $brands,
            'min_price'  => $minPrice,
            'max_price'  => $maxPrice,
            'in_stock'   => $inStock,
            'on_sale'    => $onSale,
            'attributes' => $attributes,
            'orderby'    => $orderby,
        ];
    }

    /**
     * Apply validated filter selections cleanly onto WooCommerce's main archive WP_Query.
     */
    public function applyArchiveFilters(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        $params = self::currentParams();

        // Normalize invalid or inverted min/max_price cleanly before WC_Query::price_filter_post_clauses
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ($params['min_price'] !== null) {
            $_GET[self::PARAM_MIN] = (string) $params['min_price'];
        } else {
            unset($_GET[self::PARAM_MIN]);
        }
        if ($params['max_price'] !== null) {
            $_GET[self::PARAM_MAX] = (string) $params['max_price'];
        } else {
            unset($_GET[self::PARAM_MAX]);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $taxQuery = $query->get('tax_query');
        if (!is_array($taxQuery)) {
            $taxQuery = [];
        }

        if ($params['categories'] !== []) {
            $taxQuery[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $params['categories'],
                'operator' => 'IN',
            ];
        }

        if ($params['brands'] !== [] && taxonomy_exists('product_brand')) {
            $taxQuery[] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => $params['brands'],
                'operator' => 'IN',
            ];
        }

        foreach ($params['attributes'] as $attrSlug => $termSlugs) {
            $taxonomy = 'pa_' . $attrSlug;
            if (taxonomy_exists($taxonomy) && $termSlugs !== []) {
                $taxQuery[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => $termSlugs,
                    'operator' => 'IN',
                ];
            }
        }

        if ($taxQuery !== []) {
            if (!isset($taxQuery['relation'])) {
                $taxQuery['relation'] = 'AND';
            }
            $query->set('tax_query', $taxQuery);
        }

        if ($params['in_stock']) {
            $metaQuery = $query->get('meta_query');
            if (!is_array($metaQuery)) {
                $metaQuery = [];
            }
            $metaQuery[] = [
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ];
            $query->set('meta_query', $metaQuery);
        }

        if ($params['on_sale'] && function_exists('wc_get_product_ids_on_sale')) {
            $saleIds = array_values(array_filter(array_map('intval', wc_get_product_ids_on_sale())));
            $existingPostIn = $query->get('post__in');
            if (is_array($existingPostIn) && $existingPostIn !== []) {
                $saleIds = array_values(array_intersect(array_map('intval', $existingPostIn), $saleIds));
            }
            $query->set('post__in', $saleIds !== [] ? $saleIds : [0]);
        }
    }

    /**
     * Parse scalar comma-separated or array GET parameter into validated existing term slugs.
     *
     * @param mixed $raw
     * @return list<string>
     */
    private static function parseSlugList(mixed $raw, string $taxonomy): array
    {
        if ($raw === null || !taxonomy_exists($taxonomy)) {
            return [];
        }

        $candidates = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_scalar($item)) {
                    $candidates[] = sanitize_title((string) wp_unslash($item));
                }
            }
        } elseif (is_scalar($raw)) {
            foreach (explode(',', (string) wp_unslash($raw)) as $part) {
                $candidates[] = sanitize_title($part);
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (string $s): bool => $s !== '')));
        if ($candidates === []) {
            return [];
        }

        // Cap maximum simultaneous term selections to prevent abusive mega-queries
        $candidates = array_slice($candidates, 0, 20);

        $valid = [];
        foreach ($candidates as $slug) {
            if (term_exists($slug, $taxonomy)) {
                $valid[] = $slug;
            }
        }

        return $valid;
    }

    private static function parsePrice(mixed $raw): ?float
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $str = trim((string) wp_unslash($raw));
        if ($str === '' || !is_numeric($str)) {
            return null;
        }

        $val = (float) $str;
        if (!is_finite($val) || $val < 0.0) {
            return null;
        }

        return round($val, 2);
    }
}
