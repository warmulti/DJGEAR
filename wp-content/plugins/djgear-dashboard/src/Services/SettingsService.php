<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Services;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;

/**
 * Manages non-sensitive central DJGear platform settings with strict sanitization and audit logging.
 */
final class SettingsService
{
    public const OPTION_KEY = 'djgear_core_settings';

    /** @var list<string> */
    private const ALLOWED_LOCALES = ['fa_IR', 'en_US'];

    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {
    }

    /**
     * @return array{brand_name: string, default_locale: string, persian_digits: bool, audit_retention_days: int, maintenance_notice: string}
     */
    public function defaults(): array
    {
        return [
            'brand_name'           => 'DJGear.ir',
            'default_locale'       => 'fa_IR',
            'persian_digits'       => true,
            'audit_retention_days' => 90,
            'maintenance_notice'   => '',
        ];
    }

    /**
     * @return array{brand_name: string, default_locale: string, persian_digits: bool, audit_retention_days: int, maintenance_notice: string}
     */
    public function get(): array
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION_KEY, []) : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->sanitize(array_merge($this->defaults(), $stored));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{brand_name: string, default_locale: string, persian_digits: bool, audit_retention_days: int, maintenance_notice: string}
     */
    public function update(array $input): array
    {
        $merged = array_merge($this->get(), $input);
        $sanitized = $this->sanitize($merged);

        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, $sanitized, false);
        }

        $this->auditLogger?->record(new AuditEvent(
            action: 'settings.updated',
            resourceType: 'settings',
            resourceId: self::OPTION_KEY,
            context: [
                'updated_keys'   => array_values(array_intersect(array_keys($input), array_keys($this->defaults()))),
                'default_locale' => $sanitized['default_locale'],
            ],
        ));

        return $sanitized;
    }

    /**
     * Pure sanitization helper (unit-testable outside WordPress).
     *
     * @param array<string, mixed> $raw
     * @return array{brand_name: string, default_locale: string, persian_digits: bool, audit_retention_days: int, maintenance_notice: string}
     */
    public function sanitize(array $raw): array
    {
        $defaults = $this->defaults();

        $brandRaw = is_string($raw['brand_name'] ?? null) ? trim(strip_tags($raw['brand_name'])) : $defaults['brand_name'];
        $brandName = $brandRaw !== '' ? mb_substr($brandRaw, 0, 80) : $defaults['brand_name'];

        $localeRaw = is_string($raw['default_locale'] ?? null) ? trim($raw['default_locale']) : $defaults['default_locale'];
        $defaultLocale = in_array($localeRaw, self::ALLOWED_LOCALES, true) ? $localeRaw : $defaults['default_locale'];

        $persianDigits = filter_var($raw['persian_digits'] ?? $defaults['persian_digits'], FILTER_VALIDATE_BOOLEAN);

        $retentionRaw = is_numeric($raw['audit_retention_days'] ?? null)
            ? (int) $raw['audit_retention_days']
            : $defaults['audit_retention_days'];
        $auditRetentionDays = max(7, min(365, $retentionRaw));

        $noticeRaw = is_string($raw['maintenance_notice'] ?? null) ? trim(strip_tags($raw['maintenance_notice'])) : '';
        $maintenanceNotice = mb_substr($noticeRaw, 0, 240);

        return [
            'brand_name'           => $brandName,
            'default_locale'       => $defaultLocale,
            'persian_digits'       => $persianDigits,
            'audit_retention_days' => $auditRetentionDays,
            'maintenance_notice'   => $maintenanceNotice,
        ];
    }
}
