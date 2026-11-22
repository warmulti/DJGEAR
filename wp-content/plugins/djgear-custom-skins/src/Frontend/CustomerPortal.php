<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Frontend;

use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Security\PublicSkinsSerializer;

/**
 * Customer-facing WooCommerce My Account endpoint (`/my-account/custom-skins/`)
 * and storefront Skin Order Configurator shortcode (`[djgear_skin_configurator]`).
 *
 * Commercial-only UI: strictly bilingual Persian (RTL) / English (LTR), zero engineering terminology.
 */
final class CustomerPortal
{
    public const MY_ACCOUNT_ENDPOINT = 'custom-skins';

    public function __construct(
        private readonly SkinJobRepository $jobs,
    ) {
    }

    public static function registerEndpoint(): void
    {
        $mask = (defined('EP_ROOT') ? (int) constant('EP_ROOT') : 64)
            | (defined('EP_PAGES') ? (int) constant('EP_PAGES') : 4096);
        add_rewrite_endpoint(self::MY_ACCOUNT_ENDPOINT, $mask);
    }

    public function registerHooks(): void
    {
        add_action('init', [self::class, 'registerEndpoint'], 10);
        add_filter('woocommerce_account_menu_items', [$this, 'addAccountMenuItem']);
        add_action('woocommerce_account_' . self::MY_ACCOUNT_ENDPOINT . '_endpoint', [$this, 'renderMyAccountPage']);
        add_shortcode('djgear_skin_configurator', [$this, 'renderConfiguratorShortcode']);
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function addAccountMenuItem(array $items): array
    {
        $new = [];
        $inserted = false;
        $label = __('Custom Skin Orders', 'djgear-custom-skins');

        foreach ($items as $key => $title) {
            $new[$key] = $title;
            if ($key === 'orders') {
                $new[self::MY_ACCOUNT_ENDPOINT] = $label;
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new[self::MY_ACCOUNT_ENDPOINT] = $label;
        }

        return $new;
    }

    /**
     * Render `/my-account/custom-skins/` order tracking & proof approval panel.
     */
    public function renderMyAccountPage(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            echo '<p>' . esc_html__('Please sign in to view your custom skin orders.', 'djgear-custom-skins') . '</p>';
            return;
        }

        $isRtl = function_exists('is_rtl') && is_rtl();
        $result = $this->jobs->listForCustomer($userId, 1, 25);
        $restNonce = wp_create_nonce('wp_rest');
        $restBase = esc_url_raw(rest_url('djgear/v1/skins'));

        echo '<div class="djgear-my-skins" dir="' . ($isRtl ? 'rtl' : 'ltr') . '" data-rest-base="' . esc_attr($restBase) . '" data-nonce="' . esc_attr($restNonce) . '">';
        echo '<h3>' . esc_html__('Your Custom Skin Orders', 'djgear-custom-skins') . '</h3>';

        if ($result['items'] === []) {
            echo '<p>' . esc_html__('You have not configured any custom DJ skins yet.', 'djgear-custom-skins') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('DJ Device', 'djgear-custom-skins') . '</th>';
        echo '<th>' . esc_html__('Skin Model & Color', 'djgear-custom-skins') . '</th>';
        echo '<th>' . esc_html__('Ordering Plan', 'djgear-custom-skins') . '</th>';
        echo '<th>' . esc_html__('Production Status', 'djgear-custom-skins') . '</th>';
        echo '<th>' . esc_html__('Order / Tracking', 'djgear-custom-skins') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($result['items'] as $rawJob) {
            $job = PublicSkinsSerializer::customerJob($rawJob);
            $snap = is_array($job['snapshot']) ? $job['snapshot'] : [];
            $device = is_array($snap['device'] ?? null) ? $snap['device'] : [];
            $variant = is_array($snap['variant'] ?? null) ? $snap['variant'] : [];
            $plan = is_array($snap['plan'] ?? null) ? $snap['plan'] : [];

            $deviceName = $isRtl ? (string) ($device['name_fa'] ?? $device['name_en'] ?? '') : (string) ($device['name_en'] ?? $device['name_fa'] ?? '');
            $modelName  = $isRtl ? (string) ($variant['model_name_fa'] ?? $variant['model_name_en'] ?? '') : (string) ($variant['model_name_en'] ?? $variant['model_name_fa'] ?? '');
            $colorName  = $isRtl ? (string) ($variant['color_name_fa'] ?? $variant['color_name_en'] ?? '') : (string) ($variant['color_name_en'] ?? $variant['color_name_fa'] ?? '');
            $planName   = $isRtl ? (string) ($plan['name_fa'] ?? $plan['name_en'] ?? '') : (string) ($plan['name_en'] ?? $plan['name_fa'] ?? '');

            echo '<tr>';
            echo '<td>' . esc_html($deviceName) . '</td>';
            echo '<td>' . esc_html(trim($modelName . ' — ' . $colorName, ' —')) . '</td>';
            echo '<td>' . esc_html($planName) . '</td>';
            echo '<td><strong>' . esc_html((string) $job['status']) . '</strong>';
            if ((string) $job['status'] === 'proof_sent') {
                echo ' <span class="djgear-proof-badge">(' . esc_html__('Proof Ready for Approval', 'djgear-custom-skins') . ')</span>';
            }
            echo '</td>';
            echo '<td>';
            if (!empty($job['wc_order_id'])) {
                $url = is_string($job['wc_order_url'] ?? null) ? $job['wc_order_url'] : '#';
                echo '<a href="' . esc_url($url) . '">#' . esc_html((string) $job['wc_order_id']) . '</a>';
            } else {
                echo '<span>' . esc_html__('Draft', 'djgear-custom-skins') . '</span>';
            }
            if (!empty($job['tracking_code'])) {
                echo '<br/><small>' . esc_html__('Tracking:', 'djgear-custom-skins') . ' ' . esc_html((string) $job['tracking_code']) . '</small>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Render `[djgear_skin_configurator]` commercial storefront ordering widget shell.
     *
     * @param array<string, mixed>|string $atts
     */
    public function renderConfiguratorShortcode(array|string $atts = []): string
    {
        $atts = is_array($atts) ? $atts : [];
        $deviceId = isset($atts['device_id']) && is_numeric($atts['device_id']) ? (int) $atts['device_id'] : 0;
        $isRtl = function_exists('is_rtl') && is_rtl();
        $isLoggedIn = is_user_logged_in();

        $config = [
            'deviceId'      => $deviceId,
            'locale'        => get_locale(),
            'dir'           => $isRtl ? 'rtl' : 'ltr',
            'isLoggedIn'    => $isLoggedIn,
            'loginUrl'      => wp_login_url(get_permalink() ?: home_url('/')),
            'cartUrl'       => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/'),
            'catalogApi'    => rest_url('djgear/v1/catalog/public/devices'),
            'skinsApi'      => rest_url('djgear/v1/skins'),
            'restNonce'     => $isLoggedIn ? wp_create_nonce('wp_rest') : '',
        ];

        $encodedConfig = wp_json_encode($config, JSON_UNESCAPED_UNICODE) ?: '{}';

        return '<section class="djgear-skin-configurator" dir="' . ($isRtl ? 'rtl' : 'ltr') . '" data-config="' . esc_attr($encodedConfig) . '">'
            . '<div class="djgear-skin-configurator__header">'
            . '<h2>' . esc_html__('Configure Your Custom DJ Skin', 'djgear-custom-skins') . '</h2>'
            . '<p>' . esc_html__('1. Select your DJ device · 2. Choose skin model & color · 3. Pick Standard or Custom Design plan · 4. Complete checkout via WooCommerce.', 'djgear-custom-skins') . '</p>'
            . '</div>'
            . '<div class="djgear-skin-configurator__mount" aria-live="polite"></div>'
            . '</section>';
    }
}
