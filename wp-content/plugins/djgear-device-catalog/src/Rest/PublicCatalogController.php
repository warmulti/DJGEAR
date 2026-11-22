<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\DeviceCatalog\Cache\CatalogCacheService;
use DJGear\DeviceCatalog\Repositories\CatalogDetailsRepository;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\SkinDefinitionRepository;
use DJGear\DeviceCatalog\Repositories\TaxonomyRepository;
use DJGear\DeviceCatalog\Security\PublicCatalogSerializer;
use DJGear\DeviceCatalog\Security\PublicRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Redis-cached, rate-limited, strictly allowlisted public read API (/djgear/v1/catalog/public/*).
 *
 * Security Guarantees:
 * - Exposes ONLY active/published catalog entries.
 * - Passes EVERY response through `PublicCatalogSerializer` allowlists (never leaks dielines,
 *   internal_notes, edit_url, or draft/deprecated items).
 * - Automatically invalidated via `CatalogCacheService` version salt on any catalog write.
 */
final class PublicCatalogController implements RouteInterface
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly DeviceRepository $devices,
        private readonly CatalogDetailsRepository $details,
        private readonly SkinDefinitionRepository $skins,
        private readonly CatalogCacheService $cache,
        private readonly PublicRateLimiter $rateLimiter,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/catalog/public/devices', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'listDevices'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/catalog/public/devices/(?P<slug>[a-z0-9\-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getDeviceBySlug'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/catalog/public/brands', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'listBrands'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/catalog/public/categories', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'listCategories'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/catalog/public/filters', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getFilters'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/catalog/public/wc-product/(?P<wc_id>\d+)/devices', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getDevicesForWcProduct'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listDevices(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $q = is_string($request->get_param('q')) ? substr(trim((string) $request->get_param('q')), 0, 120) : '';
        $brand = is_scalar($request->get_param('brand')) ? substr(trim((string) $request->get_param('brand')), 0, 80) : '';
        $category = is_scalar($request->get_param('category')) ? substr(trim((string) $request->get_param('category')), 0, 80) : '';
        $skinParam = $request->get_param('skin_ready');
        $skinReady = ($skinParam === '1' || $skinParam === 'true') ? true : (($skinParam === '0' || $skinParam === 'false') ? false : null);
        $specParam = $request->get_param('spec');
        $specFilters = is_array($specParam) ? $specParam : [];
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(30, (int) ($request->get_param('per_page') ?: 20)));

        $cacheKey = 'public:devices:' . json_encode([$q, $brand, $category, $skinReady, $specFilters, $page, $perPage]);
        $payload = $this->cache->remember($cacheKey, function () use ($q, $brand, $category, $skinReady, $specFilters, $page, $perPage): array {
            $result = $this->devices->search([
                'q'           => $q,
                'brand'       => $brand,
                'category'    => $category,
                'skin_ready'  => $skinReady,
                'spec'        => $specFilters,
                'page'        => $page,
                'per_page'    => $perPage,
                'active_only' => true,
            ]);

            return [
                'items' => array_map([PublicCatalogSerializer::class, 'deviceSummary'], $result['items']),
                'meta'  => ['total' => $result['total'], 'page' => $result['page'], 'per_page' => $result['per_page']],
            ];
        });

        return $this->withRateHeaders(Response::ok(['items' => $payload['items']], 200, $payload['meta']), $quota);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getDeviceBySlug(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $slug = sanitize_title((string) $request->get_param('slug'));
        $payload = $this->cache->remember('public:device:' . $slug, function () use ($slug): ?array {
            $device = $this->devices->findBySlug($slug, true);
            if ($device === null) {
                return null;
            }

            $id = (int) $device['id'];

            return PublicCatalogSerializer::deviceDetail(
                $device,
                $this->details->listSpecs($id),
                $this->details->listMedia($id),
                $this->details->listCompatibility($id, true),
                $this->skins->listForDevice($id, true)
            );
        });

        if ($payload === null) {
            return Response::error('djgear_catalog_not_found', 'Device not found.', 404);
        }

        return $this->withRateHeaders(Response::ok(['device' => $payload]), $quota);
    }

    public function listBrands(): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $items = $this->cache->remember('public:brands', fn (): array => $this->taxonomies->listBrands());

        return $this->withRateHeaders(Response::ok(['items' => $items], 200, ['total' => count($items)]), $quota);
    }

    public function listCategories(): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $items = $this->cache->remember('public:categories', fn (): array => $this->taxonomies->listCategories());

        return $this->withRateHeaders(Response::ok(['items' => $items], 200, ['total' => count($items)]), $quota);
    }

    public function getFilters(): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $payload = $this->cache->remember('public:filters', fn (): array => [
            'brands'     => $this->taxonomies->listBrands(),
            'categories' => $this->taxonomies->listCategories(),
            'specs'      => $this->devices->availableSpecFacets(true),
        ]);

        return $this->withRateHeaders(Response::ok($payload), $quota);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getDevicesForWcProduct(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $wcId = (int) $request->get_param('wc_id');
        $items = $this->cache->remember('public:wc:' . $wcId, function () use ($wcId): array {
            $raw = $this->details->findDevicesForWooCommerceProduct($wcId, true);

            return array_map(static function (array $device): array {
                $summary = PublicCatalogSerializer::deviceSummary($device);
                $summary['compatibility_relation'] = $device['compatibility_relation'] ?? '';
                $summary['fit_confidence'] = $device['fit_confidence'] ?? 'verified';

                return $summary;
            }, $raw);
        });

        return $this->withRateHeaders(Response::ok(['items' => $items], 200, ['total' => count($items)]), $quota);
    }

    /**
     * @param array{limit: int, remaining: int, window: int} $quota
     */
    private function withRateHeaders(WP_REST_Response $response, array $quota): WP_REST_Response
    {
        if ($quota['limit'] > 0) {
            $response->header('X-RateLimit-Limit', (string) $quota['limit']);
            $response->header('X-RateLimit-Remaining', (string) $quota['remaining']);
        }
        $response->header('Cache-Control', 'public, max-age=60');

        return $response;
    }
}
