<?php

declare(strict_types=1);

namespace DJGear\Theme\WooCommerce;

use DJGear\Theme\Setup\I18n;

/**
 * Native WooCommerce 11.x presentation & HPOS-safe integration hooks.
 *
 * Strictly visual/structural wrappers — never overrides pricing, cart validation,
 * checkout persistence, taxes, or order logic.
 */
final class WooCommerceSetup
{
    public function register(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Replace default WooCommerce wrapper markup with DJGear container landmarks
        remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
        remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
        add_action('woocommerce_before_main_content', [$this, 'openContentWrapper'], 10);
        add_action('woocommerce_after_main_content', [$this, 'closeContentWrapper'], 10);

        // Remove default sidebar on WooCommerce templates for clean full-width studio layout
        remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);

        // Replace default shop archive count/ordering/pagination with theme's filter-aware toolbar & pagination
        remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
        remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);
        remove_action('woocommerce_after_shop_loop', 'woocommerce_pagination', 10);

        // Keep native WooCommerce AJAX cart counter synchronized in header badge
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'syncCartCountFragment']);

        // Style WooCommerce breadcrumbs with theme semantic separator & ARIA
        add_filter('woocommerce_breadcrumb_defaults', [$this, 'filterBreadcrumbDefaults']);

        // Add body class when rendering WooCommerce pages
        add_filter('body_class', [$this, 'addWooCommerceBodyClasses']);
    }

    public function openContentWrapper(): void
    {
        echo '<div class="djg-container djg-wc-main">';
    }

    public function closeContentWrapper(): void
    {
        echo '</div>';
    }

    /**
     * @param array<string, string> $fragments
     * @return array<string, string>
     */
    public function syncCartCountFragment(array $fragments): array
    {
        $count = self::cartItemCount();
        $fragments['.djg-cart-badge'] = self::renderCartBadgeHtml($count);

        return $fragments;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function filterBreadcrumbDefaults(array $defaults): array
    {
        $defaults['delimiter']   = '<span class="djg-breadcrumbs__sep" aria-hidden="true">/</span>';
        $defaults['wrap_before'] = '<nav class="djg-breadcrumbs" aria-label="' . esc_attr__('Breadcrumb', 'djgear-theme') . '"><ol class="djg-breadcrumbs__list">';
        $defaults['wrap_after']  = '</ol></nav>';
        $defaults['before']      = '<li class="djg-breadcrumbs__item">';
        $defaults['after']       = '</li>';

        return $defaults;
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function addWooCommerceBodyClasses(array $classes): array
    {
        if (function_exists('is_woocommerce') && is_woocommerce()) {
            $classes[] = 'djg-wc-page';
        }
        if (function_exists('is_cart') && is_cart()) {
            $classes[] = 'djg-wc-cart';
        }
        if (function_exists('is_checkout') && is_checkout()) {
            $classes[] = 'djg-wc-checkout';
        }
        if (function_exists('is_account_page') && is_account_page()) {
            $classes[] = 'djg-wc-account';
        }

        return $classes;
    }

    public static function cartItemCount(): int
    {
        if (!function_exists('WC') || WC()->cart === null) {
            return 0;
        }

        return max(0, (int) WC()->cart->get_cart_contents_count());
    }

    public static function renderCartBadgeHtml(int $count): string
    {
        $hiddenClass = $count === 0 ? ' is-empty' : '';

        return sprintf(
            '<span class="djg-cart-badge%s" data-cart-count="%d" aria-live="polite">%s</span>',
            $hiddenClass,
            $count,
            esc_html(I18n::formatCount($count))
        );
    }
}
