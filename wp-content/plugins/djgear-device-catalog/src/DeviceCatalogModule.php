<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog;

use DJGear\Core\Container\ContainerInterface;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Modules\AbstractModule;
use DJGear\Core\Rest\RestKernel;
use DJGear\Core\Security\Authorizer;
use DJGear\DeviceCatalog\Cache\CatalogCacheService;
use DJGear\DeviceCatalog\Repositories\CatalogDetailsRepository;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\SkinDefinitionRepository;
use DJGear\DeviceCatalog\Repositories\TaxonomyRepository;
use DJGear\DeviceCatalog\Rest\BrandsCategoriesController;
use DJGear\DeviceCatalog\Rest\DevicesController;
use DJGear\DeviceCatalog\Rest\ImportExportController;
use DJGear\DeviceCatalog\Rest\PublicCatalogController;
use DJGear\DeviceCatalog\Rest\SkinDefinitionsController;
use DJGear\DeviceCatalog\Security\DielineProtection;
use DJGear\DeviceCatalog\Security\PublicRateLimiter;
use DJGear\DeviceCatalog\Services\ImportExportService;
use DJGear\DeviceCatalog\Services\WooCommerceProductHydrator;
use DJGear\DeviceCatalog\Taxonomy\CatalogPostTypes;
use wpdb;

/**
 * DJGear Device Catalog module integrated cleanly through djgear-core contracts.
 */
final class DeviceCatalogModule extends AbstractModule
{
    public const MODULE_ID = 'djgear-device-catalog';
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
        return 'DJGear Device Catalog';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function register(ContainerInterface $container): void
    {
        $container->instance('catalog.plugin_file', $this->pluginFile);
        $container->singleton(CatalogCacheService::class, static fn (): CatalogCacheService => new CatalogCacheService());
        $container->singleton(DielineProtection::class, static fn (): DielineProtection => new DielineProtection());
        $container->singleton(PublicRateLimiter::class, static fn (): PublicRateLimiter => new PublicRateLimiter());
        $container->singleton(TaxonomyRepository::class, static fn (): TaxonomyRepository => new TaxonomyRepository());
        $container->singleton(WooCommerceProductHydrator::class, static fn (): WooCommerceProductHydrator => new WooCommerceProductHydrator());

        $container->singleton(DeviceRepository::class, static function (ContainerInterface $c): DeviceRepository {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }
            /** @var TaxonomyRepository $tax */
            $tax = $c->get(TaxonomyRepository::class);

            return new DeviceRepository($wpdb, $tax);
        });

        $container->singleton(CatalogDetailsRepository::class, static function (ContainerInterface $c): CatalogDetailsRepository {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }
            /** @var DeviceRepository $devices */
            $devices = $c->get(DeviceRepository::class);
            /** @var WooCommerceProductHydrator $wc */
            $wc = $c->get(WooCommerceProductHydrator::class);

            return new CatalogDetailsRepository($wpdb, $devices, $wc);
        });

        $container->singleton(SkinDefinitionRepository::class, static function (ContainerInterface $c): SkinDefinitionRepository {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }
            /** @var DeviceRepository $devices */
            $devices = $c->get(DeviceRepository::class);

            return new SkinDefinitionRepository($wpdb, $devices);
        });

        $container->singleton(ImportExportService::class, static function (ContainerInterface $c): ImportExportService {
            /** @var TaxonomyRepository $tax */
            $tax = $c->get(TaxonomyRepository::class);
            /** @var DeviceRepository $devices */
            $devices = $c->get(DeviceRepository::class);
            /** @var CatalogDetailsRepository $details */
            $details = $c->get(CatalogDetailsRepository::class);
            /** @var SkinDefinitionRepository $skins */
            $skins = $c->get(SkinDefinitionRepository::class);
            /** @var CatalogCacheService $cache */
            $cache = $c->get(CatalogCacheService::class);
            /** @var AuditLoggerInterface $audit */
            $audit = $c->get(AuditLoggerInterface::class);

            return new ImportExportService($tax, $devices, $details, $skins, $cache, $audit);
        });
    }

    public function boot(ContainerInterface $container): void
    {
        add_action('init', [CatalogPostTypes::class, 'register'], 5);

        /** @var DielineProtection $dielineProtection */
        $dielineProtection = $container->get(DielineProtection::class);
        $dielineProtection->registerHooks();

        // Keep compatibility references clean if a WooCommerce product is permanently deleted
        add_action('before_delete_post', static function (int $postId) use ($container): void {
            if (get_post_type($postId) !== 'product') {
                return;
            }
            /** @var CatalogDetailsRepository $details */
            $details = $container->get(CatalogDetailsRepository::class);
            /** @var CatalogCacheService $cache */
            $cache = $container->get(CatalogCacheService::class);
            $details->purgeWooCommerceProductLinks($postId);
            $cache->invalidatePublicCache();
        });

        add_action('djgear_core_register_rest_routes', static function (RestKernel $restKernel) use ($container): void {
            /** @var Authorizer $authorizer */
            $authorizer = $container->get(Authorizer::class);
            /** @var TaxonomyRepository $tax */
            $tax = $container->get(TaxonomyRepository::class);
            /** @var DeviceRepository $devices */
            $devices = $container->get(DeviceRepository::class);
            /** @var CatalogDetailsRepository $details */
            $details = $container->get(CatalogDetailsRepository::class);
            /** @var SkinDefinitionRepository $skins */
            $skins = $container->get(SkinDefinitionRepository::class);
            /** @var ImportExportService $importExport */
            $importExport = $container->get(ImportExportService::class);
            /** @var CatalogCacheService $cache */
            $cache = $container->get(CatalogCacheService::class);
            /** @var PublicRateLimiter $rateLimiter */
            $rateLimiter = $container->get(PublicRateLimiter::class);
            /** @var AuditLoggerInterface $audit */
            $audit = $container->get(AuditLoggerInterface::class);

            $restKernel->addController(new BrandsCategoriesController($authorizer, $tax, $devices, $cache, $audit));
            $restKernel->addController(new DevicesController($authorizer, $devices, $details, $skins, $cache, $audit));
            $restKernel->addController(new SkinDefinitionsController($authorizer, $skins, $cache, $audit));
            $restKernel->addController(new ImportExportController($authorizer, $importExport));
            $restKernel->addController(new PublicCatalogController($tax, $devices, $details, $skins, $cache, $rateLimiter));
        });
    }
}
