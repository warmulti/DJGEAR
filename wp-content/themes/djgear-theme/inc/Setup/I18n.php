<?php

declare(strict_types=1);

namespace DJGear\Theme\Setup;

/**
 * Persian (RTL) and English (LTR) localization & direction integration.
 *
 * Works seamlessly both standalone and alongside djgear-core without hard-coupling.
 */
final class I18n
{
    public const TEXT_DOMAIN = 'djgear-theme';

    /** @var array<string, string> */
    private const PERSIAN_DIGITS = [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
    ];

    public function register(): void
    {
        add_action('after_setup_theme', [$this, 'loadTextDomain'], 1);
        add_filter('body_class', [$this, 'addDirectionBodyClasses']);
    }

    public function loadTextDomain(): void
    {
        load_theme_textdomain(self::TEXT_DOMAIN, DJGEAR_THEME_DIR . '/languages');
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function addDirectionBodyClasses(array $classes): array
    {
        $classes[] = 'djg-theme';
        $classes[] = is_rtl() ? 'djg-dir-rtl' : 'djg-dir-ltr';

        $locale = sanitize_html_class(strtolower(get_locale()));
        if ($locale !== '') {
            $classes[] = 'djg-locale-' . $locale;
        }

        return $classes;
    }

    /**
     * Format numeric badges (e.g. cart item counter) to Persian numerals when active locale is `fa_*`.
     */
    public static function formatCount(int $count): string
    {
        $raw = (string) max(0, $count);
        if (str_starts_with(strtolower(get_locale()), 'fa')) {
            return strtr($raw, self::PERSIAN_DIGITS);
        }

        return $raw;
    }
}
