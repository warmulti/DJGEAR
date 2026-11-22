<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\WooCommerce;

use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Services\SkinOrderService;

/**
 * HPOS-safe WooCommerce integration bridge.
 *
 * Security & Commerce Guarantees:
 * - WooCommerce remains the sole source of truth for base pricing (including sale prices), taxes, coupons,
 *   currency, cart, checkout, payment gateways, and orders (both HPOS and legacy order storage).
 * - Plan surcharges are computed strictly server-side from fresh DB product prices + frozen job snapshot
 *   and are 100% idempotent across repeated `woocommerce_before_calculate_totals` executions.
 * - Each Skin Job cart item is strictly locked to `quantity = 1` (1:1 Order Item <-> Skin Job mapping).
 * - Final checkout boundary re-validates ownership, draft state, plan rules, current artwork revision,
 *   DPI compliance, and SHA-256-bound acknowledgments (blocking post-cart artwork swaps).
 * - Order & Order Item binding executes strictly AFTER `$order->save()` on both Classic Checkout
 *   (`woocommerce_checkout_order_created`) and Checkout Blocks (`woocommerce_store_api_checkout_order_processed`)
 *   so persisted IDs (`> 0`) are guaranteed and multi-skin orders never collide on `0`.
 */
final class WooCommerceSkinBridge
{
    public const CART_ITEM_KEY = 'djgear_skin_job_uuid';
    public const ORDER_ITEM_META_JOB_UUID = '_djgear_skin_job_uuid';

