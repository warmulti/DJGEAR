<?php

declare(strict_types=1);

namespace DJGear\Theme\Security;

/**
 * Lightweight frontend hardening & performance head hygiene.
 */
final class Hardening
{
    public function register(): void
    {
        // Remove WordPress version fingerprint from public HTML & feeds
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', static fn (): string => '');

        // Remove unnecessary legacy discovery links
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');

        // Trim legacy emoji polyfill overhead on modern browsers
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');

        // Emit subtle dark theme-color meta for mobile browser chrome
        add_action('wp_head', [$this, 'emitThemeColorMeta'], 1);
    }

    public function emitThemeColorMeta(): void
    {
        echo '<meta name="theme-color" content="#070B14">' . "\n";
    }
}
