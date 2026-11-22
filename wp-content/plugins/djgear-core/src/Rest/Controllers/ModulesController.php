<?php

declare(strict_types=1);

namespace DJGear\Core\Rest\Controllers;

use DJGear\Core\Modules\ModuleDescriptor;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes registered DJGear modules and dependency metadata at GET /wp-json/djgear/v1/modules.
 */
final class ModulesController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ModuleRegistry $registry,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/modules', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::MANAGE_MODULES),
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $items = array_map(
            static fn (ModuleDescriptor $descriptor): array => $descriptor->toArray(),
            $this->registry->descriptors()
        );

        return Response::ok([
            'modules' => $items,
            'count'   => count($items),
        ]);
    }
}
