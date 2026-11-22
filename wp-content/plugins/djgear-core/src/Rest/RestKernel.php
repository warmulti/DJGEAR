<?php

declare(strict_types=1);

namespace DJGear\Core\Rest;

/**
 * Registers versioned REST API controllers under the `djgear/v1` namespace.
 */
final class RestKernel
{
    public const NAMESPACE_V1 = 'djgear/v1';

    /** @var list<RouteInterface> */
    private array $controllers = [];

    public function addController(RouteInterface $controller): void
    {
        $this->controllers[] = $controller;
    }

    /**
     * @return list<RouteInterface>
     */
    public function controllers(): array
    {
        return $this->controllers;
    }

    /**
     * Register core and module REST controllers when WordPress triggers `rest_api_init`.
     */
    public function registerAll(): void
    {
        if (function_exists('do_action')) {
            do_action('djgear_core_register_rest_routes', $this, self::NAMESPACE_V1);
        }

        foreach ($this->controllers as $controller) {
            $controller->registerRoutes(self::NAMESPACE_V1);
        }
    }
}
