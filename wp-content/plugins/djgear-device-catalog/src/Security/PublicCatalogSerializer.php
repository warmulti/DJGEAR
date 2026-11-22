<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Security;

/**
 * Explicit allowlist serializer for public storefront REST endpoints (/djgear/v1/catalog/public/*).
 *
 * Security Invariant:
 * - Structurally strips ALL private/internal fields (`dieline_attachment_id`, `dieline_url`,
 *   `internal_notes`, `edit_url`, draft/unpublished/deprecated records).
 * - Only explicitly allowlisted keys can ever leave the public API boundary.
 */
final class PublicCatalogSerializer
{
    /** @var list<string> */
    public const DEVICE_SUMMARY_ALLOWLIST = [
        'id',
        'slug',
        'model_code',
        'canonical_key',
        'mpn',
        'name_fa',
        'name_en',
        'summary_fa',
        'summary_en',
        'release_year',
        'skin_ready',
        'brand',
        'category',
        'hero_image_url',
        'primary_wc_product_id',
    ];

    /** @var list<string> */
    public const SKIN_PUBLIC_ALLOWLIST = [
        'id',
        'template_code',
        'template_version',
        'width_mm',
        'height_mm',
        'bleed_mm',
        'min_dpi',
        'zones',
        'supported_finishes',
        'preview_image_url',
        'base_wc_product_id',
        'compatible_device_ids',
        'notes_fa',
        'notes_en',
    ];

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public static function deviceSummary(array $device): array
    {
        return self::project($device, self::DEVICE_SUMMARY_ALLOWLIST);
    }

    /**
     * @param array<string, mixed> $device
     * @param list<array<string, mixed>> $specs
     * @param list<array<string, mixed>> $media
     * @param list<array<string, mixed>> $compatibilities
     * @param list<array<string, mixed>> $activeSkins
     * @return array<string, mixed>
     */
    public static function deviceDetail(
        array $device,
        array $specs,
        array $media,
        array $compatibilities,
        array $activeSkins,
    ): array {
        $base = self::deviceSummary($device);

        $base['specs'] = array_map(
            static fn (array $s): array => self::project($s, [
                'spec_group',
                'spec_key',
                'label_fa',
                'label_en',
                'value_fa',
                'value_en',
                'value_type',
                'numeric_value',
                'unit',
                'is_filterable',
            ]),
            $specs
        );

        $base['media'] = array_map(
            static fn (array $m): array => self::project($m, [
                'attachment_id',
                'media_role',
                'url',
                'caption_fa',
                'caption_en',
                'sort_order',
            ]),
            $media
        );

        $base['compatibilities'] = array_map(
            static function (array $c): array {
                $projected = self::project($c, [
                    'target_type',
                    'target_id',
                    'relation_type',
                    'fit_confidence',
                    'notes_fa',
                    'notes_en',
                ]);
                if (isset($c['wc_product']) && is_array($c['wc_product'])) {
                    $projected['wc_product'] = self::project($c['wc_product'], [
                        'id',
                        'name',
                        'sku',
                        'price',
                        'currency',
                        'stock_status',
                        'permalink',
                    ]);
                }
                if (isset($c['device']) && is_array($c['device'])) {
                    $projected['device'] = self::project($c['device'], [
                        'id',
                        'slug',
                        'model_code',
                        'name_fa',
                        'name_en',
                    ]);
                }
                return $projected;
            },
            $compatibilities
        );

        $base['skins'] = array_values(array_map(
            static fn (array $skin): array => self::skinPublic($skin),
            array_filter(
                $activeSkins,
                static fn (array $skin): bool => ($skin['status'] ?? '') === 'active'
            )
        ));

        return $base;
    }

    /**
     * Strictly filters a skin definition to public-safe customer/storefront keys ONLY.
     *
     * Never emits `dieline_attachment_id`, `dieline_url`, or `internal_notes`.
     *
     * @param array<string, mixed> $skin
     * @return array<string, mixed>
     */
    public static function skinPublic(array $skin): array
    {
        return self::project($skin, self::SKIN_PUBLIC_ALLOWLIST);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $allowedKeys
     * @return array<string, mixed>
     */
    private static function project(array $source, array $allowedKeys): array
    {
        $out = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }

        return $out;
    }
}
