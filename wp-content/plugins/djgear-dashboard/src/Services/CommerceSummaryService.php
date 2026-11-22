<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Services;

use WC_Order;
use WC_Product;
use WP_User;
use WP_User_Query;

/**
 * HPOS-safe read-only adapter over WordPress and WooCommerce commerce entities.
 *
 * WordPress and WooCommerce remain the sole source of truth for products, orders, and customers.
 */
final class CommerceSummaryService
{
    public function isWooCommerceAvailable(): bool
    {
        return class_exists('WooCommerce')
            && function_exists('wc_get_products')
            && function_exists('wc_get_orders');
    }

    /**
     * Collect real top-level catalog, order, and user counts without duplicating data.
     *
     * @return array<string, mixed>
     */
    public function overviewMetrics(): array
    {
        $userCounts = function_exists('count_users') ? count_users() : ['total_users' => 0, 'avail_roles' => []];
        $totalUsers = (int) $userCounts['total_users'];
        $customerCount = (int) ($userCounts['avail_roles']['customer'] ?? 0);

        if (!$this->isWooCommerceAvailable()) {
            return [
                'woocommerce_active' => false,
                'currency'           => '',
                'products_total'     => 0,
                'products_published' => 0,
                'orders_total'       => 0,
                'orders_processing'  => 0,
                'orders_completed'   => 0,
                'customers_total'    => $customerCount,
                'users_total'        => $totalUsers,
            ];
        }

        $productCounts = wp_count_posts('product');
        $publishedProducts = (int) ($productCounts->publish ?? 0);
        $draftProducts = (int) ($productCounts->draft ?? 0);
        $pendingProducts = (int) ($productCounts->pending ?? 0);
        $privateProducts = (int) ($productCounts->private ?? 0);
        $totalProducts = $publishedProducts + $draftProducts + $pendingProducts + $privateProducts;

        $processingOrders = $this->countOrdersByStatus('wc-processing');
        $completedOrders = $this->countOrdersByStatus('wc-completed');
        $onHoldOrders = $this->countOrdersByStatus('wc-on-hold');
        $pendingOrders = $this->countOrdersByStatus('wc-pending');
        $cancelledOrders = $this->countOrdersByStatus('wc-cancelled');
        $refundedOrders = $this->countOrdersByStatus('wc-refunded');
        $failedOrders = $this->countOrdersByStatus('wc-failed');

        $totalOrders = $processingOrders + $completedOrders + $onHoldOrders + $pendingOrders + $cancelledOrders + $refundedOrders + $failedOrders;

        return [
            'woocommerce_active' => true,
            'currency'           => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'IRR',
            'products_total'     => $totalProducts,
            'products_published' => $publishedProducts,
            'orders_total'       => $totalOrders,
            'orders_processing'  => $processingOrders,
            'orders_completed'   => $completedOrders,
            'customers_total'    => $customerCount,
            'users_total'        => $totalUsers,
        ];
    }

