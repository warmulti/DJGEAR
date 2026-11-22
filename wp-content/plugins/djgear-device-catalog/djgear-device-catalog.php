<?php
/**
 * Plugin Name:       DJGear Device Catalog
 * Plugin URI:        https://djgear.ir
 * Description:       Specialized DJ hardware device catalog, bilingual specifications, WooCommerce compatibility graph, and custom-skin engineering blueprint definitions.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            DJGear.ir Engineering
 * Text Domain:       djgear-device-catalog
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.3.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DJGEAR_CATALOG_FILE', __FILE__);
define('DJGEAR_CATALOG_DIR', __DIR__);
define('DJGEAR_CATALOG_VERSION', '0.1.0');

$djgearCatalogAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($djgearCatalogAutoload)) {
    if (is_admin()) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('DJGear Device Catalog requires Composer dependencies. Run `composer install` in wp-content/plugins/djgear-device-catalog.', 'djgear-device-catalog');
            echo '</p></div>';
        });
    }
    return;
}

require_once $djgearCatalogAutoload;

register_activation_hook(__FILE__, [\DJGear\DeviceCatalog\Lifecycle\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\DJGear\DeviceCatalog\Lifecycle\Deactivator::class, 'deactivate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('djgear_core_register_modules', static function (\DJGear\Core\Modules\ModuleRegistry $registry): void {
    $registry->register(new \DJGear\DeviceCatalog\DeviceCatalogModule(__FILE__));
});

add_action('admin_notices', static function (): void {
    if (!function_exists('djgear_core')) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('DJGear Device Catalog requires the DJGear Core plugin to be active.', 'djgear-device-catalog');
        echo '</p></div>';
    }
});
