<?php

declare(strict_types=1);

namespace DJGear\Core\Logging;

/**
 * Structured application logging contract.
 */
interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void;
}
