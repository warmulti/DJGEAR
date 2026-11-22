<?php

declare(strict_types=1);

namespace DJGear\Core\Lifecycle;

use wpdb;

/**
 * Contract for idempotent, versioned database migrations owned by DJGear plugins.
 *
 * Migrations MUST execute safely under the restricted WordPress database user (never root)
 * and MUST NOT alter WordPress core or WooCommerce tables.
 */
interface MigrationInterface
{
    /**
     * Unique sortable version identifier (e.g., "0001_create_audit_log_table").
     *
     * @return non-empty-string
     */
    public function version(): string;

    /**
     * Execute the forward schema migration.
     */
    public function up(wpdb $wpdb): void;
}
