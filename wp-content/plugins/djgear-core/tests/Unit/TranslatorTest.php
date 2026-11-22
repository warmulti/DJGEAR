<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\I18n\Direction;
use DJGear\Core\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function testPersianLocaleResolvesRtlAndPersianDigits(): void
    {
        $translator = new Translator('djgear-core/languages', 'fa_IR');

        self::assertSame('fa_IR', $translator->currentLocale());
        self::assertSame(Direction::RTL, $translator->direction());
        self::assertTrue($translator->isRtl());
        self::assertSame('سفارش ۱۲۳۴۵', $translator->localizeDigits('سفارش 12345'));

        $meta = $translator->metadata();
        self::assertSame('rtl', $meta['direction']);
        self::assertTrue($meta['is_rtl']);
        self::assertSame('djgear-core', $meta['text_domain']);
        self::assertContains('fa_IR', $meta['supported_locales']);
        self::assertContains('en_US', $meta['supported_locales']);
    }

    public function testEnglishLocaleResolvesLtrAndPreservesAsciiDigits(): void
    {
        $translator = new Translator('djgear-core/languages', 'en_US');

        self::assertSame('en_US', $translator->currentLocale());
        self::assertSame(Direction::LTR, $translator->direction());
        self::assertFalse($translator->isRtl());
        self::assertSame('Order #12345', $translator->localizeDigits('Order #12345'));
        self::assertSame('DJGear Core', $translator->translate('DJGear Core'));
    }
}
