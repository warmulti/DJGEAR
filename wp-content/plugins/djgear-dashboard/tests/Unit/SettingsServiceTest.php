<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Tests\Unit;

use DJGear\Dashboard\Services\SettingsService;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testSanitizeEnforcesAllowlistedLocaleAndBoundsAndStripsHtml(): void
    {
        $service = new SettingsService();

        $clean = $service->sanitize([
            'brand_name'           => '  <b>DJGear Pro</b>  ',
            'default_locale'       => 'evil_XX',
            'persian_digits'       => 'false',
            'audit_retention_days' => 9999,
            'maintenance_notice'   => '<script>alert(1)</script>Planned window',
        ]);

        self::assertSame('DJGear Pro', $clean['brand_name']);
        self::assertSame('fa_IR', $clean['default_locale']);
        self::assertFalse($clean['persian_digits']);
        self::assertSame(365, $clean['audit_retention_days']);
        self::assertSame('alert(1)Planned window', $clean['maintenance_notice']);

        $en = $service->sanitize([
            'default_locale'       => 'en_US',
            'audit_retention_days' => 1,
        ]);

        self::assertSame('en_US', $en['default_locale']);
        self::assertSame(7, $en['audit_retention_days']);
    }
}
