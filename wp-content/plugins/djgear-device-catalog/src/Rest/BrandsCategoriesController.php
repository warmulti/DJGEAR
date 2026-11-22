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
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\TaxonomyRepository;
use DJGear\DeviceCatalog\Security\CatalogCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin CRUD REST controller for Manufacturers (/catalog/brands) and Device Categories (/catalog/categories).
 */
final class BrandsCategoriesController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly TaxonomyRepository $taxonomies,
        private readonly DeviceRepository $devices,
        private readonly CatalogCacheService $cache,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/catalog/brands', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listBrands'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::VIEW_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createBrand'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/catalog/brands/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateBrand'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteBrand'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/catalog/categories', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listCategories'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::VIEW_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createCategory'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/catalog/categories/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateCategory'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteCategory'],
                'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::MANAGE_CATALOG),
            ],
        ]);
    }

    public function listBrands(): WP_REST_Response
    {
        $items = $this->taxonomies->listBrands();

        return Response::ok(['items' => $items], 200, ['total' => count($items)]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createBrand(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $brand = $this->taxonomies->createBrand($this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.brand.created', 'brand', (string) $brand['id'], context: ['slug' => $brand['slug']]));

            return Response::ok(['brand' => $brand], 201);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateBrand(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $brand = $this->taxonomies->updateBrand($id, $this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.brand.updated', 'brand', (string) $id));

            return Response::ok(['brand' => $brand]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteBrand(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $this->taxonomies->deleteBrand($id, $this->devices->countByBrand($id));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.brand.deleted', 'brand', (string) $id));

            return Response::ok(['deleted' => true, 'id' => $id]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    public function listCategories(): WP_REST_Response
    {
        $items = $this->taxonomies->listCategories();

        return Response::ok(['items' => $items], 200, ['total' => count($items)]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createCategory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $cat = $this->taxonomies->createCategory($this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.category.created', 'category', (string) $cat['id'], context: ['slug' => $cat['slug']]));

            return Response::ok(['category' => $cat], 201);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateCategory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $cat = $this->taxonomies->updateCategory($id, $this->body($request));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.category.updated', 'category', (string) $id));

            return Response::ok(['category' => $cat]);
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteCategory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $this->taxonomies->deleteCategory($id, $this->devices->countByCategory($id));
            $this->cache->invalidatePublicCache();
            $this->auditLogger->record(new AuditEvent('catalog.category.deleted', 'category', (string) $id));

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
