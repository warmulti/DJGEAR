<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Lifecycle;

use DJGear\Core\Lifecycle\MigrationInterface;
use wpdb;

/**
 * Versioned, idempotent, and reversible schema migration contract.
 */
interface ReversibleMigrationInterface extends MigrationInterface
{
    /**
     * Reverse the schema migration cleanly (used only for explicit rollback/testing, never on deactivation).
     */
    public function down(wpdb $wpdb): void;
}