    public function __construct(
        private readonly SkinJobRepository $jobs,
        private readonly SkinOrderService $orderService,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'applyServerSidePlanSurcharge'], 20, 1);
        add_filter('woocommerce_get_item_data', [$this, 'formatCartItemData'], 10, 2);
        add_filter('woocommerce_update_cart_validation', [$this, 'validateCartQuantityUpdate'], 10, 4);
        add_filter('woocommerce_cart_item_quantity', [$this, 'lockCartItemQuantityHtml'], 10, 3);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'clampCartItemQuantity'], 10, 4);
        add_action('woocommerce_check_cart_items', [$this, 'validateCartSkinJobsForCheckout'], 10);
        add_action('woocommerce_checkout_process', [$this, 'validateCartSkinJobsForCheckout'], 10);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'attachLineItemMetadata'], 10, 4);
        add_action('woocommerce_checkout_order_created', [$this, 'bindPersistedOrderJobs'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'bindPersistedOrderJobs'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'onOrderProcessing'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderCancelled'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'onOrderRefunded'], 10, 1);
    }

    /**
     * Compute authoritative, idempotent unit price = fresh DB product price (respecting sale price) + plan surcharge.
     * Repeated calls to `calculate_totals()` NEVER compound prices.
     */
    public function applyServerSidePlanSurcharge(\WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX') && !defined('REST_REQUEST')) {
            return;
        }

        foreach ($cart->get_cart() as $cartItem) {
            if (!is_array($cartItem) || empty($cartItem[self::CART_ITEM_KEY]) || !is_string($cartItem[self::CART_ITEM_KEY])) {
                continue;
            }

            $job = $this->jobs->findByUuid($cartItem[self::CART_ITEM_KEY], false);
            if ($job === null) {
                continue;
            }

            $product = $cartItem['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
            $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
            $surcharge = max(0.0, (float) ($planSnap['price_surcharge'] ?? 0.0));

            if (function_exists('apply_filters')) {
                $filtered = apply_filters('djgear_skins_convert_surcharge', $surcharge, $job);
                if (is_numeric($filtered)) {
                    $surcharge = max(0.0, (float) $filtered);
                }
            }

            // Always read the canonical active base price from a fresh DB WC_Product instance so:
            // 1) Active WooCommerce sale_price is respected (never overcharging regular_price when on sale).
            // 2) Repeated calculate_totals() runs NEVER compound the surcharge on top of mutated cart prices.
            $base = $this->resolveCanonicalBasePrice($product);
            $finalPrice = round($base + $surcharge, 2);
            $product->set_price((string) $finalPrice);
        }
    }

    /**
     * Read canonical active price (sale price when on sale, otherwise regular/active price) from DB.
     */
    public function resolveCanonicalBasePrice(\WC_Product $cartProduct): float
    {
        $productId = $cartProduct->get_id();
        if ($productId > 0 && function_exists('wc_get_product')) {
            $fresh = wc_get_product($productId);
            if ($fresh instanceof \WC_Product) {
                $raw = $fresh->get_price('edit');
                if (is_numeric($raw) && (float) $raw >= 0.0) {
                    return (float) $raw;
                }
                $reg = $fresh->get_regular_price('edit');
                if (is_numeric($reg) && (float) $reg >= 0.0) {
                    return (float) $reg;
                }
            }
        }

        $sale = $cartProduct->get_sale_price('edit');
        if ($cartProduct->is_on_sale() && is_numeric($sale) && (float) $sale >= 0.0) {
            return (float) $sale;
        }
        $reg = $cartProduct->get_regular_price('edit');
        if (is_numeric($reg) && (float) $reg >= 0.0) {
            return (float) $reg;
        }

        return max(0.0, (float) $cartProduct->get_price('edit'));
    }

    /**
     * Prevent quantity updates != 1 on custom skin cart items (enforces strict 1:1 Skin Job mapping).
     *
     * @param array<string, mixed> $values
     */
    public function validateCartQuantityUpdate(bool $passed, string $cartItemKey, array $values, int|float $quantity): bool
    {
        if (empty($values[self::CART_ITEM_KEY])) {
            return $passed;
        }

        if ((int) $quantity !== 1) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    __('Each custom DJ skin configuration is produced individually (quantity must remain 1). Configure an additional skin to order more.', 'djgear-custom-skins'),
                    'error'
                );
            }
            return false;
        }

        return $passed;
    }

    /**
     * Render a locked quantity ("1") control on the Cart page for custom skin items.
     *
     * @param array<string, mixed> $cartItem
     */
    public function lockCartItemQuantityHtml(string $productQuantity, string $cartItemKey, array $cartItem): string
    {
        if (empty($cartItem[self::CART_ITEM_KEY])) {
            return $productQuantity;
        }

        return sprintf(
            '<span>1</span><input type="hidden" name="cart[%s][qty]" value="1" />',
            esc_attr($cartItemKey)
        );
    }

    /**
     * Defensive clamp: if Store API or programmatic cart update sets qty > 1 on a skin job item, restore qty = 1.
     */
    public function clampCartItemQuantity(string $cartItemKey, int|float $quantity, int|float $oldQuantity, \WC_Cart $cart): void
    {
        $item = $cart->get_cart_item($cartItemKey);
        if (empty($item[self::CART_ITEM_KEY])) {
            return;
        }

        if ((int) $quantity > 1) {
            $cart->cart_contents[$cartItemKey]['quantity'] = 1;
        }
    }

    /**
     * Final checkout boundary validation (runs on Classic Checkout & Checkout Blocks / Store API):
     * Re-validates ownership, draft/unbound state, qty=1, no duplicate job UUIDs, plan rules,
     * current artwork revision, DPI compliance, and SHA-256-bound acknowledgments.
     */
    public function validateCartSkinJobsForCheckout(): void
    {
        if (!function_exists('WC')) {
            return;
        }
        /** @var \WC_Cart|null $cart */
        $cart = WC()->cart;
        if ($cart === null) {
            return;
        }

        $seenUuids = [];
        $currentUserId = get_current_user_id();

        foreach ($cart->get_cart() as $cartItem) {
            if (!is_array($cartItem) || empty($cartItem[self::CART_ITEM_KEY]) || !is_string($cartItem[self::CART_ITEM_KEY])) {
                continue;
            }

            $uuid = $cartItem[self::CART_ITEM_KEY];
            if (isset($seenUuids[$uuid])) {
                $this->addCheckoutError(__('Duplicate custom skin job detected in your cart. Please remove duplicates before checkout.', 'djgear-custom-skins'));
                continue;
            }
            $seenUuids[$uuid] = true;

            if ((int) ($cartItem['quantity'] ?? 1) !== 1) {
                $this->addCheckoutError(__('Each custom skin item in your cart must have quantity 1.', 'djgear-custom-skins'));
                continue;
            }

            if ($currentUserId <= 0) {
                $this->addCheckoutError(__('Please sign in before checking out with a custom DJ skin.', 'djgear-custom-skins'));
                continue;
            }

            try {
                $this->orderService->assertReadyForCheckout($uuid, $currentUserId);
            } catch (SkinValidationException $e) {
                $this->addCheckoutError($e->getMessage());
            }
        }
    }

    /**
     * Display clean commercial skin summary rows in WooCommerce Cart & Checkout.
     *
     * @param array<int, array<string, mixed>> $itemData
     * @param array<string, mixed> $cartItem
     * @return array<int, array<string, mixed>>
     */
    public function formatCartItemData(array $itemData, array $cartItem): array
    {
        if (empty($cartItem[self::CART_ITEM_KEY]) || !is_string($cartItem[self::CART_ITEM_KEY])) {
            return $itemData;
        }

        $job = $this->jobs->findByUuid($cartItem[self::CART_ITEM_KEY], false);
        if ($job === null) {
            return $itemData;
        }

        $isRtl = function_exists('is_rtl') && is_rtl();
        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $device = is_array($snapshot['device'] ?? null) ? $snapshot['device'] : [];
        $variant = is_array($snapshot['variant'] ?? null) ? $snapshot['variant'] : [];
        $plan = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];

        $deviceLabel = $isRtl ? (string) ($device['name_fa'] ?? $device['name_en'] ?? '') : (string) ($device['name_en'] ?? $device['name_fa'] ?? '');
        $modelLabel  = $isRtl ? (string) ($variant['model_name_fa'] ?? $variant['model_name_en'] ?? '') : (string) ($variant['model_name_en'] ?? $variant['model_name_fa'] ?? '');
        $colorLabel  = $isRtl ? (string) ($variant['color_name_fa'] ?? $variant['color_name_en'] ?? '') : (string) ($variant['color_name_en'] ?? $variant['color_name_fa'] ?? '');
        $planLabel   = $isRtl ? (string) ($plan['name_fa'] ?? $plan['name_en'] ?? '') : (string) ($plan['name_en'] ?? $plan['name_fa'] ?? '');

        if ($deviceLabel !== '') {
            $itemData[] = ['key' => __('DJ Device', 'djgear-custom-skins'), 'value' => $deviceLabel];
        }
        if ($modelLabel !== '') {
            $itemData[] = ['key' => __('Skin Model', 'djgear-custom-skins'), 'value' => $modelLabel];
        }
        if ($colorLabel !== '') {
            $itemData[] = ['key' => __('Color & Finish', 'djgear-custom-skins'), 'value' => $colorLabel];
        }
        if ($planLabel !== '') {
            $itemData[] = ['key' => __('Ordering Plan', 'djgear-custom-skins'), 'value' => $planLabel];
        }

        return $itemData;
    }

    /**
     * Attach clean commercial metadata to the order item during order creation.
     * Note: Does NOT bind `wc_order_id`/`wc_order_item_id` yet because `$order->save()` has not yet assigned IDs!
     *
     * @param array<string, mixed> $values
     */
    public function attachLineItemMetadata(\WC_Order_Item_Product $item, string $cartItemKey, array $values, \WC_Order $order): void
    {
        if (empty($values[self::CART_ITEM_KEY]) || !is_string($values[self::CART_ITEM_KEY])) {
            return;
        }

        $jobUuid = $values[self::CART_ITEM_KEY];
        $job = $this->jobs->findByUuid($jobUuid, false);
        if ($job === null) {
            return;
        }

        $this->attachJobMetadataToOrderItem($item, $job);
    }

    /**
     * Bind all Skin Jobs on a persisted WooCommerce Order AFTER `$order->save()` (Classic & Blocks).
     * Guarantees `$order->get_id() > 0` and `$item->get_id() > 0` and prevents zero-ID collisions on multi-skin orders.
     */
    public function bindPersistedOrderJobs(\WC_Order $order): void
    {
        $orderId = $order->get_id();
        if ($orderId <= 0) {
            return;
        }

        $orderCustomerId = (int) $order->get_customer_id();

        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $persistedItemId = $item->get_id() > 0 ? $item->get_id() : (int) $itemId;
            if ($persistedItemId <= 0) {
                continue;
            }

            $jobUuid = $item->get_meta(self::ORDER_ITEM_META_JOB_UUID, true);
            if (!is_string($jobUuid) || $jobUuid === '') {
                continue;
            }

            $job = $this->jobs->findByUuid($jobUuid, false);
            if ($job === null) {
                continue;
            }

            $this->linkJobToOrder((int) $job['id'], $orderId, $persistedItemId, $orderCustomerId > 0 ? $orderCustomerId : null);
        }
    }

    /**
     * Programmatic helper to attach clean commercial metadata to a WooCommerce order item.
     *
     * @param array<string, mixed> $job
     */
    public function attachJobMetadataToOrderItem(\WC_Order_Item_Product $item, array $job): void
    {
        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $device = is_array($snapshot['device'] ?? null) ? $snapshot['device'] : [];
        $variant = is_array($snapshot['variant'] ?? null) ? $snapshot['variant'] : [];
        $plan = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];

        $item->add_meta_data(self::ORDER_ITEM_META_JOB_UUID, (string) $job['job_uuid'], true);
        $item->add_meta_data(__('DJ Device', 'djgear-custom-skins'), (string) ($device['name_en'] ?? ''), true);
        $item->add_meta_data(__('Skin Model', 'djgear-custom-skins'), (string) ($variant['model_name_en'] ?? ''), true);
        $item->add_meta_data(__('Color', 'djgear-custom-skins'), (string) ($variant['color_name_en'] ?? ''), true);
        $item->add_meta_data(__('Ordering Plan', 'djgear-custom-skins'), (string) ($plan['name_en'] ?? ''), true);
    }

    /**
     * Atomically bind a draft Skin Job to a persisted WooCommerce order & line item (`draft -> awaiting_payment`).
     * Idempotent on replay with identical IDs; blocks zero IDs and conflicting re-bindings.
     */
    public function linkJobToOrder(int $jobId, int $wcOrderId, int $wcOrderItemId, ?int $expectedCustomerId = null): bool
    {
        return $this->jobs->bindWooCommerceOrderItemIfDraft($jobId, $wcOrderId, $wcOrderItemId, $expectedCustomerId);
    }

    public function onPaymentComplete(int $orderId): void
    {
        $this->orderService->handleOrderPaid($orderId);
    }

    public function onOrderProcessing(int $orderId): void
    {
        $this->orderService->handleOrderPaid($orderId);
    }

    public function onOrderCancelled(int $orderId): void
    {
        $this->orderService->handleOrderCancelledOrRefunded($orderId, 'cancelled');
    }

    public function onOrderRefunded(int $orderId): void
    {
        $this->orderService->handleOrderCancelledOrRefunded($orderId, 'refunded');
    }

    private function addCheckoutError(string $message): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }
    }
}
