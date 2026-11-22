<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Rest;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\DeviceCatalog\Cache\CatalogCacheService;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Repositories\SkinDefinitionRepository;
use DJGear\DeviceCatalog\Security\CatalogCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Administrator-only engineering management controller for Compatible Skin Definitions.
 *
 * Enforces `djgear_manage_skin_definitions` strictly (shop_manager receives HTTP 403).
 */
final class SkinDefinitionsController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly SkinDefinitionRepository $skins,
        private readonly CatalogCacheService $cache,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/catalog/skins', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listSkins'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createSkin'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS),
            ],
        ]);

        register_rest_route($namespace, '/catalog/skins/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateSkin'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteSkin'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS),
            ],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listSkins(WP_REST_Request $request): WP_REST_Response
    {
        $statusParam = $request->get_param('status');
        $status = is_string($statusParam) && $statusParam !== '' ? $statusParam : null;
        $items = $this->skins->listAll($status);

        return Response::ok(['items' => $items], 200, ['total' => count($items)]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createSkin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $skin = $this->skins->create($this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent(
                'catalog.skin.created',
                'skin_definition',
                (string) $skin['id'],
                context: [
                    'template_code'    => $skin['template_code'],
                    'template_version' => $skin['template_version'],
                    'status'           => $skin['status'],
                ]
            ));

            return Response::ok(['skin' => $skin], 201);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateSkin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $skin = $this->skins->update($id, $this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent(
                'catalog.skin.updated',
                'skin_definition',
                (string) $id,
                context: [
                    'template_code'    => $skin['template_code'],
                    'template_version' => $skin['template_version'],
                    'status'           => $skin['status'],
                ]
            ));

            return Response::ok(['skin' => $skin]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteSkin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $this->skins->delete($id);
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.skin.deleted', 'skin_definition', (string) $id));

            return Response::ok(['deleted' => true, 'id' => $id]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();

        return $json !== [] ? $json : $request->get_params();
    }
}
