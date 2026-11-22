<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\CustomSkins\Cache\SkinsCacheService;
use DJGear\CustomSkins\Repositories\SkinPlanRepository;
use DJGear\CustomSkins\Repositories\SkinVariantRepository;
use DJGear\CustomSkins\Security\PublicSkinsSerializer;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Security\PublicRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Redis-cached, rate-limited, allowlisted public storefront options endpoint (`/djgear/v1/skins/options`).
 * Exposes ONLY active skin variants for active devices and active ordering plans.
 */
final class PublicSkinsController implements RouteInterface
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly SkinVariantRepository $variants,
        private readonly SkinPlanRepository $plans,
        private readonly SkinsCacheService $cache,
        private readonly PublicRateLimiter $rateLimiter,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/skins/options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getOptions'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function getOptions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $quota = $this->rateLimiter->check();
        if ($quota instanceof WP_Error) {
            return $quota;
        }

        $deviceId = max(0, (int) $request->get_param('device_id'));
        $cacheKey = sprintf('skins:options:device:%d', $deviceId);

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember($cacheKey, function () use ($deviceId): array {
            $deviceSummary = null;
            $publicVariants = [];

            if ($deviceId > 0) {
                $device = $this->devices->findById($deviceId);
                if ($device !== null && (string) ($device['lifecycle_status'] ?? '') === 'active') {
                    $deviceSummary = [
                        'device_id'  => (int) ($device['id'] ?? $deviceId),
                        'slug'       => (string) ($device['slug'] ?? ''),
                        'model_code' => (string) ($device['model_code'] ?? ''),
                        'name_fa'    => (string) ($device['name_fa'] ?? ''),
                        'name_en'    => (string) ($device['name_en'] ?? ''),
                    ];
                    foreach ($this->variants->listActiveForDevice($deviceId) as $variant) {
                        $publicVariants[] = PublicSkinsSerializer::publicVariant($variant);
                    }
                }
            }

            $publicPlans = [];
            foreach ($this->plans->listAll(true) as $plan) {
                $publicPlans[] = PublicSkinsSerializer::publicPlan($plan);
            }

            return [
                'device'   => $deviceSummary,
                'variants' => $publicVariants,
                'plans'    => $publicPlans,
                'dpi_policy' => [
                    'min_dpi'         => 180,
                    'recommended_dpi' => 300,
                    'allowed_formats' => ['PNG', 'JPEG', 'TIFF', 'PDF'],
                    'max_file_mb'     => 25,
                ],
            ];
        });

        $response = Response::ok($payload);
        $response->header('Cache-Control', 'public, max-age=120');
        $response->header('X-RateLimit-Limit', (string) $quota['limit']);
        $response->header('X-RateLimit-Remaining', (string) $quota['remaining']);

        return $response;
    }
}
