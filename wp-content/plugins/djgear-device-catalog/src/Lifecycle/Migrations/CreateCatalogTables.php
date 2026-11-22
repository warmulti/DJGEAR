<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Lifecycle\Migrations;

use DJGear\DeviceCatalog\Lifecycle\ReversibleMigrationInterface;
use wpdb;

/**
 * Creates the 5 normalized MySQL 8.4 tables for djgear-device-catalog:
 * 1. {prefix}djgear_device_index
 * 2. {prefix}djgear_device_specs
 * 3. {prefix}djgear_device_media
 * 4. {prefix}djgear_device_compatibility
 * 5. {prefix}djgear_skin_definitions
 */
final class CreateCatalogTables implements ReversibleMigrationInterface
{
    public const TABLE_INDEX         = 'djgear_device_index';
    public const TABLE_SPECS         = 'djgear_device_specs';
    public const TABLE_MEDIA         = 'djgear_device_media';
    public const TABLE_COMPATIBILITY = 'djgear_device_compatibility';
    public const TABLE_SKINS         = 'djgear_skin_definitions';

    public function version(): string
    {
        return '0001_create_catalog_tables';
    }

    public function up(wpdb $wpdb): void
    {
        $charsetCollate = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $sqlIndex = "CREATE TABLE {$p}" . self::TABLE_INDEX . " (
            device_id BIGINT UNSIGNED NOT NULL,
            brand_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            model_code VARCHAR(120) NOT NULL,
            canonical_key VARCHAR(190) NOT NULL,
            mpn VARCHAR(100) NULL DEFAULT NULL,
            name_fa VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NOT NULL,
            summary_fa TEXT NULL,
            summary_en TEXT NULL,
            release_year SMALLINT UNSIGNED NULL DEFAULT NULL,
            lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'active',
            skin_ready TINYINT(1) NOT NULL DEFAULT 0,
            primary_wc_product_id BIGINT UNSIGNED NULL DEFAULT NULL,
            search_tokens TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (device_id),
            UNIQUE KEY uq_canonical_key (canonical_key),
            UNIQUE KEY uq_mpn (mpn),
            KEY idx_brand (brand_term_id),
            KEY idx_category (category_term_id),
            KEY idx_lifecycle (lifecycle_status),
            KEY idx_skin_ready (skin_ready),
            KEY idx_wc_product (primary_wc_product_id),
            KEY idx_year (release_year),
            FULLTEXT KEY ft_device_search (model_code, name_en, name_fa, search_tokens)
        ) {$charsetCollate};";

        $sqlSpecs = "CREATE TABLE {$p}" . self::TABLE_SPECS . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id BIGINT UNSIGNED NOT NULL,
            spec_group VARCHAR(64) NOT NULL DEFAULT 'general',
            spec_key VARCHAR(64) NOT NULL,
            label_fa VARCHAR(128) NOT NULL,
            label_en VARCHAR(128) NOT NULL,
            value_fa VARCHAR(255) NOT NULL,
            value_en VARCHAR(255) NOT NULL,
            value_type VARCHAR(24) NOT NULL DEFAULT 'string',
            numeric_value DECIMAL(12,3) NULL DEFAULT NULL,
            filter_slug VARCHAR(64) NULL DEFAULT NULL,
            unit VARCHAR(32) NOT NULL DEFAULT '',
            is_filterable TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_device_spec (device_id, spec_key),
            KEY idx_device_group (device_id, spec_group, sort_order),
            KEY idx_filter_num (spec_key, numeric_value),
            KEY idx_filter_slug (spec_key, filter_slug)
        ) {$charsetCollate};";

        $sqlMedia = "CREATE TABLE {$p}" . self::TABLE_MEDIA . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id BIGINT UNSIGNED NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            media_role VARCHAR(32) NOT NULL DEFAULT 'gallery',
            caption_fa VARCHAR(255) NOT NULL DEFAULT '',
            caption_en VARCHAR(255) NOT NULL DEFAULT '',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_device_attachment_role (device_id, attachment_id, media_role),
            KEY idx_device_role (device_id, media_role, sort_order)
        ) {$charsetCollate};";

        $sqlCompat = "CREATE TABLE {$p}" . self::TABLE_COMPATIBILITY . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_device_id BIGINT UNSIGNED NOT NULL,
            target_type VARCHAR(24) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            relation_type VARCHAR(32) NOT NULL,
            fit_confidence VARCHAR(24) NOT NULL DEFAULT 'verified',
            notes_fa VARCHAR(255) NOT NULL DEFAULT '',
            notes_en VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_compat_edge (source_device_id, target_type, target_id, relation_type),
            KEY idx_source (source_device_id, relation_type),
            KEY idx_target (target_type, target_id)
        ) {$charsetCollate};";

        $sqlSkins = "CREATE TABLE {$p}" . self::TABLE_SKINS . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            primary_device_id BIGINT UNSIGNED NOT NULL,
            template_code VARCHAR(96) NOT NULL,
            template_version VARCHAR(24) NOT NULL DEFAULT '1.0.0',
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            width_mm DECIMAL(8,2) NOT NULL,
            height_mm DECIMAL(8,2) NOT NULL,
            bleed_mm DECIMAL(6,2) NOT NULL DEFAULT 3.00,
            min_dpi SMALLINT UNSIGNED NOT NULL DEFAULT 300,
            zones_json LONGTEXT NOT NULL,
            supported_finishes_json TEXT NOT NULL,
            preview_attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
            dieline_attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
            base_wc_product_id BIGINT UNSIGNED NULL DEFAULT NULL,
            internal_notes TEXT NULL,
            notes_fa TEXT NULL,
            notes_en TEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_template_code (template_code),
            KEY idx_primary_device (primary_device_id, status),
            KEY idx_status (status),
            KEY idx_skin_wc_product (base_wc_product_id)
        ) {$charsetCollate};";

        dbDelta($sqlIndex);
        dbDelta($sqlSpecs);
        dbDelta($sqlMedia);
        dbDelta($sqlCompat);
        dbDelta($sqlSkins);
    }

    public function down(wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        foreach ([
            self::TABLE_SKINS,
            self::TABLE_COMPATIBILITY,
            self::TABLE_MEDIA,
            self::TABLE_SPECS,
            self::TABLE_INDEX,
        ] as $suffix) {
            $wpdb->query("DROP TABLE IF EXISTS {$p}{$suffix}");
        }
    }
}
