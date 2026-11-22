<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\WooCommerce;

use DJGear\CustomSkins\Domain\SkinJobStateMachine;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Services\SkinOrderService;

/**
 * HPOS-safe WooCommerce integration bridge.
 *
 * Guarantees:
 * - WooCommerce remains the sole source of truth for base pricing, cart, checkout, taxes, coupons, and payments.
 * - Plan price surcharge is applied strictly server-side from the frozen job snapshot (never trusted from client).
 * - Checkout line items store clean commercial metadata (Device, Skin Model, Color, Plan, Job UUID — zero engineering terms).
 * - Payment completion & cancellation/refund hooks drive the dual-track SkinJobStateMachine automatically.
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
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'bindOrderLineItem'], 10, 4);
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'onOrderProcessing'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderCancelled'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'onOrderRefunded'], 10, 1);
    }

    /**
     * Apply authoritative server-side plan surcharge to WooCommerce cart items linked to a skin job.
     */
    public function applyServerSidePlanSurcharge(\WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
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
            $surcharge = (float) ($planSnap['price_surcharge'] ?? 0.0);

            if ($surcharge > 0.0) {
                $base = (float) $product->get_regular_price();
                if ($base <= 0.0) {
                    $base = (float) $product->get_price();
                }
                $product->set_price((string) round($base + $surcharge, 2));
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
     * Bind a skin job to the newly created HPOS WooCommerce Order Line Item and transition `draft` -> `awaiting_payment`.
     *
     * @param array<string, mixed> $values
     */
    public function bindOrderLineItem(\WC_Order_Item_Product $item, string $cartItemKey, array $values, \WC_Order $order): void
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
        $item->save();

        $this->linkJobToOrder((int) $job['id'], $order->get_id(), $item->get_id());
    }

    /**
     * Programmatic helper to attach clean commercial metadata to a WooCommerce order item (also used in HPOS tests).
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
     * Link a skin job to a WooCommerce order & line item and move `draft` -> `awaiting_payment`.
     */
    public function linkJobToOrder(int $jobId, int $wcOrderId, int $wcOrderItemId): void
    {
        $this->jobs->bindWooCommerceOrderItem($jobId, $wcOrderId, $wcOrderItemId);

        $job = $this->jobs->findById($jobId, false);
        if ($job !== null && (string) $job['status'] === SkinJobStateMachine::STATUS_DRAFT) {
            $this->jobs->transitionStatus(
                $jobId,
                SkinJobStateMachine::STATUS_AWAITING_PAYMENT,
                (int) $job['customer_user_id'],
                'system',
                true,
                sprintf('Linked to WooCommerce order #%d.', $wcOrderId)
            );
        }
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
}
