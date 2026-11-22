<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Security\PublicSkinsSerializer;
use DJGear\CustomSkins\Security\SkinCapabilities;
use DJGear\CustomSkins\Services\CustomerArtworkVault;
use DJGear\CustomSkins\Services\SkinOrderService;
use DJGear\CustomSkins\WooCommerce\WooCommerceSkinBridge;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Authenticated customer REST endpoints (`/djgear/v1/skins/jobs*` and `/djgear/v1/skins/my-jobs*`).
 *
 * Security Guarantees:
 * - Anonymous callers ALWAYS receive HTTP `401` on all endpoints.
 * - Cross-customer access ALWAYS receives HTTP `403`.
 * - Artwork upload is blocked (HTTP `400`) on Standard plans where `allows_artwork_upload = false`.
 * - Private file downloads enforce owner OR `djgear_view_skin_orders`, `realpath()` containment,
 *   `X-Content-Type-Options: nosniff`, and `Cache-Control: private, no-store`.
 */
final class CustomerSkinJobsController implements RouteInterface
{
    public const STREAM_HEADER_MARKER = 'X-DJGear-Stream-Path';

    public function __construct(
        private readonly SkinJobRepository $jobs,
        private readonly SkinOrderService $orderService,
        private readonly CustomerArtworkVault $vault,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        $uuidPattern = '(?P<uuid>[a-f0-9\-]{36})';

        register_rest_route($namespace, '/skins/jobs', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createJob'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/jobs/{$uuidPattern}/artwork", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'uploadArtwork'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/jobs/{$uuidPattern}/acknowledge-dpi", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'acknowledgeDpi'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/jobs/{$uuidPattern}/add-to-cart", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'addToCart'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, '/skins/my-jobs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'listMyJobs'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/my-jobs/{$uuidPattern}", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getMyJob'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/my-jobs/{$uuidPattern}/approve-proof", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'approveProof'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/my-jobs/{$uuidPattern}/request-revision", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'requestRevision'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        register_rest_route($namespace, "/skins/jobs/{$uuidPattern}/artworks/(?P<rev_id>\d+)/download", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'downloadArtwork'],
            'permission_callback' => [$this, 'requireAuthenticated'],
        ]);

