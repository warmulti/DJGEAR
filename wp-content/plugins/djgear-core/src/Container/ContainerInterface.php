<?php

declare(strict_types=1);

namespace DJGear\Core\Container;

use Closure;

/**
 * Lightweight dependency injection container contract for DJGear modules.
 */
interface ContainerInterface
{
    /**
     * Register a transient factory binding (new instance per get()).
     *
     * @param non-empty-string $id
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function bind(string $id, Closure $factory): void;

    /**
     * Register a lazy singleton factory binding (resolved once and cached).
     *
     * @param non-empty-string $id
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void;

    /**
     * Register an already-constructed service instance.
     *
     * @param non-empty-string $id
     */
    public function instance(string $id, mixed $value): void;

    /**
     * Determine whether a service identifier is registered.
     *
     * @param non-empty-string $id
     */
    public function has(string $id): bool;

    /**
     * Resolve and return a registered service.
     *
     * @param non-empty-string $id
     *
     * @throws ContainerException When the service is missing or has a circular dependency.
     */
    public function get(string $id): mixed;

    /**
     * Return all registered service identifiers.
     *
     * @return list<string>
     */
    public function keys(): array;
}
