<?php

declare(strict_types=1);

use DJGear\Theme\Setup\Menus;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$drawerMenuLocation = has_nav_menu(Menus::MOBILE) ? Menus::MOBILE : Menus::PRIMARY;
?>
<div
    class="djg-drawer djg-drawer--start"
    id="djg-mobile-drawer"
    data-djg-drawer="mobile-nav"
    role="dialog"
    aria-modal="true"
    aria-labelledby="djg-mobile-drawer-title"
    hidden
>
    <div class="djg-drawer__backdrop" data-djg-drawer-close></div>
    <div class="djg-drawer__panel">
        <div class="djg-drawer__header">
            <span class="djg-drawer__title" id="djg-mobile-drawer-title">
                <?php esc_html_e('Navigation', 'djgear-theme'); ?>
            </span>
            <button
                type="button"
                class="djg-icon-btn"
                data-djg-drawer-close
                aria-label="<?php esc_attr_e('Close navigation menu', 'djgear-theme'); ?>"
            >
                <?php Icons::render('close', 20); ?>
            </button>
        </div>

        <nav class="djg-drawer__body" aria-label="<?php esc_attr_e('Mobile Navigation', 'djgear-theme'); ?>">
            <?php if (has_nav_menu($drawerMenuLocation)) : ?>
                <?php
                wp_nav_menu([
                    'theme_location' => $drawerMenuLocation,
                    'container'      => false,
                    'menu_class'     => 'djg-mobile-nav__list',
                    'depth'          => 2,
                    'fallback_cb'    => false,
                ]);
                ?>
            <?php else : ?>
                <ul class="djg-mobile-nav__list">
                    <?php foreach (Menus::defaultPrimaryItems() as $item) : ?>
                        <li>
                            <a
                                class="djg-mobile-nav__link<?php echo $item['current'] ? ' is-current' : ''; ?>"
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
    </div>
</div>
