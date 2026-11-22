<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Rest;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\CustomSkins\Cache\SkinsCacheService;
use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Repositories\SkinPlanRepository;
use DJGear\CustomSkins\Repositories\SkinVariantRepository;
use DJGear\CustomSkins\Security\SkinCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin CRUD REST controller for Skin Variants (`/skins/admin/variants`) and Ordering Plans (`/skins/admin/plans`).
 *
 * Role Split:
 * - Variants write: `djgear_manage_skin_catalog` (`administrator`, `shop_manager`).
 * - Plans write: `djgear_manage_skin_plans` (`administrator` ONLY).
 */
final class AdminSkinCatalogController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly SkinVariantRepository $variants,
        private readonly SkinPlanRepository $plans,
        private readonly SkinsCacheService $cache,
        private readonly AuditLoggerInterface $audit,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/skins/admin/variants', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listVariants'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createVariant'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/skins/admin/variants/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateVariant'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_CATALOG),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteVariant'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_CATALOG),
            ],
        ]);

        register_rest_route($namespace, '/skins/admin/plans', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'listPlans'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_PLANS),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createPlan'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_PLANS),
            ],
        ]);

        register_rest_route($namespace, '/skins/admin/plans/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updatePlan'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_PLANS),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deletePlan'],
                'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_PLANS),
            ],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listVariants(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 25)));
        $filters = [
            'device_id' => $request->get_param('device_id'),
            'status'    => $request->get_param('status'),
            'q'         => $request->get_param('q'),
        ];

        $result = $this->variants->list($filters, $page, $perPage);
        return Response::ok(['items' => $result['items']], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createVariant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $body = $request->get_json_params();
            $created = $this->variants->create($body);
            $this->cache->invalidatePublicCache();

            $this->audit->record(new AuditEvent(
                action: 'skins.variant.created',
                resourceType: 'skin_variant',
                resourceId: (string) $created['id'],
                actorId: get_current_user_id(),
                context: [
                    'device_id'  => $created['device_id'],
                    'model_code' => $created['model_code'],
                    'color_code' => $created['color_code'],
                ],
            ));

            return Response::ok($created, 201);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateVariant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $body = $request->get_json_params();
            $updated = $this->variants->update($id, $body);
            $this->cache->invalidatePublicCache();

            $this->audit->record(new AuditEvent(
                action: 'skins.variant.updated',
                resourceType: 'skin_variant',
                resourceId: (string) $id,
                actorId: get_current_user_id(),
            ));

            return Response::ok($updated);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteVariant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $this->variants->delete($id);
            $this->cache->invalidatePublicCache();

            $this->audit->record(new AuditEvent(
                action: 'skins.variant.deleted',
                resourceType: 'skin_variant',
                resourceId: (string) $id,
                actorId: get_current_user_id(),
            ));

            return Response::ok(['deleted' => true, 'id' => $id]);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listPlans(WP_REST_Request $request): WP_REST_Response
    {
        $items = $this->plans->listAll(false);
        return Response::ok(['items' => $items], 200, ['total' => count($items)]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createPlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $body = $request->get_json_params();
            $created = $this->plans->create($body);
            $this->cache->invalidatePublicCache();

            $this->audit->record(new AuditEvent(
                action: 'skins.plan.created',
                resourceType: 'skin_plan',
                resourceId: (string) $created['id'],
                actorId: get_current_user_id(),
                context: ['slug' => $created['slug'], 'plan_type' => $created['plan_type']],
            ));

            return Response::ok($created, 201);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updatePlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $body = $request->get_json_params();
            $updated = $this->plans->update($id, $body);
            $this->cache->invalidatePublicCache();

            $this->audit->record(new AuditEvent(
                action: 'skins.plan.updated',
                resourceType: 'skin_plan',
                resourceId: (string) $id,
                actorId: get_current_user_id(),
            ));

            return Response::ok($updated);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deletePlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $this->plans->delete($id);
            $this->cache->invalidatePublicCache();

            $this->audit->record(new AuditEvent(
                action: 'skins.plan.deleted',
                resourceType: 'skin_plan',
                resourceId: (string) $id,
                actorId: get_current_user_id(),
            ));

            return Response::ok(['deleted' => true, 'id' => $id]);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }
}
