<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Security\PublicSkinsSerializer;
use DJGear\CustomSkins\Security\SkinCapabilities;
use DJGear\CustomSkins\Services\SkinOrderService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin & Production Queue REST controller (`/djgear/v1/skins/admin/jobs*`).
 */
final class AdminSkinOrdersController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly SkinJobRepository $jobs,
        private readonly SkinOrderService $orderService,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/skins/admin/jobs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'listJobs'],
            'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::VIEW_SKIN_ORDERS),
        ]);

        register_rest_route($namespace, '/skins/admin/jobs/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getJob'],
            'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::VIEW_SKIN_ORDERS),
        ]);

        register_rest_route($namespace, '/skins/admin/jobs/(?P<id>\d+)/proof', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'uploadProof'],
            'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_ORDERS),
        ]);

        register_rest_route($namespace, '/skins/admin/jobs/(?P<id>\d+)/transition', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'transitionJob'],
            'permission_callback' => $this->authorizer->restPermissionCallback(SkinCapabilities::MANAGE_SKIN_ORDERS),
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listJobs(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 20)));
        $filters = [
            'status'      => $request->get_param('status'),
            'wc_order_id' => $request->get_param('wc_order_id'),
            'q'           => $request->get_param('q'),
        ];

        $result = $this->jobs->listAdminQueue($filters, $page, $perPage);
        $items = array_map([$this, 'formatAdminJob'], $result['items']);

        return Response::ok([
            'items'  => $items,
            'counts' => $result['counts'],
        ], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $job = $this->jobs->findById($id, true);
        if ($job === null) {
            return Response::error('djgear_skins_not_found', 'Custom skin job not found.', 404);
        }

        return Response::ok($this->formatAdminJob($job));
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function uploadProof(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $files = $request->get_file_params();
            $file = $files['file'] ?? $files['proof'] ?? null;

            if (!is_array($file) || empty($file['tmp_name']) || !is_string($file['tmp_name'])) {
                return Response::error('djgear_skins_missing_file', 'Designer proof file (multipart field "file") is required.', 400);
            }

            $originalName = is_string($file['name'] ?? null) ? $file['name'] : 'proof.png';
            $noteFa = is_string($request->get_param('note_fa')) ? (string) $request->get_param('note_fa') : '';
            $noteEn = is_string($request->get_param('note_en')) ? (string) $request->get_param('note_en') : '';

            $result = $this->orderService->uploadDesignerProof(
                $id,
                get_current_user_id(),
                $file['tmp_name'],
                $originalName,
                $noteFa,
                $noteEn
            );

            $uuid = (string) $result['job']['job_uuid'];
            return Response::ok([
                'job'   => $this->formatAdminJob($result['job']),
                'proof' => PublicSkinsSerializer::customerArtwork($result['proof'], $uuid),
            ], 201);
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function transitionJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = (int) $request->get_param('id');
            $body = $request->get_json_params();
            $isAdmin = current_user_can('manage_options');

            $updated = $this->orderService->adminTransition($id, get_current_user_id(), $isAdmin, $body);
            return Response::ok($this->formatAdminJob($updated));
        } catch (SkinValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
        }
    }

    /**
     * Format admin job payload with download URLs while never exposing raw filesystem paths.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function formatAdminJob(array $job): array
    {
        $uuid = (string) ($job['job_uuid'] ?? '');
        if (is_array($job['artworks'] ?? null)) {
            $safeArtworks = [];
            foreach ($job['artworks'] as $rev) {
                if (is_array($rev)) {
                    $safeArtworks[] = PublicSkinsSerializer::customerArtwork($rev, $uuid);
                }
            }
            $job['artworks'] = $safeArtworks;
        }

        return $job;
    }
}
