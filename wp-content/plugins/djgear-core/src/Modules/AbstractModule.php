<?php

declare(strict_types=1);

namespace DJGear\Core\Modules;

use DJGear\Core\Container\ContainerInterface;

/**
 * Convenience base class for DJGear modules with safe defaults.
 */
abstract class AbstractModule implements ModuleInterface
{
    public function dependencies(): array
    {
        return [];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function register(ContainerInterface $container): void
    {
        // Modules override when binding services into the container.
    }

    public function boot(ContainerInterface $container): void
    {
        // Modules override when hooking into WordPress/WooCommerce runtime.
    }
}
