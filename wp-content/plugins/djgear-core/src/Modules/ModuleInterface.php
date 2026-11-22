<?php

declare(strict_types=1);

namespace DJGear\Core\Modules;

use DJGear\Core\Container\ContainerInterface;

/**
 * Contract implemented by every independent DJGear module plugin.
 */
interface ModuleInterface
{
    /**
     * Unique machine-readable module slug (e.g., "djgear-sms-center").
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * Human-readable display name.
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Semantic version string (e.g., "1.0.0").
     *
     * @return non-empty-string
     */
    public function version(): string;

    /**
     * List of required module IDs that must be registered and booted first.
     *
     * @return list<string>
     */
    public function dependencies(): array;

    /**
     * Whether the module is enabled for booting.
     */
    public function isEnabled(): bool;

    /**
     * Bind services into the shared container before any module boots.
     */
    public function register(ContainerInterface $container): void;

    /**
     * Attach WordPress hooks, REST controllers, and runtime behavior after dependencies are booted.
     */
    public function boot(ContainerInterface $container): void;
}
