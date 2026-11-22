<?php
/**
 * Plugin Name: DJGear Dev Mailpit Router (MU-Plugin)
 * Description: Routes all local development WordPress/WooCommerce emails through Mailpit SMTP.
 *              Strictly disabled outside `development` environments.
 * Author: DJGear.ir Engineering
 * Version: 1.0.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'development') {
    $djgearFromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@djgear.local';
    $djgearFromName    = getenv('MAIL_FROM_NAME') ?: 'DJGear Local';

    // Ensure valid From address for WP-CLI, WP-Cron, and WooCommerce ActionScheduler jobs
    add_filter('wp_mail_from', static function (string $from) use ($djgearFromAddress): string {
        if (empty($from) || str_starts_with($from, 'wordpress@') || !str_contains($from, '@')) {
            return $djgearFromAddress;
        }
        return $from;
    }, 10, 1);

    add_filter('wp_mail_from_name', static function (string $name) use ($djgearFromName): string {
        if (empty($name) || $name === 'WordPress') {
            return $djgearFromName;
        }
        return $name;
    }, 10, 1);

    add_action('phpmailer_init', static function ($phpmailer) use ($djgearFromAddress, $djgearFromName): void {
        $host = getenv('MAIL_HOST') ?: 'mailpit';
        $port = (int) (getenv('MAIL_PORT') ?: 1025);

        $phpmailer->isSMTP();
        $phpmailer->Host        = $host;
        $phpmailer->Port        = $port;
        $phpmailer->SMTPAuth    = false;
        $phpmailer->SMTPSecure  = '';
        $phpmailer->SMTPAutoTLS = false;

        if (empty($phpmailer->From) || str_contains((string) $phpmailer->From, 'wordpress@')) {
            $phpmailer->setFrom($djgearFromAddress, $djgearFromName, false);
        }
    }, 10, 1);
}
