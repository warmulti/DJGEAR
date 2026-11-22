<?php

declare(strict_types=1);

/**
 * DJGear Theme bootstrap.
 *
 * Strictly presentation & WordPress/WooCommerce theme integration only.
 * Zero commerce business logic — all domain logic lives in WordPress, WooCommerce,
 * and the independent DJGear plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DJGEAR_THEME_VERSION', '0.1.0');
define('DJGEAR_THEME_DIR', get_template_directory());
define('DJGEAR_THEME_URI', get_template_directory_uri());

spl_autoload_register(static function (string $class): void {
    $prefix = 'DJGear\\Theme\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = DJGEAR_THEME_DIR . '/inc/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

DJGear\Theme\Theme::instance()->boot();
