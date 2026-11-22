<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Rest;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use DJGear\Dashboard\Services\CommerceSummaryService;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes live overview metrics at GET /wp-json/djgear/v1/dashboard/overview.
 */
final class OverviewController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly CommerceSummaryService $commerce,
        private readonly ModuleRegistry $modules,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/dashboard/overview', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::VIEW_DASHBOARD),
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $descriptors = $this->modules->descriptors();
        $recentLogs = $this->authorizer->currentUserCan(Capabilities::VIEW_LOGS)
            ? $this->auditLogger->query(1, 5)
            : ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 5];

        return Response::ok([
            'commerce'     => $this->commerce->overviewMetrics(),
            'modules'      => [
                'registered_count' => count($descriptors),
                'booted_ids'       => array_values(array_map(
                    static fn ($d): string => $d->id,
                    array_filter($descriptors, static fn ($d): bool => $d->booted)
                )),
            ],
            'recent_audit' => [
                'total' => $recentLogs['total'],
                'items' => array_map(
                    static fn (AuditEvent $e): array => $e->toArray(),
                    $recentLogs['items']
                ),
            ],
        ]);
    }
}
