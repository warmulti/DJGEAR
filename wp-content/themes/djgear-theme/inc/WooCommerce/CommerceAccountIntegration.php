<?php

declare(strict_types=1);

namespace DJGear\Theme\WooCommerce;

use DJGear\Theme\Template\CommerceAccountData;

/**
 * Non-intrusive WooCommerce Cart, Checkout, and My Account presentation hooks.
 *
 * Preserves all native WooCommerce Blocks, Store API, Classic shortcodes,
 * payment gateways, shipping, taxes, HPOS storage, and Custom Skins endpoints.
 */
final class CommerceAccountIntegration
{
    public function register(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('body_class', [$this, 'addCommerceAccountBodyClasses']);
        add_action('woocommerce_account_dashboard', [$this, 'renderDashboardOverview'], 15);
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function addCommerceAccountBodyClasses(array $classes): array
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            $classes[] = 'djg-wc-order-received';
        }

        if (function_exists('is_account_page') && is_account_page()) {
            $classes[] = is_user_logged_in() ? 'djg-wc-account-member' : 'djg-wc-account-guest';
        }

        return $classes;
    }

    public function renderDashboardOverview(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        get_template_part('template-parts/account/dashboard-cards', null, [
            'cards'         => CommerceAccountData::accountDashboardCards($userId),
            'recent_orders' => CommerceAccountData::recentOrders($userId, 3),
        ]);
    }
}