    /**
     * Paginated WooCommerce products list using wc_get_products().
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, currency: string}
     */
    public function listProducts(int $page = 1, int $perPage = 10, string $search = '', string $status = ''): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, min(50, $perPage));
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';

        if (!$this->isWooCommerceAvailable()) {
            return [
                'items'    => [],
                'total'    => 0,
                'page'     => $safePage,
                'per_page' => $safePerPage,
                'currency' => $currency,
            ];
        }

        $allowedStatuses = ['publish', 'draft', 'pending', 'private'];
        $queryStatus = in_array($status, $allowedStatuses, true) ? [$status] : $allowedStatuses;

        $args = [
            'limit'    => $safePerPage,
            'page'     => $safePage,
            'status'   => $queryStatus,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }

        $result = wc_get_products($args);
        $rawProducts = is_object($result) && isset($result->products) && is_array($result->products)
            ? $result->products
            : [];
        $total = is_object($result) && isset($result->total) ? (int) $result->total : count($rawProducts);

        $items = [];
        foreach ($rawProducts as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            $id = $product->get_id();
            $items[] = [
                'id'             => $id,
                'name'           => $product->get_name(),
                'sku'            => $product->get_sku(),
                'type'           => $product->get_type(),
                'status'         => $product->get_status(),
                'price'          => $product->get_price(),
                'regular_price'  => $product->get_regular_price(),
                'stock_status'   => $product->get_stock_status(),
                'stock_quantity' => $product->get_stock_quantity(),
                'edit_url'       => esc_url_raw(admin_url('post.php?post=' . $id . '&action=edit')),
            ];
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $safePage,
            'per_page' => $safePerPage,
            'currency' => $currency,
        ];
    }

    /**
     * Paginated HPOS-safe WooCommerce orders list using wc_get_orders().
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listOrders(int $page = 1, int $perPage = 10, string $status = ''): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, min(50, $perPage));

        if (!$this->isWooCommerceAvailable()) {
            return [
                'items'    => [],
                'total'    => 0,
                'page'     => $safePage,
                'per_page' => $safePerPage,
            ];
        }

        $args = [
            'limit'    => $safePerPage,
            'page'     => $safePage,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'type'     => 'shop_order',
        ];

        $allowedStatuses = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];
        if (in_array($status, $allowedStatuses, true)) {
            $args['status'] = 'wc-' . $status;
        }

        $result = wc_get_orders($args);
        $rawOrders = is_object($result) && isset($result->orders) && is_array($result->orders)
            ? $result->orders
            : [];
        $total = is_object($result) && isset($result->total) ? (int) $result->total : count($rawOrders);

        $items = [];
        foreach ($rawOrders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $dateCreated = $order->get_date_created();
            $customerName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            if ($customerName === '') {
                $customerName = $order->get_billing_email() ?: 'Guest';
            }

            $items[] = [
                'id'            => $order->get_id(),
                'order_number'  => $order->get_order_number(),
                'status'        => $order->get_status(),
                'total'         => $order->get_total(),
                'currency'      => $order->get_currency(),
                'customer_name' => $customerName,
                'customer_email' => $order->get_billing_email(),
                'item_count'    => $order->get_item_count(),
                'created_at'    => $dateCreated !== null ? $dateCreated->date('c') : null,
                'edit_url'      => esc_url_raw($order->get_edit_order_url()),
            ];
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $safePage,
            'per_page' => $safePerPage,
        ];
    }

    /**
     * Paginated WordPress/WooCommerce users & customers directory (no duplicated user table).
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listCustomers(int $page = 1, int $perPage = 10, string $search = ''): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, min(50, $perPage));

        $args = [
            'number'      => $safePerPage,
            'paged'       => $safePage,
            'orderby'     => 'registered',
            'order'       => 'DESC',
            'count_total' => true,
        ];

        if ($search !== '') {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query = new WP_User_Query($args);
        $users = $query->get_results();
        $total = (int) $query->get_total();

        $items = [];
        foreach ($users as $user) {
            if (!$user instanceof WP_User) {
                continue;
            }
            $userId = (int) $user->ID;
            $orderCount = function_exists('wc_get_customer_order_count')
                ? (int) wc_get_customer_order_count($userId)
                : 0;
            $totalSpent = function_exists('wc_get_customer_total_spent')
                ? (string) wc_get_customer_total_spent($userId)
                : '0';

            $items[] = [
                'id'           => $userId,
                'display_name' => (string) $user->display_name,
                'email'        => (string) $user->user_email,
                'roles'        => array_values($user->roles),
                'registered'   => (string) $user->user_registered,
                'order_count'  => $orderCount,
                'total_spent'  => $totalSpent,
                'edit_url'     => esc_url_raw(admin_url('user-edit.php?user_id=' . $userId)),
            ];
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $safePage,
            'per_page' => $safePerPage,
        ];
    }

    private function countOrdersByStatus(string $status): int
    {
        if (function_exists('wc_orders_count')) {
            return (int) wc_orders_count(str_replace('wc-', '', $status));
        }

        return 0;
    }
}
