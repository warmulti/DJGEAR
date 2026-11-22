<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Services;

use WC_Product;

/**
 * HPOS-safe batched WooCommerce product hydrator (read-only reference resolver; zero product duplication).
 */
final class WooCommerceProductHydrator
{
    public function productExists(int $productId): bool
    {
        if (!function_exists('wc_get_product')) {
            return false;
        }

        return wc_get_product($productId) instanceof WC_Product;
    }

    public function findIdBySku(string $sku): ?int
    {
        if ($sku === '' || !function_exists('wc_get_product_id_by_sku')) {
            return null;
        }

        $id = (int) wc_get_product_id_by_sku($sku);

        return $id > 0 ? $id : null;
    }

    /**
     * Hydrate a map of WooCommerce product ID => live product summary in a single batched query.
     *
     * @param list<int> $productIds
     * @return array<int, array<string, mixed>>
     */
    public function hydrateMany(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !function_exists('wc_get_products')) {
            return [];
        }

        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $products = wc_get_products([
            'include' => $ids,
            'limit'   => count($ids),
            'status'  => ['publish', 'draft', 'pending', 'private'],
        ]);

        if (!is_array($products)) {
            return [];
        }

        $map = [];
        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            $id = $product->get_id();
            $map[$id] = [
                'id'           => $id,
                'name'         => $product->get_name(),
                'sku'          => $product->get_sku(),
                'status'       => $product->get_status(),
                'price'        => $product->get_price(),
                'currency'     => $currency,
                'stock_status' => $product->get_stock_status(),
                'permalink'    => esc_url_raw($product->get_permalink()),
                'edit_url'     => esc_url_raw(admin_url('post.php?post=' . $id . '&action=edit')),
            ];
        }

        return $map;
    }
}
