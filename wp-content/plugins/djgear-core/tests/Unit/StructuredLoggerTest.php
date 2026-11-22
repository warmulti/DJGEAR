<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\Logging\LogLevel;
use DJGear\Core\Logging\StructuredLogger;
use PHPUnit\Framework\TestCase;

final class StructuredLoggerTest extends TestCase
{
    public function testEmitsStructuredJsonAndFiltersByMinimumLevel(): void
    {
        $lines = [];
        $logger = new StructuredLogger(
            channel: 'djgear.test',
            minLevel: LogLevel::WARNING,
            sink: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }
        );

        $logger->debug('ignored debug');
        $logger->info('ignored info');
        $logger->warning('low stock detected', ['sku' => 'DJ-CTRL-01']);

        self::assertCount(1, $lines);

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('djgear.test', $decoded['channel']);
        self::assertSame('warning', $decoded['level']);
        self::assertSame('low stock detected', $decoded['message']);
        self::assertSame('DJ-CTRL-01', $decoded['context']['sku']);
        self::assertNotEmpty($decoded['timestamp']);
    }

    public function testRecursivelyRedactsSensitiveKeys(): void
    {
        $lines = [];
        $logger = new StructuredLogger(
            channel: 'djgear.security',
            minLevel: LogLevel::DEBUG,
            sink: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }
        );

        $logger->error('gateway auth error', [
            'gateway'  => 'sandbox',
            'api_key'  => 'super-secret-key',
            'password' => 'do-not-log',
            'nested'   => [
                'access_token' => 'jwt-secret-value',
                'safe_field'   => 'visible',
            ],
        ]);

        self::assertCount(1, $lines);
        self::assertStringNotContainsString('super-secret-key', $lines[0]);
        self::assertStringNotContainsString('do-not-log', $lines[0]);
        self::assertStringNotContainsString('jwt-secret-value', $lines[0]);

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('[REDACTED]', $decoded['context']['api_key']);
        self::assertSame('[REDACTED]', $decoded['context']['password']);
        self::assertSame('[REDACTED]', $decoded['context']['nested']['access_token']);
        self::assertSame('visible', $decoded['context']['nested']['safe_field']);
    }
}
