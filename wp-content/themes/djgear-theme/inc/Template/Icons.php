<?php

declare(strict_types=1);

namespace DJGear\Theme\Template;

/**
 * Unified inline SVG stroke icon helper (Lucide-compatible geometry, zero icon font overhead).
 */
final class Icons
{
    /** @var array<string, string> */
    private const PATHS = [
        'menu'          => '<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/>',
        'close'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'search'        => '<circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.2" y2="16.2"/>',
        'cart'          => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2.5 3.5h2.8l2.1 10.7a1.5 1.5 0 0 0 1.5 1.2h9.2a1.5 1.5 0 0 0 1.46-1.16L21 7.5H6.2"/>',
        'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
        'chevron-right' => '<polyline points="9 6 15 12 9 18"/>',
        'arrow-right'   => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="13 6 19 12 13 18"/>',
        'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'truck'         => '<rect x="1" y="4" width="14" height="11" rx="1"/><polygon points="15 8 20 8 23 11 23 15 15 15 15 8"/><circle cx="5.5" cy="18" r="2"/><circle cx="18.5" cy="18" r="2"/>',
        'headphones'    => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
        'sliders'       => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'check'         => '<polyline points="20 6 9 17 4 12"/>',
    ];

    public static function get(string $name, int $size = 20, string $class = ''): string
    {
        $paths = self::PATHS[$name] ?? '';
        if ($paths === '') {
            return '';
        }

        $classes = trim('djg-icon ' . $class);

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            esc_attr($classes),
            $size,
            $size,
            $paths
        );
    }

    public static function render(string $name, int $size = 20, string $class = ''): void
    {
        // All markup comes strictly from the hardcoded internal PATHS allowlist above.
        echo self::get($name, $size, $class); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
