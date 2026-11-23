<?php

declare(strict_types=1);

namespace DJGear\Theme\Assets;

/**
 * Vite manifest-backed asset loader with conditional WooCommerce loading and cache busting.
 */
final class AssetManager
{
    public const MAIN_SCRIPT_HANDLE = 'djgear-theme-main';
    public const MAIN_STYLE_HANDLE  = 'djgear-theme-main-css';
    public const WC_STYLE_HANDLE    = 'djgear-theme-woocommerce-css';
    public const HOME_STYLE_HANDLE  = 'djgear-theme-homepage-css';
    public const SHOP_SCRIPT_HANDLE    = 'djgear-theme-shop';
    public const SHOP_STYLE_HANDLE     = 'djgear-theme-shop-css';
    public const PRODUCT_SCRIPT_HANDLE = 'djgear-theme-product';
    public const PRODUCT_STYLE_HANDLE  = 'djgear-theme-product-css';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    private bool $manifestLoaded = false;

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets'], 20);
        add_filter('script_loader_tag', [$this, 'addModuleAttribute'], 10, 2);
        add_action('admin_notices', [$this, 'maybeShowMissingBuildNotice']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getManifest(): ?array
    {
        if ($this->manifestLoaded) {
            return $this->manifest;
        }
        $this->manifestLoaded = true;

        $manifestPath = DJGEAR_THEME_DIR . '/assets/dist/.vite/manifest.json';
        if (!is_readable($manifestPath)) {
            return null;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $this->manifest = $decoded;
        }

        return $this->manifest;
    }

    public function distBaseUri(): string
    {
        return DJGEAR_THEME_URI . '/assets/dist/';
    }

    public function enqueueFrontendAssets(): void
    {
        $manifest = $this->getManifest();
        if ($manifest === null) {
            return;
        }

        $distUrl = $this->distBaseUri();

        // Main entry (TypeScript + core design system CSS + bundled self-hosted fonts)
        $mainEntry = $manifest['assets/src/scripts/main.ts'] ?? null;
        if (is_array($mainEntry)) {
            if (isset($mainEntry['css']) && is_array($mainEntry['css'])) {
                foreach ($mainEntry['css'] as $idx => $cssFile) {
                    if (is_string($cssFile) && $cssFile !== '') {
                        wp_enqueue_style(
                            $idx === 0 ? self::MAIN_STYLE_HANDLE : self::MAIN_STYLE_HANDLE . '-' . (string) $idx,
                            $distUrl . ltrim($cssFile, '/'),
                            [],
                            null
                        );
                    }
                }
            }

            if (isset($mainEntry['file']) && is_string($mainEntry['file']) && $mainEntry['file'] !== '') {
                wp_enqueue_script(
                    self::MAIN_SCRIPT_HANDLE,
                    $distUrl . ltrim($mainEntry['file'], '/'),
                    [],
                    null,
                    true
                );

                $config = [
                    'isRtl'      => is_rtl(),
                    'locale'     => get_locale(),
                    'cartUrl'    => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
                    'homeUrl'    => home_url('/'),
                    'i18n'       => [
                        'openMenu'   => __('Open navigation menu', 'djgear-theme'),
                        'closeMenu'  => __('Close navigation menu', 'djgear-theme'),
                        'openCart'   => __('Open shopping cart', 'djgear-theme'),
                        'closeCart'  => __('Close shopping cart', 'djgear-theme'),
                        'openSearch' => __('Open product search', 'djgear-theme'),
                        'closeSearch'=> __('Close product search', 'djgear-theme'),
                    ],
                ];

                $json = wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($json)) {
                    wp_add_inline_script(
                        self::MAIN_SCRIPT_HANDLE,
                        'window.DJGEAR_THEME_CONFIG = ' . $json . ';',
                        'before'
                    );
                }
            }
        }

        // Conditional WooCommerce (Classic + Blocks + Custom Skins Portal) stylesheet
        if (class_exists('WooCommerce')) {
            $wcEntry = $manifest['assets/src/styles/woocommerce-entry.css'] ?? null;
            if (is_array($wcEntry) && isset($wcEntry['file']) && is_string($wcEntry['file']) && $wcEntry['file'] !== '') {
                wp_enqueue_style(
                    self::WC_STYLE_HANDLE,
                    $distUrl . ltrim($wcEntry['file'], '/'),
                    [self::MAIN_STYLE_HANDLE],
                    null
                );
            }
        }

        // Conditional Homepage stylesheet (loaded strictly on front page)
        if (is_front_page()) {
            $homeEntry = $manifest['assets/src/styles/homepage-entry.css'] ?? null;
            if (is_array($homeEntry) && isset($homeEntry['file']) && is_string($homeEntry['file']) && $homeEntry['file'] !== '') {
                wp_enqueue_style(
                    self::HOME_STYLE_HANDLE,
                    $distUrl . ltrim($homeEntry['file'], '/'),
                    [self::MAIN_STYLE_HANDLE],
                    null
                );
            }
        }
        // Conditional Shop & Product Archive assets (loaded strictly on shop/category/brand/tag archives)
        if (self::isShopArchiveContext()) {
            $shopEntry = $manifest['assets/src/scripts/shop.ts'] ?? null;
            if (is_array($shopEntry)) {
                if (isset($shopEntry['css']) && is_array($shopEntry['css'])) {
                    foreach ($shopEntry['css'] as $idx => $cssFile) {
                        if (is_string($cssFile) && $cssFile !== '') {
                            wp_enqueue_style(
                                $idx === 0 ? self::SHOP_STYLE_HANDLE : self::SHOP_STYLE_HANDLE . '-' . (string) $idx,
                                $distUrl . ltrim($cssFile, '/'),
                                [self::MAIN_STYLE_HANDLE],
                                null
                            );
                        }
                    }
                }
                if (isset($shopEntry['file']) && is_string($shopEntry['file']) && $shopEntry['file'] !== '') {
                    wp_enqueue_script(
                        self::SHOP_SCRIPT_HANDLE,
                        $distUrl . ltrim($shopEntry['file'], '/'),
                        [self::MAIN_SCRIPT_HANDLE],
                        null,
                        true
                    );
                }
            }
        }
        // Conditional Single Product assets (loaded strictly on single product pages)
        if (class_exists('WooCommerce') && function_exists('is_product') && is_product()) {
            $productEntry = $manifest['assets/src/scripts/product.ts'] ?? null;
            if (is_array($productEntry)) {
                if (isset($productEntry['css']) && is_array($productEntry['css'])) {
                    foreach ($productEntry['css'] as $idx => $cssFile) {
                        if (is_string($cssFile) && $cssFile !== '') {
                            wp_enqueue_style(
                                $idx === 0 ? self::PRODUCT_STYLE_HANDLE : self::PRODUCT_STYLE_HANDLE . '-' . (string) $idx,
                                $distUrl . ltrim($cssFile, '/'),
                                [self::MAIN_STYLE_HANDLE],
                                null
                            );
                        }
                    }
                }
                if (isset($productEntry['file']) && is_string($productEntry['file']) && $productEntry['file'] !== '') {
                    wp_enqueue_script(
                        self::PRODUCT_SCRIPT_HANDLE,
                        $distUrl . ltrim($productEntry['file'], '/'),
                        [self::MAIN_SCRIPT_HANDLE],
                        null,
                        true
                    );
                }
            }
        }
    }

    public static function isShopArchiveContext(): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        if (function_exists('is_shop') && is_shop()) {
            return true;
        }
        if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
            return true;
        }

        return is_search() && get_query_var('post_type') === 'product';
    }

    public function addModuleAttribute(string $tag, string $handle): string
    {
        if (
            $handle !== self::MAIN_SCRIPT_HANDLE
            && $handle !== self::SHOP_SCRIPT_HANDLE
            && $handle !== self::PRODUCT_SCRIPT_HANDLE
        ) {
            return $tag;
        }

        if (!str_contains($tag, 'type=')) {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }

        return $tag;
    }

    public function maybeShowMissingBuildNotice(): void
    {
        if (!current_user_can('switch_themes') || $this->getManifest() !== null) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'DJGear Theme frontend assets have not been built yet. Run "npm ci && npm run build" inside wp-content/themes/djgear-theme.',
            'djgear-theme'
        );
        echo '</p></div>';
    }
}
