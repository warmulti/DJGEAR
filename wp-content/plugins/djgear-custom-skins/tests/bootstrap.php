<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists('wpdb', false)) {
    class wpdb
    {
        public string $prefix = 'wp_';
    }
}

foreach (['djgear-core', 'djgear-device-catalog'] as $dep) {
    $path = dirname(__DIR__, 2) . '/' . $dep . '/vendor/autoload.php';
    if (is_readable($path)) {
        require_once $path;
    }
}
