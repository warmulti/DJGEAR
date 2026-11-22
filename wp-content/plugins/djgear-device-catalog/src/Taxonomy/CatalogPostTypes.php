<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Taxonomy;

/**
 * Registers the WordPress CPT (djgear_device) and taxonomies (djgear_brand, djgear_device_category).
 *
 * Canonical Source-of-Truth Rule:
 * - `djgear_device` CPT stores ONLY ID, slug (`post_name`), status (`post_status`), and display title (`post_title`).
 * - All structured device attributes live strictly in normalized MySQL tables (zero duplicate post_meta).
 */
final class CatalogPostTypes
{
    public const POST_TYPE         = 'djgear_device';
    public const TAXONOMY_BRAND    = 'djgear_brand';
    public const TAXONOMY_CATEGORY = 'djgear_device_category';

    public static function register(): void
    {
        if (!function_exists('register_post_type') || !function_exists('register_taxonomy')) {
            return;
        }

        register_post_type(self::POST_TYPE, [
            'labels'              => [
                'name'          => 'DJ Devices',
                'singular_name' => 'DJ Device',
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'exclude_from_search' => false,
            'has_archive'         => false,
            'rewrite'             => ['slug' => 'devices', 'with_front' => false],
            'supports'            => ['title', 'thumbnail', 'revisions'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ]);

        register_taxonomy(self::TAXONOMY_BRAND, [self::POST_TYPE], [
            'labels'            => [
                'name'          => 'DJ Brands',
                'singular_name' => 'DJ Brand',
            ],
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => false,
            'show_in_rest'      => false,
            'rewrite'           => ['slug' => 'device-brand', 'with_front' => false],
        ]);

        register_taxonomy(self::TAXONOMY_CATEGORY, [self::POST_TYPE], [
            'labels'            => [
                'name'          => 'Device Categories',
                'singular_name' => 'Device Category',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => false,
            'show_in_rest'      => false,
            'rewrite'           => ['slug' => 'device-category', 'with_front' => false],
        ]);
    }
}
