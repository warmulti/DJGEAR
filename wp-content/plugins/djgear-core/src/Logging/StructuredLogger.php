<?php

declare(strict_types=1);

namespace DJGear\Core\Logging;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * JSON-structured application logger with automatic sensitive-key redaction.
 */
final class StructuredLogger implements LoggerInterface
{
    /** @var list<string> */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'private_key',
        'card_number',
        'cvv',
    ];

    /** @var Closure(string): void */
    private readonly Closure $sink;

    /**
     * @param (Closure(string): void)|null $sink Custom line writer (defaults to PHP error_log).
     */
    public function __construct(
        private readonly string $channel = 'djgear.core',
        private readonly LogLevel $minLevel = LogLevel::INFO,
        ?Closure $sink = null,
    ) {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if ($level->weight() < $this->minLevel->weight()) {
            return;
        }

        $record = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'channel'   => $this->channel,
            'level'     => $level->value,
            'message'   => $message,
            'context'   => self::redactContext($context),
        ];

        $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            ($this->sink)($encoded);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Recursively redact sensitive keys so credentials or tokens never leak into logs.
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redactContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $keyStr = is_string($key) ? strtolower($key) : '';
            if ($keyStr !== '' && self::isSensitiveKey($keyStr)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::redactContext($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $lowercaseKey): bool
    {
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($lowercaseKey, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
