<?php

declare(strict_types=1);

use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH') || !class_exists('WooCommerce')) {
    exit;
}

$cartUrl = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
$checkoutUrl = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
?>
<div
    class="djg-drawer djg-drawer--end"
    id="djg-mini-cart-drawer"
    data-djg-drawer="mini-cart"
    role="dialog"
    aria-modal="true"
    aria-labelledby="djg-mini-cart-title"
    hidden
>
    <div class="djg-drawer__backdrop" data-djg-drawer-close></div>
    <div class="djg-drawer__panel">
        <div class="djg-drawer__header">
            <span class="djg-drawer__title" id="djg-mini-cart-title">
                <?php esc_html_e('Shopping Cart', 'djgear-theme'); ?>
            </span>
            <button
                type="button"
                class="djg-icon-btn"
                data-djg-drawer-close
                aria-label="<?php esc_attr_e('Close shopping cart', 'djgear-theme'); ?>"
            >
                <?php Icons::render('close', 20); ?>
            </button>
        </div>

        <div class="djg-drawer__body djg-mini-cart-content widget_shopping_cart_content">
            <?php
            if (function_exists('woocommerce_mini_cart')) {
                woocommerce_mini_cart();
            }
            ?>
        </div>

        <div class="djg-drawer__footer">
            <a class="djg-btn djg-btn--secondary djg-btn--block" href="<?php echo esc_url((string) $cartUrl); ?>">
                <?php esc_html_e('View Cart', 'djgear-theme'); ?>
            </a>
            <a class="djg-btn djg-btn--primary djg-btn--block" href="<?php echo esc_url((string) $checkoutUrl); ?>">
                <?php esc_html_e('Checkout', 'djgear-theme'); ?>
            </a>
        </div>
    </div>
</div>
