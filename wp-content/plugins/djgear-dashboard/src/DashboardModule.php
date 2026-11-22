<?php

declare(strict_types=1);

namespace DJGear\Dashboard;

use DJGear\Core\Container\ContainerInterface;
use DJGear\Core\I18n\Translator;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Modules\AbstractModule;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\Core\Rest\RestKernel;
use DJGear\Core\Security\Authorizer;
use DJGear\Dashboard\Admin\AdminPage;
use DJGear\Dashboard\Rest\CustomersController;
use DJGear\Dashboard\Rest\OrdersController;
use DJGear\Dashboard\Rest\OverviewController;
use DJGear\Dashboard\Rest\PreferencesController;
use DJGear\Dashboard\Rest\ProductsController;
use DJGear\Dashboard\Rest\SettingsController;
use DJGear\Dashboard\Services\CommerceSummaryService;
use DJGear\Dashboard\Services\SettingsService;

/**
 * DJGear Dashboard module integrated through djgear-core module contracts.
 */
final class DashboardModule extends AbstractModule
{
    public const MODULE_ID = 'djgear-dashboard';
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
        return 'DJGear Dashboard';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function register(ContainerInterface $container): void
    {
        $container->singleton(CommerceSummaryService::class, static fn (): CommerceSummaryService => new CommerceSummaryService());

        $container->singleton(SettingsService::class, static function (ContainerInterface $c): SettingsService {
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $c->get(AuditLoggerInterface::class);

            return new SettingsService($auditLogger);
        });

        $pluginFile = $this->pluginFile;
        $container->singleton(AdminPage::class, static function (ContainerInterface $c) use ($pluginFile): AdminPage {
            /** @var Translator $translator */
            $translator = $c->get(Translator::class);

            return new AdminPage($pluginFile, $translator);
        });
    }

    public function boot(ContainerInterface $container): void
    {
        /** @var AdminPage $adminPage */
        $adminPage = $container->get(AdminPage::class);
        $adminPage->registerHooks();

        add_action('djgear_core_register_rest_routes', static function (RestKernel $restKernel) use ($container): void {
            /** @var Authorizer $authorizer */
            $authorizer = $container->get(Authorizer::class);
            /** @var CommerceSummaryService $commerce */
            $commerce = $container->get(CommerceSummaryService::class);
            /** @var SettingsService $settings */
            $settings = $container->get(SettingsService::class);
            /** @var ModuleRegistry $modules */
            $modules = $container->get(ModuleRegistry::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);
            /** @var Translator $translator */
            $translator = $container->get(Translator::class);

            $restKernel->addController(new OverviewController($authorizer, $commerce, $modules, $auditLogger));
            $restKernel->addController(new ProductsController($authorizer, $commerce));
            $restKernel->addController(new OrdersController($authorizer, $commerce));
            $restKernel->addController(new CustomersController($authorizer, $commerce));
            $restKernel->addController(new SettingsController($authorizer, $settings));
            $restKernel->addController(new PreferencesController($authorizer, $translator));
        });
    }
}
