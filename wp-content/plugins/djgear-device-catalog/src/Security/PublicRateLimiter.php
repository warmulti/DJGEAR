<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Security;

use WP_Error;

/**
 * Lightweight Redis/object-cache fixed-window IP rate limiter for public catalog read endpoints.
 *
 * Reverse Proxy Safety:
 * - Defaults strictly to `REMOTE_ADDR` and NEVER blindly trusts `X-Forwarded-For` or `Client-IP`.
 * - Parses `X-Forwarded-For` right-to-left ONLY when `REMOTE_ADDR` belongs to an explicitly configured
 *   trusted reverse proxy list (`djgear_catalog_trusted_proxies` filter / `DJGEAR_TRUSTED_PROXIES`).
 */
final class PublicRateLimiter
{
    public const CACHE_GROUP = 'djgear_catalog_ratelimit';

    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(
        private readonly int $defaultLimit = 120,
        private readonly int $windowSeconds = 60,
        private readonly array $trustedProxies = [],
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

        $ip = $clientKey ?? $this->resolveClientIp($_SERVER);
        $key = 'ip:' . hash('sha256', $ip . ':' . (string) floor(time() / $this->windowSeconds));
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

    /**
     * Resolve client IP safely without trusting spoofable headers from untrusted peers.
     *
     * @param array<string, mixed> $server
     */
    public function resolveClientIp(array $server): string
    {
        $remoteRaw = is_string($server['REMOTE_ADDR'] ?? null) ? trim($server['REMOTE_ADDR']) : '127.0.0.1';
        $remoteIp = filter_var($remoteRaw, FILTER_VALIDATE_IP) !== false ? $remoteRaw : '127.0.0.1';

        $trusted = $this->resolvedTrustedProxies();
        if ($trusted === [] || !in_array($remoteIp, $trusted, true)) {
            return $remoteIp;
        }

        $xffRaw = is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) ? $server['HTTP_X_FORWARDED_FOR'] : '';
        if ($xffRaw === '') {
            return $remoteIp;
        }

        // Walk right-to-left: return the first valid IP that is NOT itself a trusted proxy.
        // This prevents clients from spoofing the left-most X-Forwarded-For value.
        $hops = array_reverse(array_map('trim', explode(',', $xffRaw)));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!in_array($hop, $trusted, true)) {
                return $hop;
            }
        }

        return $remoteIp;
    }

    /**
     * @return list<string>
     */
    private function resolvedTrustedProxies(): array
    {
        $proxies = $this->trustedProxies;
        if ($proxies === []) {
            $env = getenv('DJGEAR_TRUSTED_PROXIES');
            if (is_string($env) && trim($env) !== '') {
                $proxies = array_values(array_filter(array_map('trim', explode(',', $env))));
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('djgear_catalog_trusted_proxies', $proxies);
            if (is_array($filtered)) {
                $proxies = array_values(array_filter($filtered, static fn (mixed $v): bool => is_string($v) && $v !== ''));
            }
        }

        return $proxies;
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
}
