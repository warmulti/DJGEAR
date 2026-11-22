<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Cache;

/**
 * Redis-backed public cache with atomic namespace version-salt invalidation.
 *
 * Security Invariant:
 * - Used STRICTLY for public anonymous-safe `/djgear/v1/skins/options` responses.
 * - NEVER caches customer jobs, artwork, proofs, or admin responses.
 */
final class SkinsCacheService
{
    public const CACHE_GROUP = 'djgear_skins_public';
    public const VERSION_KEY = 'namespace_version';
    public const OPTION_VERSION = 'djgear_skins_cache_version';
    public const DEFAULT_TTL = 300;

    /**
     * @template T
     * @param callable(): T $resolver
     * @return T
     */
    public function remember(string $key, callable $resolver, int $ttl = self::DEFAULT_TTL): mixed
    {
        if (!function_exists('wp_cache_get') || !function_exists('wp_cache_set')) {
            return $resolver();
        }

        $versionedKey = $this->versionedKey($key);
        $found = false;
        $cached = wp_cache_get($versionedKey, self::CACHE_GROUP, false, $found);
        if ($found) {
            return $cached;
        }

        $fresh = $resolver();
        wp_cache_set($versionedKey, $fresh, self::CACHE_GROUP, max(10, $ttl));

        return $fresh;
    }

    public function invalidatePublicCache(): int
    {
        $next = $this->currentVersion() + 1;

        if (function_exists('wp_cache_set')) {
            wp_cache_set(self::VERSION_KEY, $next, self::CACHE_GROUP, 0);
        }
        if (function_exists('update_option')) {
            update_option(self::OPTION_VERSION, $next, false);
        }

        return $next;
    }

    public function currentVersion(): int
    {
        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get(self::VERSION_KEY, self::CACHE_GROUP);
            if (is_int($cached) && $cached > 0) {
                return $cached;
            }
        }

        $opt = function_exists('get_option') ? (int) get_option(self::OPTION_VERSION, 1) : 1;
        $ver = max(1, $opt);

        if (function_exists('wp_cache_set')) {
            wp_cache_set(self::VERSION_KEY, $ver, self::CACHE_GROUP, 0);
        }

        return $ver;
    }

    private function versionedKey(string $key): string
    {
        return 'v' . $this->currentVersion() . ':' . hash('sha256', $key);
    }
}
