<?php
/**
 * Plugin Name:       DJGear Core
 * Plugin URI:        https://djgear.ir
 * Description:       Modular foundation plugin for DJGear.ir: PSR-4 autoloading, service container, module lifecycle, i18n/RTL, djgear/v1 REST API, capabilities, structured/audit logging, and provider contracts.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            DJGear.ir Engineering
 * Text Domain:       djgear-core
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.3.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DJGEAR_CORE_FILE', __FILE__);
define('DJGEAR_CORE_DIR', __DIR__);
define('DJGEAR_CORE_VERSION', '0.1.0');

$djgearAutoloader = __DIR__ . '/vendor/autoload.php';
if (!is_readable($djgearAutoloader)) {
    if (is_admin()) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('DJGear Core requires Composer dependencies. Run `composer install` in wp-content/plugins/djgear-core.', 'djgear-core');
            echo '</p></div>';
        });
    }
    return;
}

require_once $djgearAutoloader;

register_activation_hook(__FILE__, [\DJGear\Core\Lifecycle\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\DJGear\Core\Lifecycle\Deactivator::class, 'deactivate']);

/**
 * Global accessor for the DJGear Core kernel.
 */
function djgear_core(): \DJGear\Core\Plugin
{
    return \DJGear\Core\Plugin::instance(__FILE__);
}

djgear_core()->boot();
