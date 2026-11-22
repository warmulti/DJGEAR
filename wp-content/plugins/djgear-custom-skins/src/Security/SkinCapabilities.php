<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Security;

/**
 * Capability definitions for DJGear Custom Skins (commercial ordering & production).
 *
 * Role Split:
 * - `administrator`: all 4 capabilities (including `djgear_manage_skin_plans`).
 * - `shop_manager`: `djgear_view_skin_orders`, `djgear_manage_skin_orders`, `djgear_manage_skin_catalog`.
 * - Zero engineering/dieline permissions exist in this module.
 */
final class SkinCapabilities
{
    public const VIEW_SKIN_ORDERS    = 'djgear_view_skin_orders';
    public const MANAGE_SKIN_ORDERS  = 'djgear_manage_skin_orders';
    public const MANAGE_SKIN_CATALOG = 'djgear_manage_skin_catalog';
    public const MANAGE_SKIN_PLANS   = 'djgear_manage_skin_plans';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW_SKIN_ORDERS,
            self::MANAGE_SKIN_ORDERS,
            self::MANAGE_SKIN_CATALOG,
            self::MANAGE_SKIN_PLANS,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        return [
            'administrator' => self::all(),
            'shop_manager'  => [
                self::VIEW_SKIN_ORDERS,
                self::MANAGE_SKIN_ORDERS,
                self::MANAGE_SKIN_CATALOG,
            ],
        ];
    }

    /**
     * Grant capabilities idempotently to existing WordPress roles.
     */
    public static function install(): void
    {
        if (!function_exists('get_role')) {
            return;
        }

        foreach (self::roleMatrix() as $roleSlug => $caps) {
            $role = get_role($roleSlug);
            if ($role === null) {
                continue;
            }
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }
}
