<?php

declare(strict_types=1);

namespace DJGear\Core;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use DJGear\Core\Container\Container;
use DJGear\Core\Container\ContainerInterface;
use DJGear\Core\I18n\Translator;
use DJGear\Core\Logging\AuditLogger;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Logging\LoggerInterface;
use DJGear\Core\Logging\LogLevel;
use DJGear\Core\Logging\StructuredLogger;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\Core\Providers\ProviderRegistry;
use DJGear\Core\Rest\Controllers\AuditLogController;
use DJGear\Core\Rest\Controllers\HealthController;
use DJGear\Core\Rest\Controllers\ModulesController;
use DJGear\Core\Rest\RestKernel;
use DJGear\Core\Security\Authorizer;
use wpdb;

/**
 * DJGear Core application kernel.
 *
 * Boots the service container, localization, module registry, provider registry,
 * structured/audit logging, WooCommerce HPOS compatibility, and djgear/v1 REST API.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    private readonly Container $container;

    private bool $booted = false;

    public function __construct(
        private readonly string $pluginFile,
    ) {
        $this->container = new Container();
        $this->registerCoreServices();
    }

    public static function instance(?string $pluginFile = null): self
    {
        if (self::$instance === null) {
            if ($pluginFile === null) {
                throw new \LogicException('DJGear Core Plugin::instance() requires $pluginFile on first initialization.');
            }
            self::$instance = new self($pluginFile);
        }

        return self::$instance;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function modules(): ModuleRegistry
    {
        /** @var ModuleRegistry $registry */
        $registry = $this->container->get(ModuleRegistry::class);

        return $registry;
    }

    public function providers(): ProviderRegistry
    {
        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        return $registry;
    }

    public function translator(): Translator
    {
        /** @var Translator $translator */
        $translator = $this->container->get(Translator::class);

        return $translator;
    }

    public function logger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        return $logger;
    }

    /**
     * Attach WordPress and WooCommerce runtime hooks cleanly (idempotent).
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // Declare WooCommerce High-Performance Order Storage (HPOS) compatibility
        add_action('before_woocommerce_init', [$this, 'declareWooCommerceCompatibility']);

        // Load translations on `init` (WordPress 6.7+ JIT textdomain compliant)
        add_action('init', [$this, 'onInit'], 5);

        // Discover and boot registered modules after all plugins have loaded
        add_action('plugins_loaded', [$this, 'onPluginsLoaded'], 20);

        // Register versioned REST API endpoints under djgear/v1
        add_action('rest_api_init', [$this, 'onRestApiInit']);
    }

    public function declareWooCommerceCompatibility(): void
    {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', $this->pluginFile, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', $this->pluginFile, true);
        }
    }

    public function onInit(): void
    {
        $this->translator()->loadTextDomain();
    }

    public function onPluginsLoaded(): void
    {
        $modules = $this->modules();
        $modules->discoverViaWordPress();

        if (function_exists('do_action')) {
            do_action('djgear_core_register_providers', $this->providers(), $this->container);
        }

        $modules->bootAll($this->container);

        if (function_exists('do_action')) {
            do_action('djgear_core_booted', $this);
        }
    }

    public function onRestApiInit(): void
    {
        /** @var RestKernel $rest */
        $rest = $this->container->get(RestKernel::class);
        $rest->registerAll();
    }

    private function registerCoreServices(): void
    {
        $this->container->instance(self::class, $this);
        $this->container->instance('plugin.file', $this->pluginFile);
        $this->container->instance('plugin.dir', dirname($this->pluginFile));

        $this->container->singleton(Translator::class, function (): Translator {
            $relLang = basename(dirname($this->pluginFile)) . '/languages';

            return new Translator($relLang);
        });

        $this->container->singleton(Authorizer::class, static fn (): Authorizer => new Authorizer());

        $this->container->singleton(LoggerInterface::class, static function (): LoggerInterface {
            $isDebug = defined('WP_DEBUG') && (bool) constant('WP_DEBUG');

            return new StructuredLogger(
                channel: 'djgear.core',
                minLevel: $isDebug ? LogLevel::DEBUG : LogLevel::INFO,
            );
        });

        $this->container->singleton(AuditLoggerInterface::class, static function (ContainerInterface $c): AuditLoggerInterface {
            global $wpdb;
            if (!$wpdb instanceof wpdb) {
                throw new \RuntimeException('WordPress $wpdb is not initialized.');
            }

            /** @var LoggerInterface $logger */
            $logger = $c->get(LoggerInterface::class);

            return new AuditLogger($wpdb, $logger);
        });

        $this->container->singleton(ModuleRegistry::class, static fn (): ModuleRegistry => new ModuleRegistry());
        $this->container->singleton(ProviderRegistry::class, static fn (): ProviderRegistry => new ProviderRegistry());

        $this->container->singleton(RestKernel::class, static function (ContainerInterface $c): RestKernel {
            $kernel = new RestKernel();

            /** @var Authorizer $authorizer */
            $authorizer = $c->get(Authorizer::class);
            /** @var Translator $translator */
            $translator = $c->get(Translator::class);
            /** @var ProviderRegistry $providers */
            $providers = $c->get(ProviderRegistry::class);
            /** @var ModuleRegistry $modules */
            $modules = $c->get(ModuleRegistry::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $c->get(AuditLoggerInterface::class);

            $kernel->addController(new HealthController($authorizer, $translator, $providers));
            $kernel->addController(new ModulesController($authorizer, $modules));
            $kernel->addController(new AuditLogController($authorizer, $auditLogger));

            return $kernel;
        });
    }
}
