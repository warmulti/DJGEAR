<?php

declare(strict_types=1);

namespace DJGear\Core\Rest\Controllers;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes paginated audit logs at GET /wp-json/djgear/v1/audit-logs.
 */
final class AuditLogController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/audit-logs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::VIEW_LOGS),
            'args'                => [
                'page'     => [
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
                'action'   => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) $request->get_param('per_page')));
        $actionParam = $request->get_param('action');
        $action = is_string($actionParam) && $actionParam !== '' ? $actionParam : null;

        $result = $this->auditLogger->query($page, $perPage, $action);

        return Response::ok(
            [
                'items' => array_map(
                    static fn (AuditEvent $event): array => $event->toArray(),
                    $result['items']
                ),
            ],
            200,
            [
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
            ]
        );
    }
}
