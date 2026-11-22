<?php

declare(strict_types=1);

namespace DJGear\Theme\Setup;

/**
 * Registers semantic navigation menu locations with graceful storefront fallbacks.
 */
final class Menus
{
    public const PRIMARY        = 'primary';
    public const MOBILE         = 'mobile';
    public const FOOTER_SHOP    = 'footer_shop';
    public const FOOTER_SUPPORT = 'footer_support';
    public const FOOTER_LEGAL   = 'footer_legal';

    public function register(): void
    {
        add_action('init', [$this, 'registerLocations'], 10);
        add_filter('nav_menu_link_attributes', [$this, 'enhanceMenuLinkAttributes'], 10, 4);
    }

    public function registerLocations(): void
    {
        register_nav_menus([
            self::PRIMARY        => esc_html__('Primary Header Navigation', 'djgear-theme'),
            self::MOBILE         => esc_html__('Mobile Drawer Navigation', 'djgear-theme'),
            self::FOOTER_SHOP    => esc_html__('Footer — Shop & Gear', 'djgear-theme'),
            self::FOOTER_SUPPORT => esc_html__('Footer — Customer Support', 'djgear-theme'),
            self::FOOTER_LEGAL   => esc_html__('Footer — Legal & Policies', 'djgear-theme'),
        ]);
    }

    /**
     * @param array<string, mixed> $atts
     * @param \WP_Post             $item
     * @param \stdClass            $args
     * @return array<string, mixed>
     */
    public function enhanceMenuLinkAttributes(array $atts, \WP_Post $item, \stdClass $args, int $depth): array
    {
        $location = is_string($args->theme_location ?? null) ? $args->theme_location : '';

        if ($location === self::PRIMARY || $location === self::MOBILE) {
            $classes = isset($atts['class']) && is_string($atts['class']) ? $atts['class'] . ' ' : '';
            $atts['class'] = trim($classes . ($depth === 0 ? 'djg-nav__link' : 'djg-nav__sublink'));
        }

        if (in_array('current-menu-item', (array) $item->classes, true)) {
            $atts['aria-current'] = 'page';
        }

        return $atts;
    }

    /**
     * Render clean fallback storefront links when an admin has not yet assigned a WP Menu.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    public static function defaultPrimaryItems(): array
    {
        $homeUrl = home_url('/');
        $shopUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : $homeUrl;
        $accountUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : $homeUrl;

        $items = [
            [
                'label'   => esc_html__('Home', 'djgear-theme'),
                'url'     => $homeUrl,
                'current' => is_front_page(),
            ],
        ];

        if (is_string($shopUrl) && $shopUrl !== '') {
            $items[] = [
                'label'   => esc_html__('Shop', 'djgear-theme'),
                'url'     => $shopUrl,
                'current' => function_exists('is_shop') && is_shop(),
            ];
        }

        if (is_string($accountUrl) && $accountUrl !== '') {
            $items[] = [
                'label'   => esc_html__('My Account', 'djgear-theme'),
                'url'     => $accountUrl,
                'current' => function_exists('is_account_page') && is_account_page(),
            ];
        }

        return $items;
    }
}
