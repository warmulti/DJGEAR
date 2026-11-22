<?php

declare(strict_types=1);

namespace DJGear\Core\Security;

use Closure;
use WP_Error;

/**
 * WordPress capability-based authorization helper for UI and REST endpoints.
 */
final class Authorizer
{
    /**
     * Check whether the current authenticated user holds the required capability.
     */
    public function currentUserCan(string $capability): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }

        return current_user_can($capability);
    }

    /**
     * Check whether the current authenticated user holds any of the supplied capabilities.
     *
     * @param list<string> $capabilities
     */
    public function currentUserCanAny(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->currentUserCan($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a WP REST API permission_callback enforcing authentication and capability checks.
     *
     * Returns HTTP 401 for unauthenticated callers and HTTP 403 for authenticated callers lacking the capability.
     *
     * @return Closure(): (bool|WP_Error)
     */
    public function restPermissionCallback(string $capability): Closure
    {
        return function () use ($capability): bool|WP_Error {
            if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
                return new WP_Error(
                    'djgear_rest_unauthenticated',
                    'Authentication is required to access this DJGear endpoint.',
                    ['status' => 401]
                );
            }

            if (!$this->currentUserCan($capability)) {
                return new WP_Error(
                    'djgear_rest_forbidden',
                    sprintf('Missing required DJGear capability: %s', $capability),
                    ['status' => 403]
                );
            }

            return true;
        };
    }
}
