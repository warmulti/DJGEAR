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
use DJGear\DeviceCatalog\Repositories\CatalogDetailsRepository;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\SkinDefinitionRepository;
use DJGear\DeviceCatalog\Security\CatalogCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin CRUD REST controller for Device Models, Specs, Media Galleries, and Compatibility Relationships.
 */
final class DevicesController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly DeviceRepository $devices,
        private readonly CatalogDetailsRepository $details,
        private readonly SkinDefinitionRepository $skins,
        private readonly CatalogCacheService $cache,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/catalog/devices', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listDevices'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::VIEW_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createDevice'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/catalog/devices/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getDevice'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::VIEW_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateDevice'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteDevice'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/catalog/devices/(?P<id>\d+)/specs', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'replaceSpecs'],
            'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
        ]);

        register_rest_route($namespace, '/catalog/devices/(?P<id>\d+)/media', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'replaceMedia'],
            'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
        ]);

        register_rest_route($namespace, '/catalog/devices/(?P<id>\d+)/compatibility', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listCompatibility'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::VIEW_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'addCompatibility'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/catalog/devices/(?P<id>\d+)/compatibility/(?P<edge_id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'deleteCompatibility'],
            'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listDevices(WP_REST_Request $request): WP_REST_Response
    {
        $skinParam = $request->get_param('skin_ready');
        $skinReady = ($skinParam === '1' || $skinParam === 'true' || $skinParam === true)
            ? true
            : (($skinParam === '0' || $skinParam === 'false' || $skinParam === false) ? false : null);

        $specParam = $request->get_param('spec');
        $specFilters = is_array($specParam) ? $specParam : [];

        $result = $this->devices->search([
            'q'           => is_string($request->get_param('q')) ? (string) $request->get_param('q') : '',
            'brand'       => is_scalar($request->get_param('brand')) ? (string) $request->get_param('brand') : '',
            'category'    => is_scalar($request->get_param('category')) ? (string) $request->get_param('category') : '',
            'status'      => is_string($request->get_param('status')) ? (string) $request->get_param('status') : '',
            'skin_ready'  => $skinReady,
            'spec'        => $specFilters,
            'page'        => max(1, (int) $request->get_param('page')),
            'per_page'    => max(1, min(50, (int) ($request->get_param('per_page') ?: 20))),
            'active_only' => false,
        ]);

        return Response::ok(
            ['items' => $result['items']],
            200,
            ['total' => $result['total'], 'page' => $result['page'], 'per_page' => $result['per_page']]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getDevice(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $device = $this->devices->findById($id);
        if ($device === null) {
            return Response::error('djgear_catalog_not_found', "Device #{$id} was not found.", 404);
        }

        $canManageSkins = $this->authorizer->currentUserCan(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS);
        $rawSkins = $this->skins->listForDevice($id, false);
        if (!$canManageSkins) {
            $rawSkins = array_map(static function (array $skin): array {
                unset($skin['dieline_attachment_id'], $skin['dieline_url'], $skin['internal_notes']);

                return $skin;
            }, $rawSkins);
        }

        return Response::ok([
            'device'          => $device,
            'specs'           => $this->details->listSpecs($id),
            'media'           => $this->details->listMedia($id),
            'compatibilities' => $this->details->listCompatibility($id, true),
            'skins'           => $rawSkins,
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createDevice(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $device = $this->devices->create($this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent(
                'catalog.device.created',
                'device',
                (string) $device['id'],
                context: ['canonical_key' => $device['canonical_key']]
            ));

            return Response::ok(['device' => $device], 201);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateDevice(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $device = $this->devices->update($id, $this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent(
                'catalog.device.updated',
                'device',
                (string) $id,
                context: ['lifecycle_status' => $device['lifecycle_status']]
            ));

            return Response::ok(['device' => $device]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteDevice(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $this->devices->delete($id);
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.device.deleted', 'device', (string) $id));

            return Response::ok(['deleted' => true, 'id' => $id]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function replaceSpecs(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $body = $this->body($request);
            $specs = is_array($body['specs'] ?? null) ? $body['specs'] : [];
            $saved = $this->details->replaceSpecs($id, $specs);
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.specs.updated', 'device', (string) $id, context: ['count' => count($saved)]));

            return Response::ok(['specs' => $saved]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function replaceMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $body = $this->body($request);
            $media = is_array($body['media'] ?? null) ? $body['media'] : [];
            $saved = $this->details->replaceMedia($id, $media);
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.media.updated', 'device', (string) $id, context: ['count' => count($saved)]));

            return Response::ok(['media' => $saved]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listCompatibility(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $items = $this->details->listCompatibility($id, true);

        return Response::ok(['items' => $items], 200, ['total' => count($items)]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function addCompatibility(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $edge = $this->details->addCompatibility($id, $this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent(
                'catalog.compatibility.linked',
                'device',
                (string) $id,
                context: ['target_type' => $edge['target_type'], 'target_id' => $edge['target_id'], 'relation' => $edge['relation_type']]
            ));

            return Response::ok(['compatibility' => $edge], 201);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteCompatibility(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $edgeId = (int) $request->get_param('edge_id');
            $this->details->deleteCompatibility($id, $edgeId);
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.compatibility.unlinked', 'device', (string) $id, context: ['edge_id' => $edgeId]));

            return Response::ok(['deleted' => true, 'edge_id' => $edgeId]);
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
