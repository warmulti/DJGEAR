<?php
/**
 * Plugin Name:       DJGear Dashboard
 * Plugin URI:        https://djgear.ir
 * Description:       Modern dark-navy administrative dashboard for DJGear.ir built with React, TypeScript, and Vite. Integrates via djgear-core contracts alongside native wp-admin.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            DJGear.ir Engineering
 * Text Domain:       djgear-dashboard
 * WC requires at least: 9.0
 * WC tested up to:   11.3.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DJGEAR_DASHBOARD_FILE', __FILE__);
define('DJGEAR_DASHBOARD_DIR', __DIR__);
define('DJGEAR_DASHBOARD_VERSION', '0.1.0');

$djgearDashboardAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($djgearDashboardAutoload)) {
    if (is_admin()) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('DJGear Dashboard requires Composer dependencies. Run `composer install` in wp-content/plugins/djgear-dashboard.', 'djgear-dashboard');
            echo '</p></div>';
        });
    }
    return;
}

require_once $djgearDashboardAutoload;

// Declare WooCommerce HPOS & Blocks compatibility
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Register cleanly as a djgear-core module
add_action('djgear_core_register_modules', static function (\DJGear\Core\Modules\ModuleRegistry $registry): void {
    $registry->register(new \DJGear\Dashboard\DashboardModule(__FILE__));
});

// Graceful admin notice if djgear-core is deactivated
add_action('admin_notices', static function (): void {
    if (!function_exists('djgear_core')) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('DJGear Dashboard requires the DJGear Core plugin to be active.', 'djgear-dashboard');
        echo '</p></div>';
    }
});
