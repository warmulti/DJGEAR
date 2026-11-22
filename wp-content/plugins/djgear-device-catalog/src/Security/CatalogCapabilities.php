<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Security;

/**
 * Capability matrix for djgear-device-catalog.
 *
 * Role Split (Approved Phase 3 Architecture):
 * - administrator: all 4 capabilities
 * - shop_manager: djgear_view_catalog + djgear_manage_catalog ONLY
 *   (skin engineering definitions and bulk import/export remain administrator-only)
 */
final class CatalogCapabilities
{
    public const VIEW_CATALOG            = 'djgear_view_catalog';
    public const MANAGE_CATALOG          = 'djgear_manage_catalog';
    public const MANAGE_SKIN_DEFINITIONS = 'djgear_manage_skin_definitions';
    public const IMPORT_EXPORT_CATALOG   = 'djgear_import_export_catalog';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW_CATALOG,
            self::MANAGE_CATALOG,
            self::MANAGE_SKIN_DEFINITIONS,
            self::IMPORT_EXPORT_CATALOG,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function defaultRoleMap(): array
    {
        return [
            'administrator' => self::all(),
            'shop_manager'  => [
                self::VIEW_CATALOG,
                self::MANAGE_CATALOG,
            ],
        ];
    }

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
