<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Lifecycle;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\DeviceCatalog\DeviceCatalogModule;
use DJGear\DeviceCatalog\Lifecycle\Migrations\CreateCatalogTables;
use DJGear\DeviceCatalog\Security\CatalogCapabilities;
use DJGear\DeviceCatalog\Taxonomy\CatalogPostTypes;
use wpdb;

/**
 * Idempotent activation handler for djgear-device-catalog.
 */
final class Activator
{
    public const VERSION_OPTION = 'djgear_catalog_version';

    public static function activate(): void
    {
        global $wpdb;

        CatalogPostTypes::register();
        CatalogCapabilities::install();

        if ($wpdb instanceof wpdb) {
            $runner = new CatalogMigrationRunner($wpdb, [
                new CreateCatalogTables(),
            ]);
            $applied = $runner->runPending();

            if (function_exists('update_option')) {
                update_option(self::VERSION_OPTION, DeviceCatalogModule::VERSION, false);
            }

            if (class_exists(AuditLogger::class)) {
                $audit = new AuditLogger($wpdb);
                $audit->record(new AuditEvent(
                    action: 'catalog.activated',
                    resourceType: 'plugin',
                    resourceId: DeviceCatalogModule::MODULE_ID,
                    context: [
                        'version'            => DeviceCatalogModule::VERSION,
                        'applied_migrations' => $applied,
                    ],
                ));
            }
        }

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
