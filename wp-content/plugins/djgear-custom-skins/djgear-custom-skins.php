<?php
/**
 * Plugin Name:       DJGear Custom Skins
 * Plugin URI:        https://djgear.ir
 * Description:       Commercial DJ equipment skin ordering system with device/model/color variants, Standard & Custom-Design plans, authenticated artwork DPI validation, WooCommerce checkout integration, and production tracking.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            DJGear.ir Engineering
 * Text Domain:       djgear-custom-skins
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.3.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DJGEAR_SKINS_FILE', __FILE__);
define('DJGEAR_SKINS_DIR', __DIR__);
define('DJGEAR_SKINS_VERSION', '0.1.0');

$djgearSkinsAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($djgearSkinsAutoload)) {
    if (is_admin()) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('DJGear Custom Skins requires Composer dependencies. Run `composer install` in wp-content/plugins/djgear-custom-skins.', 'djgear-custom-skins');
            echo '</p></div>';
        });
    }
    return;
}

require_once $djgearSkinsAutoload;

register_activation_hook(__FILE__, [\DJGear\CustomSkins\Lifecycle\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\DJGear\CustomSkins\Lifecycle\Deactivator::class, 'deactivate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('djgear_core_register_modules', static function (\DJGear\Core\Modules\ModuleRegistry $registry): void {
    $registry->register(new \DJGear\CustomSkins\CustomSkinsModule(__FILE__));
});

add_action('admin_notices', static function (): void {
    if (!function_exists('djgear_core') || !class_exists(\DJGear\DeviceCatalog\DeviceCatalogModule::class)) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('DJGear Custom Skins requires DJGear Core and DJGear Device Catalog plugins to be active.', 'djgear-custom-skins');
        echo '</p></div>';
    }
});
