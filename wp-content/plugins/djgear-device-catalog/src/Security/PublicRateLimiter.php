<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Security;

use WP_Error;

/**
 * Lightweight Redis/object-cache fixed-window IP rate limiter for public catalog read endpoints.
 */
final class PublicRateLimiter
{
    public const CACHE_GROUP = 'djgear_catalog_ratelimit';

    public function __construct(
        private readonly int $defaultLimit = 120,
        private readonly int $windowSeconds = 60,
    ) {
    }

    /**
     * Increment and check caller rate quota. Returns quota metadata or WP_Error (HTTP 429).
     *
     * @return array{limit: int, remaining: int, window: int}|WP_Error
     */
    public function check(?string $clientKey = null): array|WP_Error
    {
        $limit = $this->resolvedLimit();
        if ($limit <= 0) {
            return ['limit' => 0, 'remaining' => 0, 'window' => $this->windowSeconds];
        }

        $key = 'ip:' . hash('sha256', ($clientKey ?? $this->resolveClientIp()) . ':' . (string) floor(time() / $this->windowSeconds));
        $count = 1;

        if (function_exists('wp_cache_get') && function_exists('wp_cache_set')) {
            $current = wp_cache_get($key, self::CACHE_GROUP);
            $count = is_numeric($current) ? ((int) $current) + 1 : 1;
            wp_cache_set($key, $count, self::CACHE_GROUP, $this->windowSeconds);
        }

        $remaining = max(0, $limit - $count);
        if ($count > $limit) {
            return new WP_Error(
                'djgear_catalog_rate_limited',
                'Too many public catalog requests. Please retry shortly.',
                [
                    'status'      => 429,
                    'retry_after' => $this->windowSeconds,
                ]
            );
        }

        return [
            'limit'     => $limit,
            'remaining' => $remaining,
            'window'    => $this->windowSeconds,
        ];
    }

    private function resolvedLimit(): int
    {
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('djgear_catalog_public_rate_limit', $this->defaultLimit);
            if (is_numeric($filtered)) {
                return (int) $filtered;
            }
        }

        return $this->defaultLimit;
    }

    private function resolveClientIp(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!is_string($raw)) {
            return '127.0.0.1';
        }
        $valid = filter_var(trim($raw), FILTER_VALIDATE_IP);

        return $valid !== false ? $valid : '127.0.0.1';
    }
}
