<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Lifecycle;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\DeviceCatalog\DeviceCatalogModule;
use wpdb;

/**
 * Safe deactivation handler — preserves all catalog tables, devices, taxonomies, and WooCommerce data.
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        global $wpdb;

        if ($wpdb instanceof wpdb && class_exists(AuditLogger::class)) {
            $audit = new AuditLogger($wpdb);
            $audit->record(new AuditEvent(
                action: 'catalog.deactivated',
                resourceType: 'plugin',
                resourceId: DeviceCatalogModule::MODULE_ID,
                context: [
                    'version' => DeviceCatalogModule::VERSION,
                ],
            ));
        }

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
