<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Domain;

/**
 * Generates deterministic canonical keys for duplicate prevention across UI, REST, and imports.
 */
final class CanonicalKeyGenerator
{
    public static function slugify(string $raw): string
    {
        $lower = strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($normalized, '-');
    }

    public static function normalizeModelToken(string $modelCode): string
    {
        $lower = strtolower(trim($modelCode));

        return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
    }

    /**
     * Build canonical device key: `{brand_slug}::{normalized_model_code}`.
     *
     * Example: ("Pioneer DJ", "DDJ-FLX4") => "pioneer-dj::ddjflx4".
     */
    public static function deviceKey(string $brandSlug, string $modelCode): string
    {
        $brand = self::slugify($brandSlug);
        $model = self::normalizeModelToken($modelCode);

        if ($brand === '' || $model === '') {
            throw ValidationException::invalidField(
                'canonical_key',
                'Both brand slug and model code are required to form a canonical device key.'
            );
        }

        return $brand . '::' . $model;
    }

    public static function normalizeTemplateCode(string $raw): string
    {
        $upper = strtoupper(trim($raw));
        $clean = preg_replace('/[^A-Z0-9\-_]+/', '-', $upper) ?? '';

        return trim($clean, '-');
    }
}
