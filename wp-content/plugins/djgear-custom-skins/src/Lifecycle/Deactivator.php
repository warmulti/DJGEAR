<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Lifecycle;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\CustomSkins\CustomSkinsModule;
use wpdb;

/**
 * Non-destructive deactivation handler for djgear-custom-skins.
 * Preserves all database tables, orders, artwork vault files, and capabilities.
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        global $wpdb;

        if ($wpdb instanceof wpdb && class_exists(AuditLogger::class)) {
            $audit = new AuditLogger($wpdb);
            $audit->record(new AuditEvent(
                action: 'skins.deactivated',
                resourceType: 'plugin',
                resourceId: CustomSkinsModule::MODULE_ID,
                context: [
                    'version'        => CustomSkinsModule::VERSION,
                    'data_preserved' => true,
                ],
            ));
        }

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
