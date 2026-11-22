<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Lifecycle\Migrations;

use DJGear\CustomSkins\Lifecycle\ReversibleMigrationInterface;
use wpdb;

/**
 * Creates the 5 normalized commercial custom-skin tables under MySQL 8.4 (utf8mb4_unicode_ci).
 * Zero engineering dieline tables or fields are created.
 */
final class CreateCustomSkinTables implements ReversibleMigrationInterface
{
    public const TABLE_VARIANTS       = 'djgear_skin_variants';
    public const TABLE_PLANS          = 'djgear_skin_plans';
    public const TABLE_JOBS           = 'djgear_skin_jobs';
    public const TABLE_ARTWORKS       = 'djgear_skin_artworks';
    public const TABLE_STATUS_HISTORY = 'djgear_skin_status_history';

    public function version(): string
    {
        return '2026_11_22_000001_create_custom_skin_tables';
    }

    public function up(wpdb $wpdb): void
    {
        $charset = $wpdb->get_charset_collate();
        if ($charset === '') {
            $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        $variants = $wpdb->prefix . self::TABLE_VARIANTS;
        $plans    = $wpdb->prefix . self::TABLE_PLANS;
        $jobs     = $wpdb->prefix . self::TABLE_JOBS;
        $artworks = $wpdb->prefix . self::TABLE_ARTWORKS;
        $history  = $wpdb->prefix . self::TABLE_STATUS_HISTORY;

        if (function_exists('dbDelta') || (defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/upgrade.php'))) {
            if (!function_exists('dbDelta') && defined('ABSPATH')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $sqlVariants = "CREATE TABLE {$variants} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                device_id BIGINT UNSIGNED NOT NULL,
                wc_product_id BIGINT UNSIGNED NOT NULL,
                wc_variation_id BIGINT UNSIGNED NULL DEFAULT NULL,
                model_code VARCHAR(64) NOT NULL,
                model_name_fa VARCHAR(140) NOT NULL,
                model_name_en VARCHAR(140) NOT NULL,
                color_code VARCHAR(64) NOT NULL,
                color_name_fa VARCHAR(120) NOT NULL,
                color_name_en VARCHAR(120) NOT NULL,
                color_hex VARCHAR(16) NOT NULL DEFAULT '#111827',
                finish VARCHAR(32) NOT NULL DEFAULT 'matte',
                preview_attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
                print_width_mm DECIMAL(8,2) NULL DEFAULT NULL,
                print_height_mm DECIMAL(8,2) NULL DEFAULT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'active',
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_device_model_color (device_id, model_code, color_code),
                KEY idx_device_status (device_id, status),
                KEY idx_wc_product (wc_product_id)
            ) {$charset};";

            $sqlPlans = "CREATE TABLE {$plans} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(64) NOT NULL,
                name_fa VARCHAR(140) NOT NULL,
                name_en VARCHAR(140) NOT NULL,
                description_fa TEXT NULL,
                description_en TEXT NULL,
                plan_type VARCHAR(24) NOT NULL DEFAULT 'standard',
                allows_artwork_upload TINYINT(1) NOT NULL DEFAULT 0,
                requires_artwork TINYINT(1) NOT NULL DEFAULT 0,
                requires_proof_approval TINYINT(1) NOT NULL DEFAULT 0,
                max_revisions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                price_surcharge DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                estimated_lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                features_fa_json TEXT NOT NULL,
                features_en_json TEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_plan_slug (slug),
                KEY idx_active_sort (is_active, sort_order)
            ) {$charset};";

            $sqlJobs = "CREATE TABLE {$jobs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_uuid CHAR(36) NOT NULL,
                customer_user_id BIGINT UNSIGNED NOT NULL,
                wc_order_id BIGINT UNSIGNED NULL DEFAULT NULL,
                wc_order_item_id BIGINT UNSIGNED NULL DEFAULT NULL,
                device_id BIGINT UNSIGNED NOT NULL,
                skin_variant_id BIGINT UNSIGNED NOT NULL,
                plan_id BIGINT UNSIGNED NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
                current_artwork_id BIGINT UNSIGNED NULL DEFAULT NULL,
                current_proof_id BIGINT UNSIGNED NULL DEFAULT NULL,
                approved_proof_id BIGINT UNSIGNED NULL DEFAULT NULL,
                approved_at DATETIME NULL DEFAULT NULL,
                assigned_staff_id BIGINT UNSIGNED NULL DEFAULT NULL,
                customer_notes TEXT NULL,
                internal_notes TEXT NULL,
                tracking_code VARCHAR(120) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uq_job_uuid (job_uuid),
                UNIQUE KEY uq_wc_order_item (wc_order_item_id),
                KEY idx_customer (customer_user_id),
                KEY idx_wc_order (wc_order_id),
                KEY idx_device (device_id),
                KEY idx_status_updated (status, updated_at),
                KEY idx_staff (assigned_staff_id)
            ) {$charset};";

            $sqlArtworks = "CREATE TABLE {$artworks} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                revision_type VARCHAR(24) NOT NULL,
                version_number SMALLINT UNSIGNED NOT NULL,
                uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
                storage_rel_path VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(64) NOT NULL,
                file_size_bytes BIGINT UNSIGNED NOT NULL,
                sha256_hash CHAR(64) NOT NULL,
                pixel_width INT UNSIGNED NULL DEFAULT NULL,
                pixel_height INT UNSIGNED NULL DEFAULT NULL,
                computed_dpi DECIMAL(7,2) NULL DEFAULT NULL,
                dpi_status VARCHAR(24) NOT NULL DEFAULT 'not_applicable',
                preflight_report_json TEXT NULL,
                low_dpi_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
                customer_decision VARCHAR(24) NOT NULL DEFAULT 'pending',
                decision_at DATETIME NULL DEFAULT NULL,
                note_fa TEXT NULL,
                note_en TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_job_type_ver (job_id, revision_type, version_number)
            ) {$charset};";

            $sqlHistory = "CREATE TABLE {$history} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(32) NULL DEFAULT NULL,
                to_status VARCHAR(32) NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                actor_role VARCHAR(24) NOT NULL DEFAULT 'system',
                is_customer_visible TINYINT(1) NOT NULL DEFAULT 1,
                comment TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_job_created (job_id, created_at)
            ) {$charset};";

            dbDelta($sqlVariants);
            dbDelta($sqlPlans);
            dbDelta($sqlJobs);
            dbDelta($sqlArtworks);
            dbDelta($sqlHistory);
        }
    }

    public function down(wpdb $wpdb): void
    {
        $tables = [
            $wpdb->prefix . self::TABLE_STATUS_HISTORY,
            $wpdb->prefix . self::TABLE_ARTWORKS,
            $wpdb->prefix . self::TABLE_JOBS,
            $wpdb->prefix . self::TABLE_PLANS,
            $wpdb->prefix . self::TABLE_VARIANTS,
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
