<?php

declare(strict_types=1);

namespace DJGear\Core\I18n;

/**
 * Persian (RTL) and English (LTR) localization & direction foundation for DJGear.
 */
final class Translator
{
    public const TEXT_DOMAIN = 'djgear-core';

    /** @var list<string> */
    private const RTL_LANGUAGE_PREFIXES = ['fa', 'ar', 'he', 'ur', 'ps', 'ku', 'ckb', 'sd', 'ug', 'yi'];

    /** @var array<int, string> */
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

    public function __construct(
        private readonly string $pluginRelLanguagesPath = 'djgear-core/languages',
        private readonly ?string $forcedLocale = null,
    ) {
    }

    /**
     * Load the plugin's compiled .mo textdomain in WordPress.
     */
    public function loadTextDomain(): bool
    {
        if (!function_exists('load_plugin_textdomain')) {
            return false;
        }

        return load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            $this->pluginRelLanguagesPath
        );
    }

    /**
     * Resolve active locale (prefers WordPress user locale in admin/REST, then site locale).
     */
    public function currentLocale(): string
    {
        if ($this->forcedLocale !== null && $this->forcedLocale !== '') {
            return $this->forcedLocale;
        }

        if (function_exists('determine_locale')) {
            $locale = determine_locale();
            if ($locale !== '') {
                return $locale;
            }
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if ($locale !== '') {
                return $locale;
            }
        }

        return 'en_US';
    }

    /**
     * Return writing direction (RTL or LTR) for the given or active locale.
     */
    public function direction(?string $locale = null): Direction
    {
        $resolved = $locale ?? $this->currentLocale();
        $lang = strtolower(explode('_', str_replace('-', '_', $resolved))[0]);

        return in_array($lang, self::RTL_LANGUAGE_PREFIXES, true)
            ? Direction::RTL
            : Direction::LTR;
    }

    public function isRtl(?string $locale = null): bool
    {
        return $this->direction($locale) === Direction::RTL;
    }

    /**
     * Translate a string under the djgear-core textdomain (safe fallback outside WordPress).
     */
    public function translate(string $text): string
    {
        if (function_exists('__')) {
            return (string) __($text, self::TEXT_DOMAIN);
        }

        return $text;
    }

    /**
     * Format ASCII digits to Persian digits when locale is Persian (`fa_*`).
     */
    public function localizeDigits(string $input, ?string $locale = null): string
    {
        $resolved = $locale ?? $this->currentLocale();
        if (str_starts_with(strtolower($resolved), 'fa')) {
            return strtr($input, self::PERSIAN_DIGITS);
        }

        return $input;
    }

    /**
     * Export client-facing localization payload for admin/dashboard consumers.
     *
     * @return array{locale: string, direction: string, is_rtl: bool, text_domain: string, supported_locales: list<string>}
     */
    public function metadata(?string $locale = null): array
    {
        $resolved = $locale ?? $this->currentLocale();
        $direction = $this->direction($resolved);

        return [
            'locale'            => $resolved,
            'direction'         => $direction->value,
            'is_rtl'            => $direction === Direction::RTL,
            'text_domain'       => self::TEXT_DOMAIN,
            'supported_locales' => ['fa_IR', 'en_US'],
        ];
    }
}
