<?php

declare(strict_types=1);

namespace DJGear\Core\Security;

/**
 * Central WordPress capability definitions for DJGear modules.
 */
final class Capabilities
{
    public const VIEW_DASHBOARD      = 'djgear_view_dashboard';
    public const MANAGE_CORE         = 'djgear_manage_core';
    public const MANAGE_MODULES      = 'djgear_manage_modules';
    public const VIEW_LOGS           = 'djgear_view_logs';
    public const MANAGE_INTEGRATIONS = 'djgear_manage_integrations';

    /**
     * Return all capabilities owned by djgear-core.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW_DASHBOARD,
            self::MANAGE_CORE,
            self::MANAGE_MODULES,
            self::VIEW_LOGS,
            self::MANAGE_INTEGRATIONS,
        ];
    }

    /**
     * Default WordPress/WooCommerce role-to-capability mapping.
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoleMap(): array
    {
        return [
            'administrator' => self::all(),
            'shop_manager'  => [
                self::VIEW_DASHBOARD,
                self::VIEW_LOGS,
            ],
        ];
    }

    /**
     * Grant default DJGear capabilities to existing WordPress/WooCommerce roles.
     */
    public static function install(): void
    {
        if (!function_exists('get_role')) {
            return;
        }

        foreach (self::defaultRoleMap() as $roleName => $caps) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }

            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }
}
