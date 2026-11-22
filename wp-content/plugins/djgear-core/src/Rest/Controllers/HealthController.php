<?php

declare(strict_types=1);

namespace DJGear\Core\Rest\Controllers;

use Automattic\WooCommerce\Utilities\OrderUtil;
use DJGear\Core\I18n\Translator;
use DJGear\Core\Plugin;
use DJGear\Core\Providers\ProviderRegistry;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes platform runtime diagnostics at GET /wp-json/djgear/v1/health.
 */
final class HealthController implements RouteInterface
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Translator $translator,
        private readonly ProviderRegistry $providers,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::VIEW_DASHBOARD),
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $wcActive = class_exists('WooCommerce');
        $wcVersion = defined('WC_VERSION') ? (string) constant('WC_VERSION') : null;
        $hposEnabled = $wcActive
            && class_exists(OrderUtil::class)
            && OrderUtil::custom_orders_table_usage_is_enabled();

        $environment = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : 'production';

        $wpVersion = function_exists('get_bloginfo')
            ? (string) get_bloginfo('version')
            : '';

        return Response::ok([
            'status'      => 'healthy',
            'core'        => [
                'version'     => Plugin::VERSION,
                'environment' => $environment,
                'php_version' => PHP_VERSION,
                'wp_version'  => $wpVersion,
            ],
            'woocommerce' => [
                'active'                 => $wcActive,
                'version'                => $wcVersion,
                'hpos_compatible'        => true,
                'hpos_tables_enabled'    => $hposEnabled,
                'source_of_truth_policy' => 'wordpress_woocommerce_only',
            ],
            'i18n'        => $this->translator->metadata(),
            'providers'   => $this->providers->summary(),
            'object_cache' => [
                'external' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            ],
        ]);
    }
}