        // Stream binary bytes cleanly on real HTTP requests when a download response carries our internal marker.
        add_filter('rest_pre_serve_request', [$this, 'maybeStreamPrivateFile'], 10, 4);
    }

    public function requireAuthenticated(): bool|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'djgear_skins_auth_required',
                __('Authentication is required to access custom skin orders and artwork uploads.', 'djgear-custom-skins'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function createJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $body = $request->get_json_params();
            if ($body === []) {
                $body = $request->get_body_params();
            }

            $deviceId = (int) ($body['device_id'] ?? 0);
            $variantId = (int) ($body['skin_variant_id'] ?? 0);
            $planId = (int) ($body['plan_id'] ?? 0);
            $notes = is_string($body['customer_notes'] ?? null) ? $body['customer_notes'] : '';

            $job = $this->orderService->createCustomerDraft(
                get_current_user_id(),
                $deviceId,
                $variantId,
                $planId,
                $notes
            );

            return Response::ok(PublicSkinsSerializer::customerJob($job), 201);
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function uploadArtwork(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $files = $request->get_file_params();
            $file = $files['file'] ?? $files['artwork'] ?? null;

            if (!is_array($file) || empty($file['tmp_name']) || !is_string($file['tmp_name'])) {
                return Response::error('djgear_skins_missing_file', 'Artwork file (multipart field "file") is required.', 400);
            }

            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
            if ($uploadError !== UPLOAD_ERR_OK) {
                return Response::error('djgear_skins_upload_error', 'File upload failed with error code ' . $uploadError . '.', 400);
            }

            $originalName = is_string($file['name'] ?? null) ? $file['name'] : 'artwork';
            $note = is_string($request->get_param('note')) ? (string) $request->get_param('note') : '';

            $result = $this->orderService->uploadCustomerArtwork(
                $uuid,
                get_current_user_id(),
                $file['tmp_name'],
                $originalName,
                $note
            );

            return Response::ok([
                'job'        => PublicSkinsSerializer::customerJob($result['job']),
                'artwork'    => PublicSkinsSerializer::customerArtwork($result['artwork'], $uuid),
                'dpi_report' => $result['dpi_report'],
            ], 201);
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function acknowledgeDpi(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $body = $request->get_json_params();
            $artworkId = isset($body['artwork_id']) && is_numeric($body['artwork_id']) ? (int) $body['artwork_id'] : null;

            if (!empty($body) && array_key_exists('acknowledged', $body) && !((bool) $body['acknowledged'])) {
                return Response::error('djgear_skins_ack_false', 'Explicit acknowledgment must be true.', 400);
            }

            $job = $this->orderService->acknowledgeLowDpi($uuid, get_current_user_id(), $artworkId);
            return Response::ok(PublicSkinsSerializer::customerJob($job));
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function addToCart(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $job = $this->orderService->assertReadyForCheckout($uuid, get_current_user_id());

            $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
            $variantSnap = is_array($snapshot['variant'] ?? null) ? $snapshot['variant'] : [];
            $productId = (int) ($variantSnap['wc_product_id'] ?? 0);
            $variationId = isset($variantSnap['wc_variation_id']) ? (int) $variantSnap['wc_variation_id'] : 0;

            if ($productId <= 0) {
                return Response::error('djgear_skins_invalid_product', 'Skin variant is not linked to a valid WooCommerce product.', 422);
            }

            $cartItemKey = null;
            if (function_exists('WC')) {
                $wc = WC();
                if ($wc->cart === null && function_exists('wc_load_cart')) {
                    wc_load_cart();
                }
                if ($wc->cart !== null) {
                    $added = $wc->cart->add_to_cart(
                        $productId,
                        1,
                        $variationId,
                        [],
                        [WooCommerceSkinBridge::CART_ITEM_KEY => $uuid]
                    );
                    if (is_string($added) && $added !== '') {
                        $cartItemKey = $added;
                    }
                }
            }

            return Response::ok([
                'job_uuid'      => $uuid,
                'wc_product_id' => $productId,
                'cart_item_key' => $cartItemKey,
                'cart_url'      => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/'),
                'checkout_url'  => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/'),
                'job'           => PublicSkinsSerializer::customerJob($job),
            ]);
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listMyJobs(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(50, (int) ($request->get_param('per_page') ?: 20)));

        $result = $this->jobs->listForCustomer(get_current_user_id(), $page, $perPage);
        $items = [];
        foreach ($result['items'] as $job) {
            $items[] = PublicSkinsSerializer::customerJob($job);
        }

        return Response::ok(['items' => $items], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getMyJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $job = $this->orderService->requireOwnedJob($uuid, get_current_user_id());
            return Response::ok(PublicSkinsSerializer::customerJob($job));
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function approveProof(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $body = $request->get_json_params();
            $comment = is_string($body['comment'] ?? null) ? $body['comment'] : '';
            $job = $this->orderService->approveProof($uuid, get_current_user_id(), $comment);
            return Response::ok(PublicSkinsSerializer::customerJob($job));
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function requestRevision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $body = $request->get_json_params();
            $comment = is_string($body['comment'] ?? null) ? $body['comment'] : '';
            $job = $this->orderService->requestProofRevision($uuid, get_current_user_id(), $comment);
            return Response::ok(PublicSkinsSerializer::customerJob($job));
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * Private authenticated download proxy for customer artwork and designer proofs.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function downloadArtwork(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $uuid = (string) $request->get_param('uuid');
            $revId = (int) $request->get_param('rev_id');
            $currentUserId = get_current_user_id();

            $job = $this->jobs->findByUuid($uuid, false);
            if ($job === null) {
                throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
            }

            $isOwner = (int) $job['customer_user_id'] === $currentUserId;
            $isStaff = current_user_can(SkinCapabilities::VIEW_SKIN_ORDERS);
            if (!$isOwner && !$isStaff) {
                throw SkinValidationException::forbidden('You do not have permission to download this artwork.');
            }

            $artwork = $this->jobs->findArtworkById($revId);
            if ($artwork === null || (int) $artwork['job_id'] !== (int) $job['id']) {
                throw SkinValidationException::notFound('artwork', 'Artwork revision not found.');
            }

            $safePath = $this->vault->resolveSafePath((string) $artwork['storage_rel_path']);
            $filename = str_replace(['"', "\r", "\n"], '', (string) $artwork['original_filename']);
            $mime = (string) $artwork['mime_type'];

            $response = new WP_REST_Response([
                'ok'           => true,
                'filename'     => $filename,
                'mime_type'    => $mime,
                'size_bytes'   => (int) $artwork['file_size_bytes'],
                'sha256_hash'  => (string) $artwork['sha256_hash'],
                '_stream_path' => $safePath,
            ], 200);

            $response->header('Content-Type', $mime);
            $response->header('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Cache-Control', 'private, no-store, max-age=0');

            return $response;
        } catch (SkinValidationException $e) {
            return $this->toError($e);
        }
    }

    /**
     * Stream raw bytes when WordPress serves our download response over HTTP.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function maybeStreamPrivateFile(
        bool $served,
        \WP_HTTP_Response $result,
        WP_REST_Request $request,
        WP_REST_Server $server,
    ): bool {
        if ($served || !str_contains($request->get_route(), '/skins/jobs/') || !str_ends_with($request->get_route(), '/download')) {
            return $served;
        }

        $data = $result->get_data();
        if (!is_array($data) || empty($data['_stream_path']) || !is_string($data['_stream_path'])) {
            return $served;
        }

        $path = $data['_stream_path'];
        if (!is_file($path) || !is_readable($path)) {
            return $served;
        }

        $mime = is_string($data['mime_type'] ?? null) ? $data['mime_type'] : 'application/octet-stream';
        $filename = is_string($data['filename'] ?? null) ? $data['filename'] : 'artwork';

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
        }

        readfile($path);
        return true;
    }

    private function toError(SkinValidationException $e): WP_Error
    {
        return Response::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->details);
    }
}
