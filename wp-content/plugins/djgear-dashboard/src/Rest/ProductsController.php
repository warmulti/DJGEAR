<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use DJGear\Dashboard\Services\CommerceSummaryService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes WooCommerce products at GET /wp-json/djgear/v1/dashboard/products.
 */
final class ProductsController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly CommerceSummaryService $commerce,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/dashboard/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50, 'sanitize_callback' => 'absint'],
                'search'   => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'status'   => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);
    }

    public function checkPermission(): bool|WP_Error
    {
        $baseCheck = ($this->authorizer->restPermissionCallback(Capabilities::VIEW_DASHBOARD))();
        if ($baseCheck !== true) {
            return $baseCheck;
        }

        if (!$this->authorizer->currentUserCanAny(['edit_products', 'manage_woocommerce'])) {
            return Response::error(
                'djgear_rest_forbidden',
                'Missing WooCommerce product read capability (edit_products).',
                403
            );
        }

        return true;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(50, (int) $request->get_param('per_page')));
        $search = is_string($request->get_param('search')) ? (string) $request->get_param('search') : '';
        $status = is_string($request->get_param('status')) ? (string) $request->get_param('status') : '';

        $result = $this->commerce->listProducts($page, $perPage, $search, $status);

        return Response::ok(
            [
                'items'    => $result['items'],
                'currency' => $result['currency'],
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
