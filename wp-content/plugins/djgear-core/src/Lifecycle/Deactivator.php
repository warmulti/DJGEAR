<?php

declare(strict_types=1);

namespace DJGear\Core\Lifecycle;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\Core\Plugin;
use wpdb;

/**
 * Handles safe DJGear Core deactivation (preserves audit data, capabilities, and WooCommerce state).
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $audit = new AuditLogger($wpdb);
            $audit->record(new AuditEvent(
                action: 'core.deactivated',
                resourceType: 'plugin',
                resourceId: 'djgear-core',
                context: [
                    'version' => Plugin::VERSION,
                ],
            ));
        }

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
