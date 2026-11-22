<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use DJGear\Dashboard\Services\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes central DJGear platform settings at GET/POST /wp-json/djgear/v1/dashboard/settings.
 *
 * Strictly restricted to `djgear_manage_core` (administrator only by default).
 */
final class SettingsController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly SettingsService $settings,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/dashboard/settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handleGet'],
                'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::MANAGE_CORE),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handleUpdate'],
                'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::MANAGE_CORE),
            ],
        ]);
    }

    public function handleGet(): WP_REST_Response
    {
        return Response::ok([
            'settings' => $this->settings->get(),
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function handleUpdate(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if ($body === []) {
            $body = $request->get_params();
        }

        $updated = $this->settings->update($body);

        return Response::ok([
            'settings' => $updated,
            'saved'    => true,
        ]);
    }
}
