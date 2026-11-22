<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Lifecycle;

use wpdb;

/**
 * Contract for versioned, reversible djgear-custom-skins database migrations.
 */
interface ReversibleMigrationInterface
{
    public function version(): string;

    public function up(wpdb $wpdb): void;

    public function down(wpdb $wpdb): void;
}
