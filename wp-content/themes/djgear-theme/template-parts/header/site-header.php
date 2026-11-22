<?php

declare(strict_types=1);

use DJGear\Theme\Setup\Menus;
use DJGear\Theme\Template\Icons;
use DJGear\Theme\WooCommerce\WooCommerceSetup;

if (!defined('ABSPATH')) {
    exit;
}

$hasWc = class_exists('WooCommerce');
$accountUrl = $hasWc && function_exists('wc_get_page_permalink')
    ? wc_get_page_permalink('myaccount')
    : wp_login_url();
$cartUrl = $hasWc && function_exists('wc_get_cart_url')
    ? wc_get_cart_url()
    : home_url('/cart/');
$cartCount = $hasWc ? WooCommerceSetup::cartItemCount() : 0;
?>
<header class="djg-header" data-djg-header>
    <div class="djg-container djg-header__inner">
        <div class="djg-header__start">
            <button
                type="button"
                class="djg-icon-btn djg-header__menu-toggle"
                data-djg-drawer-open="mobile-nav"
                aria-controls="djg-mobile-drawer"
                aria-expanded="false"
                aria-label="<?php esc_attr_e('Open navigation menu', 'djgear-theme'); ?>"
            >
                <?php Icons::render('menu', 22); ?>
            </button>

            <a class="djg-brand" href="<?php echo esc_url(home_url('/')); ?>" rel="home">
                <?php if (has_custom_logo()) : ?>
                    <span class="djg-brand__logo"><?php echo wp_kses_post((string) get_custom_logo()); ?></span>
                <?php else : ?>
                    <span class="djg-brand__mark" aria-hidden="true">DJ</span>
                    <span class="djg-brand__text">
                        <span class="djg-brand__name"><?php echo esc_html(get_bloginfo('name') ?: 'DJGear'); ?></span>
                        <span class="djg-brand__tagline"><?php esc_html_e('Pro DJ Gear & Custom Skins', 'djgear-theme'); ?></span>
                    </span>
                <?php endif; ?>
            </a>
        </div>

        <nav class="djg-nav" aria-label="<?php esc_attr_e('Primary Navigation', 'djgear-theme'); ?>">
            <?php if (has_nav_menu(Menus::PRIMARY)) : ?>
                <?php
                wp_nav_menu([
                    'theme_location' => Menus::PRIMARY,
                    'container'      => false,
                    'menu_class'     => 'djg-nav__list',
                    'depth'          => 2,
                    'fallback_cb'    => false,
                ]);
                ?>
            <?php else : ?>
                <ul class="djg-nav__list">
                    <?php foreach (Menus::defaultPrimaryItems() as $item) : ?>
                        <li class="djg-nav__item">
                            <a
                                class="djg-nav__link<?php echo $item['current'] ? ' is-current' : ''; ?>"
                                href="<?php echo esc_url($item['url']); ?>"
                                <?php echo $item['current'] ? 'aria-current="page"' : ''; ?>
                            >
                                <?php echo esc_html($item['label']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </nav>

        <div class="djg-header__actions">
            <button
                type="button"
                class="djg-icon-btn"
                data-djg-overlay-open="search"
                aria-controls="djg-search-overlay"
                aria-expanded="false"
                aria-label="<?php esc_attr_e('Open product search', 'djgear-theme'); ?>"
            >
                <?php Icons::render('search', 20); ?>
            </button>

            <a
                class="djg-icon-btn"
                href="<?php echo esc_url((string) $accountUrl); ?>"
                aria-label="<?php esc_attr_e('My Account', 'djgear-theme'); ?>"
            >
                <?php Icons::render('user', 20); ?>
            </a>

            <?php if ($hasWc) : ?>
                <button
                    type="button"
                    class="djg-icon-btn djg-cart-trigger"
                    data-djg-drawer-open="mini-cart"
                    data-cart-url="<?php echo esc_url((string) $cartUrl); ?>"
                    aria-controls="djg-mini-cart-drawer"
                    aria-expanded="false"
                    aria-label="<?php esc_attr_e('Open shopping cart', 'djgear-theme'); ?>"
                >
                    <?php Icons::render('cart', 20); ?>
                    <?php echo WooCommerceSetup::renderCartBadgeHtml($cartCount); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</header>
