<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Lifecycle;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\CustomSkins\CustomSkinsModule;
use DJGear\CustomSkins\Frontend\CustomerPortal;
use DJGear\CustomSkins\Lifecycle\Migrations\CreateCustomSkinTables;
use DJGear\CustomSkins\Repositories\SkinPlanRepository;
use DJGear\CustomSkins\Security\SkinCapabilities;
use DJGear\CustomSkins\Services\CustomerArtworkVault;
use wpdb;

/**
 * Idempotent activation handler for djgear-custom-skins.
 */
final class Activator
{
    public const VERSION_OPTION = 'djgear_skins_version';

    public static function activate(): void
    {
        global $wpdb;

        SkinCapabilities::install();
        CustomerPortal::registerEndpoint();

        $vault = new CustomerArtworkVault();
        $vault->ensureVaultDirectories();

        if ($wpdb instanceof wpdb) {
            $runner = new SkinMigrationRunner($wpdb, [
                new CreateCustomSkinTables(),
            ]);
            $applied = $runner->runPending();

            $plans = new SkinPlanRepository($wpdb);
            $seededPlans = $plans->seedDefaultsIfEmpty();

            if (function_exists('update_option')) {
                update_option(self::VERSION_OPTION, CustomSkinsModule::VERSION, false);
            }

            if (class_exists(AuditLogger::class)) {
                $audit = new AuditLogger($wpdb);
                $audit->record(new AuditEvent(
                    action: 'skins.activated',
                    resourceType: 'plugin',
                    resourceId: CustomSkinsModule::MODULE_ID,
                    context: [
                        'version'            => CustomSkinsModule::VERSION,
                        'applied_migrations' => $applied,
                        'seeded_plans'       => $seededPlans,
                    ],
                ));
            }
        }

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
