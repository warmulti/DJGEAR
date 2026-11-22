<?php

declare(strict_types=1);

namespace DJGear\Core\Lifecycle;

use DJGear\Core\Lifecycle\Migrations\CreateAuditLogTable;
use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\Core\Plugin;
use DJGear\Core\Security\Capabilities;
use wpdb;

/**
 * Handles idempotent DJGear Core plugin activation.
 */
final class Activator
{
    public const VERSION_OPTION = 'djgear_core_version';

    public static function activate(): void
    {
        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $runner = new MigrationRunner($wpdb, [
                new CreateAuditLogTable(),
            ]);
            $applied = $runner->runPending();

            Capabilities::install();

            if (function_exists('update_option')) {
                update_option(self::VERSION_OPTION, Plugin::VERSION, false);
            }

            $audit = new AuditLogger($wpdb);
            $audit->record(new AuditEvent(
                action: 'core.activated',
                resourceType: 'plugin',
                resourceId: 'djgear-core',
                context: [
                    'version'            => Plugin::VERSION,
                    'applied_migrations' => $applied,
                ],
            ));
        }

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
