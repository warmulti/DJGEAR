<?php

declare(strict_types=1);

namespace DJGear\CustomSkins;

use DJGear\Core\Container\ContainerInterface;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Modules\AbstractModule;
use DJGear\Core\Rest\RestKernel;
use DJGear\Core\Security\Authorizer;
use DJGear\CustomSkins\Cache\SkinsCacheService;
use DJGear\CustomSkins\Frontend\CustomerPortal;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Repositories\SkinPlanRepository;
use DJGear\CustomSkins\Repositories\SkinVariantRepository;
use DJGear\CustomSkins\Rest\AdminSkinCatalogController;
use DJGear\CustomSkins\Rest\AdminSkinOrdersController;
use DJGear\CustomSkins\Rest\CustomerSkinJobsController;
use DJGear\CustomSkins\Rest\PublicSkinsController;
use DJGear\CustomSkins\Services\ArtworkDpiValidator;
use DJGear\CustomSkins\Services\CustomerArtworkVault;
use DJGear\CustomSkins\Services\SkinOrderService;
use DJGear\CustomSkins\WooCommerce\WooCommerceSkinBridge;
use DJGear\DeviceCatalog\DeviceCatalogModule;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Security\PublicRateLimiter;
use wpdb;

/**
 * DJGear Custom Skins commercial module registered via djgear-core contracts.
 */
final class CustomSkinsModule extends AbstractModule
{
    public const MODULE_ID = 'djgear-custom-skins';
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly string $pluginFile,
    ) {
    }

    public function id(): string
    {
        return self::MODULE_ID;
    }

    public function name(): string
    {
        return 'DJGear Custom Skins';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return [DeviceCatalogModule::MODULE_ID];
    }

    public function register(ContainerInterface $container): void
    {
        $container->instance('skins.plugin_file', $this->pluginFile);
        $container->singleton(SkinsCacheService::class, static fn (): SkinsCacheService => new SkinsCacheService());
        $container->singleton(ArtworkDpiValidator::class, static fn (): ArtworkDpiValidator => new ArtworkDpiValidator());
        $container->singleton(CustomerArtworkVault::class, static fn (): CustomerArtworkVault => new CustomerArtworkVault());

        $container->singleton(SkinVariantRepository::class, static function (): SkinVariantRepository {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }
            return new SkinVariantRepository($wpdb);
        });

        $container->singleton(SkinPlanRepository::class, static function (): SkinPlanRepository {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }
            return new SkinPlanRepository($wpdb);
        });

        $container->singleton(SkinJobRepository::class, static function (): SkinJobRepository {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }
            return new SkinJobRepository($wpdb);
        });

        $container->singleton(SkinOrderService::class, static function (ContainerInterface $c): SkinOrderService {
            /** @var DeviceRepository $devices */
            $devices = $c->get(DeviceRepository::class);
            /** @var SkinVariantRepository $variants */
            $variants = $c->get(SkinVariantRepository::class);
            /** @var SkinPlanRepository $plans */
            $plans = $c->get(SkinPlanRepository::class);
            /** @var SkinJobRepository $jobs */
            $jobs = $c->get(SkinJobRepository::class);
            /** @var CustomerArtworkVault $vault */
            $vault = $c->get(CustomerArtworkVault::class);
            /** @var ArtworkDpiValidator $dpi */
            $dpi = $c->get(ArtworkDpiValidator::class);
            /** @var AuditLoggerInterface $audit */
            $audit = $c->get(AuditLoggerInterface::class);

            return new SkinOrderService($devices, $variants, $plans, $jobs, $vault, $dpi, $audit);
        });

        $container->singleton(WooCommerceSkinBridge::class, static function (ContainerInterface $c): WooCommerceSkinBridge {
            /** @var SkinJobRepository $jobs */
            $jobs = $c->get(SkinJobRepository::class);
            /** @var SkinOrderService $orderService */
            $orderService = $c->get(SkinOrderService::class);

            return new WooCommerceSkinBridge($jobs, $orderService);
        });

        $container->singleton(CustomerPortal::class, static function (ContainerInterface $c): CustomerPortal {
            /** @var SkinJobRepository $jobs */
            $jobs = $c->get(SkinJobRepository::class);

            return new CustomerPortal($jobs);
        });
    }

    public function boot(ContainerInterface $container): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain(
                'djgear-custom-skins',
                false,
                dirname(plugin_basename(DJGEAR_SKINS_FILE)) . '/languages'
            );
        }, 5);

        /** @var WooCommerceSkinBridge $wcBridge */
        $wcBridge = $container->get(WooCommerceSkinBridge::class);
        $wcBridge->registerHooks();

        /** @var CustomerPortal $portal */
        $portal = $container->get(CustomerPortal::class);
        $portal->registerHooks();

        add_action('djgear_core_register_rest_routes', static function (RestKernel $restKernel) use ($container): void {
            /** @var Authorizer $authorizer */
            $authorizer = $container->get(Authorizer::class);
            /** @var DeviceRepository $devices */
            $devices = $container->get(DeviceRepository::class);
            /** @var SkinVariantRepository $variants */
            $variants = $container->get(SkinVariantRepository::class);
            /** @var SkinPlanRepository $plans */
            $plans = $container->get(SkinPlanRepository::class);
            /** @var SkinJobRepository $jobs */
            $jobs = $container->get(SkinJobRepository::class);
            /** @var SkinOrderService $orderService */
            $orderService = $container->get(SkinOrderService::class);
            /** @var CustomerArtworkVault $vault */
            $vault = $container->get(CustomerArtworkVault::class);
            /** @var SkinsCacheService $cache */
            $cache = $container->get(SkinsCacheService::class);
            /** @var PublicRateLimiter $rateLimiter */
            $rateLimiter = $container->get(PublicRateLimiter::class);
            /** @var AuditLoggerInterface $audit */
            $audit = $container->get(AuditLoggerInterface::class);

            $restKernel->addController(new PublicSkinsController($devices, $variants, $plans, $cache, $rateLimiter));
            $restKernel->addController(new CustomerSkinJobsController($jobs, $orderService, $vault));
            $restKernel->addController(new AdminSkinCatalogController($authorizer, $variants, $plans, $cache, $audit));
            $restKernel->addController(new AdminSkinOrdersController($authorizer, $jobs, $orderService));
        });
    }
}
