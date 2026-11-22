<?php

declare(strict_types=1);

namespace DJGear\Core\Container;

use Closure;

/**
 * Small, deterministic, testable service container with circular dependency detection.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, array{factory: Closure(ContainerInterface): mixed, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function bind(string $id, Closure $factory): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = [
            'factory' => $factory,
            'shared'  => false,
        ];
    }

    public function singleton(string $id, Closure $factory): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = [
            'factory' => $factory,
            'shared'  => true,
        ];
    }

    public function instance(string $id, mixed $value): void
    {
        unset($this->bindings[$id]);
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!array_key_exists($id, $this->bindings)) {
            throw ContainerException::notFound($id);
        }

        if (isset($this->resolving[$id])) {
            throw ContainerException::circularDependency($id, array_keys($this->resolving));
        }

        $this->resolving[$id] = true;

        try {
            $binding = $this->bindings[$id];
            $resolved = ($binding['factory'])($this);

            if ($binding['shared']) {
                $this->instances[$id] = $resolved;
            }

            return $resolved;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function keys(): array
    {
        return array_values(array_unique([
            ...array_keys($this->bindings),
            ...array_keys($this->instances),
        ]));
    }
}
