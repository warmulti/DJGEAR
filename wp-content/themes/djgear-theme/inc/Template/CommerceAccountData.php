<?php

declare(strict_types=1);

namespace DJGear\Theme\Template;

use DJGear\Theme\Setup\I18n;

/**
 * Read-only view-model provider for WooCommerce Cart, Checkout, Order Confirmation,
 * and My Account pages.
 *
 * Strictly HPOS-safe: uses WooCommerce object getters (`wc_get_orders()`, `WC_Order`,
 * `wc_get_account_endpoint_url()`) exclusively — never queries `wp_posts` directly for
 * orders and never duplicates cart, checkout, payment, or Custom Skins business logic.
 */
final class CommerceAccountData
{
    public static function isCommerceAccountPage(): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        return (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout())
            || (function_exists('is_account_page') && is_account_page());
    }

    /**
     * Resolve header context and checkout stepper state for Cart, Checkout, Order Received, and My Account.
     *
     * @return array{
     *   kind: string,
     *   eyebrow: string,
     *   title: string,
     *   subtitle: string,
     *   show_stepper: bool,
     *   active_step: int
     * }
     */
    public static function pageHeaderContext(): array
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return [
                'kind'         => 'order_received',
                'eyebrow'      => __('ORDER CONFIRMATION', 'djgear-theme'),
                'title'        => __('Thank you for your order', 'djgear-theme'),
                'subtitle'     => __('Review your WooCommerce order summary and next steps below.', 'djgear-theme'),
                'show_stepper' => true,
                'active_step'  => 3,
            ];
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            return [
                'kind'         => 'order_pay',
                'eyebrow'      => __('PAYMENT', 'djgear-theme'),
                'title'        => __('Complete order payment', 'djgear-theme'),
                'subtitle'     => __('Select a configured payment method below to complete this order.', 'djgear-theme'),
                'show_stepper' => true,
                'active_step'  => 2,
            ];
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return [
                'kind'         => 'checkout',
                'eyebrow'      => __('SECURE CHECKOUT', 'djgear-theme'),
                'title'        => get_the_title() ?: __('Checkout', 'djgear-theme'),
                'subtitle'     => __('Verify billing, shipping, and payment details to place your order.', 'djgear-theme'),
                'show_stepper' => true,
                'active_step'  => 2,
            ];
        }

        if (function_exists('is_cart') && is_cart()) {
            return [
                'kind'         => 'cart',
                'eyebrow'      => __('SHOPPING CART', 'djgear-theme'),
                'title'        => get_the_title() ?: __('Cart', 'djgear-theme'),
                'subtitle'     => __('Review selected DJ equipment and custom skin configurations before checkout.', 'djgear-theme'),
                'show_stepper' => true,
                'active_step'  => 1,
            ];
        }

        // My Account
        if (!is_user_logged_in()) {
            $isLostPass = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('lost-password');
            return [
                'kind'         => 'account_guest',
                'eyebrow'      => __('CUSTOMER ACCOUNT', 'djgear-theme'),
                'title'        => $isLostPass
                    ? __('Reset password', 'djgear-theme')
                    : (get_the_title() ?: __('My Account', 'djgear-theme')),
                'subtitle'     => $isLostPass
                    ? __('Enter your account email or username to receive a password reset link.', 'djgear-theme')
                    : __('Sign in to manage orders, addresses, and custom skin proof approvals.', 'djgear-theme'),
                'show_stepper' => false,
                'active_step'  => 0,
            ];
        }

        $user = wp_get_current_user();
        $displayName = $user instanceof \WP_User && $user->display_name !== ''
            ? $user->display_name
            : __('Customer', 'djgear-theme');

        return [
            'kind'         => 'account_member',
            'eyebrow'      => __('CUSTOMER ACCOUNT', 'djgear-theme'),
            'title'        => get_the_title() ?: __('My Account', 'djgear-theme'),
            'subtitle'     => sprintf(
                /* translators: %s: logged-in customer display name */
                __('Signed in as %s. Track orders, update addresses, and manage custom skin orders.', 'djgear-theme'),
                $displayName
            ),
            'show_stepper' => false,
            'active_step'  => 0,
        ];
    }

    /**
     * Build quick-access cards for the My Account Dashboard endpoint using native WooCommerce endpoints.
     *
     * @return list<array{title: string, description: string, url: string, icon: string, badge: string}>
     */
    public static function accountDashboardCards(int $userId): array
    {
        if ($userId <= 0 || !function_exists('wc_get_account_endpoint_url')) {
            return [];
        }

        $orderCount = self::customerOrderCount($userId);

        $cards = [
            [
                'title'       => __('Orders', 'djgear-theme'),
                'description' => __('View order history, payment status, and receipts.', 'djgear-theme'),
                'url'         => (string) wc_get_account_endpoint_url('orders'),
                'icon'        => 'cart',
                'badge'       => $orderCount > 0 ? I18n::formatCount($orderCount) : '',
            ],
        ];

        $skinsUrl = HomepageData::customSkinsUrl();
        if ($skinsUrl !== '') {
            $cards[] = [
                'title'       => __('Custom Skin Orders', 'djgear-theme'),
                'description' => __('Track skin production stages, artwork uploads, and proof approvals.', 'djgear-theme'),
                'url'         => $skinsUrl,
                'icon'        => 'layers',
                'badge'       => '',
            ];
        }

        $cards[] = [
            'title'       => __('Addresses', 'djgear-theme'),
            'description' => __('Manage billing and shipping addresses for faster checkout.', 'djgear-theme'),
            'url'         => (string) wc_get_account_endpoint_url('edit-address'),
            'icon'        => 'truck',
            'badge'       => '',
        ];

        $cards[] = [
            'title'       => __('Account Details', 'djgear-theme'),
            'description' => __('Update your name, email address, and account password.', 'djgear-theme'),
            'url'         => (string) wc_get_account_endpoint_url('edit-account'),
            'icon'        => 'user',
            'badge'       => '',
        ];

        return $cards;
    }

    /**
     * Fetch up to 3 most recent orders for the logged-in customer strictly via HPOS-safe `wc_get_orders()`.
     *
     * @return list<array{number: string, date: string, status_slug: string, status_label: string, total_html: string, view_url: string}>
     */
    public static function recentOrders(int $userId, int $limit = 3): array
    {
        if ($userId <= 0 || !function_exists('wc_get_orders')) {
            return [];
        }

        $orders = wc_get_orders([
            'customer_id' => $userId,
            'limit'       => max(1, min(5, $limit)),
            'orderby'     => 'date',
            'order'       => 'DESC',
            'return'      => 'objects',
        ]);

        if (!is_array($orders)) {
            return [];
        }

        $items = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $dateObj = $order->get_date_created();
            $statusSlug = $order->get_status();

            $items[] = [
                'number'       => $order->get_order_number(),
                'date'         => $dateObj ? wc_format_datetime($dateObj) : '',
                'status_slug'  => $statusSlug,
                'status_label' => wc_get_order_status_name($statusSlug),
                'total_html'   => $order->get_formatted_order_total(),
                'view_url'     => $order->get_view_order_url(),
            ];
        }

        return $items;
    }

    private static function customerOrderCount(int $userId): int
    {
        if (!function_exists('wc_get_customer_order_count')) {
            return 0;
        }

        return max(0, (int) wc_get_customer_order_count($userId));
    }
}
