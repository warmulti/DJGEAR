<?php

declare(strict_types=1);

namespace DJGear\Core\Logging;

/**
 * Severity levels for DJGear structured application logging.
 */
enum LogLevel: string
{
    case DEBUG    = 'debug';
    case INFO     = 'info';
    case NOTICE   = 'notice';
    case WARNING  = 'warning';
    case ERROR    = 'error';
    case CRITICAL = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::DEBUG    => 100,
            self::INFO     => 200,
            self::NOTICE   => 250,
            self::WARNING  => 300,
            self::ERROR    => 400,
            self::CRITICAL => 500,
        };
    }
}
