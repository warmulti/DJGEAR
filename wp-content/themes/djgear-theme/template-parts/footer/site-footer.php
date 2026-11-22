<?php

declare(strict_types=1);

use DJGear\Theme\Setup\Menus;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$shopUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
$accountUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
?>
<footer class="djg-footer" role="contentinfo">
    <div class="djg-container">
        <div class="djg-footer__grid">
            <div class="djg-footer__brand">
                <a class="djg-brand" href="<?php echo esc_url(home_url('/')); ?>" rel="home">
                    <span class="djg-brand__mark" aria-hidden="true">DJ</span>
                    <span class="djg-brand__text">
                        <span class="djg-brand__name"><?php echo esc_html(get_bloginfo('name') ?: 'DJGear'); ?></span>
                        <span class="djg-brand__tagline"><?php esc_html_e('Pro DJ Equipment & Custom Skins', 'djgear-theme'); ?></span>
                    </span>
                </a>
                <p class="djg-footer__summary">
                    <?php esc_html_e('Authentic professional DJ hardware, studio essentials, and precision custom equipment skins backed by transparent order tracking.', 'djgear-theme'); ?>
                </p>
                <ul class="djg-trust-list" aria-label="<?php esc_attr_e('Store Guarantees', 'djgear-theme'); ?>">
                    <li class="djg-trust-list__item">
                        <?php Icons::render('shield', 16); ?>
                        <span><?php esc_html_e('Verified Original Gear', 'djgear-theme'); ?></span>
                    </li>
                    <li class="djg-trust-list__item">
                        <?php Icons::render('truck', 16); ?>
                        <span><?php esc_html_e('Tracked Nationwide Delivery', 'djgear-theme'); ?></span>
                    </li>
                </ul>
            </div>

            <div class="djg-footer__col">
                <h2 class="djg-footer__heading"><?php esc_html_e('Shop & Gear', 'djgear-theme'); ?></h2>
                <?php if (has_nav_menu(Menus::FOOTER_SHOP)) : ?>
                    <?php
                    wp_nav_menu([
                        'theme_location' => Menus::FOOTER_SHOP,
                        'container'      => false,
                        'menu_class'     => 'djg-footer__links',
                        'depth'          => 1,
                        'fallback_cb'    => false,
                    ]);
                    ?>
                <?php else : ?>
                    <ul class="djg-footer__links">
                        <li><a href="<?php echo esc_url((string) $shopUrl); ?>"><?php esc_html_e('All Products', 'djgear-theme'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'djgear-theme'); ?></a></li>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="djg-footer__col">
                <h2 class="djg-footer__heading"><?php esc_html_e('Customer Care', 'djgear-theme'); ?></h2>
                <?php if (has_nav_menu(Menus::FOOTER_SUPPORT)) : ?>
                    <?php
                    wp_nav_menu([
                        'theme_location' => Menus::FOOTER_SUPPORT,
                        'container'      => false,
                        'menu_class'     => 'djg-footer__links',
                        'depth'          => 1,
                        'fallback_cb'    => false,
                    ]);
                    ?>
                <?php else : ?>
                    <ul class="djg-footer__links">
                        <li><a href="<?php echo esc_url((string) $accountUrl); ?>"><?php esc_html_e('My Account & Orders', 'djgear-theme'); ?></a></li>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="djg-footer__col">
                <h2 class="djg-footer__heading"><?php esc_html_e('Policies', 'djgear-theme'); ?></h2>
                <?php if (has_nav_menu(Menus::FOOTER_LEGAL)) : ?>
                    <?php
                    wp_nav_menu([
                        'theme_location' => Menus::FOOTER_LEGAL,
                        'container'      => false,
                        'menu_class'     => 'djg-footer__links',
                        'depth'          => 1,
                        'fallback_cb'    => false,
                    ]);
                    ?>
                <?php else : ?>
                    <ul class="djg-footer__links">
                        <?php
                        $privacyUrl = get_privacy_policy_url();
                        if (is_string($privacyUrl) && $privacyUrl !== '') :
                            ?>
                            <li><a href="<?php echo esc_url($privacyUrl); ?>"><?php esc_html_e('Privacy Policy', 'djgear-theme'); ?></a></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="djg-footer__bottom">
            <p class="djg-footer__copyright">
                <?php
                printf(
                    /* translators: 1: current year, 2: site name */
                    esc_html__('© %1$s %2$s. All rights reserved.', 'djgear-theme'),
                    esc_html(gmdate('Y')),
                    esc_html(get_bloginfo('name') ?: 'DJGear')
                );
                ?>
            </p>
        </div>
    </div>
</footer>
