<?php

declare(strict_types=1);

namespace DJGear\Core\Lifecycle\Migrations;

use DJGear\Core\Lifecycle\MigrationInterface;
use DJGear\Core\Logging\AuditLogger;
use wpdb;

/**
 * Creates the dedicated `{prefix}djgear_audit_logs` table for DJGear security & operational auditing.
 */
final class CreateAuditLogTable implements MigrationInterface
{
    public function version(): string
    {
        return '0001_create_audit_log_table';
    }

    public function up(wpdb $wpdb): void
    {
        $tableName = $wpdb->prefix . AuditLogger::TABLE_SUFFIX;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(128) NOT NULL,
            resource_type VARCHAR(64) NOT NULL,
            resource_id VARCHAR(128) NOT NULL DEFAULT '',
            actor_id BIGINT UNSIGNED NULL DEFAULT NULL,
            context_json LONGTEXT NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_action (action),
            KEY idx_resource (resource_type, resource_id),
            KEY idx_actor_created (actor_id, created_at),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);
    }
}
