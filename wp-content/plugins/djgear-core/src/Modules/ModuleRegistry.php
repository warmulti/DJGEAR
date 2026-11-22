<?php

declare(strict_types=1);

namespace DJGear\Core\Modules;

use DJGear\Core\Container\ContainerInterface;

/**
 * Discovers, validates dependencies, topologically orders, and boots DJGear modules.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /** @var array<string, bool> */
    private array $booted = [];

    private bool $hasBooted = false;

    public function register(ModuleInterface $module): void
    {
        $id = $module->id();

        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $id)) {
            throw ModuleException::invalidId($id);
        }

        if (isset($this->modules[$id])) {
            throw ModuleException::duplicate($id);
        }

        $this->modules[$id] = $module;
        $this->booted[$id] = false;
    }

    public function has(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    public function get(string $id): ?ModuleInterface
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * Resolve enabled modules in valid dependency-first topological order.
     *
     * @return list<ModuleInterface>
     *
     * @throws ModuleException When a dependency is missing/disabled or cyclic.
     */
    public function resolveBootOrder(): array
    {
        $ordered = [];
        /** @var array<string, true> $visited */
        $visited = [];
        /** @var array<string, true> $visiting */
        $visiting = [];

        $visit = function (string $id) use (&$visit, &$ordered, &$visited, &$visiting): void {
            if (isset($visited[$id])) {
                return;
            }

            if (isset($visiting[$id])) {
                throw ModuleException::circularDependency($id, array_keys($visiting));
            }

            $module = $this->modules[$id];
            if (!$module->isEnabled()) {
                return;
            }

            $visiting[$id] = true;

            foreach ($module->dependencies() as $depId) {
                if (!isset($this->modules[$depId]) || !$this->modules[$depId]->isEnabled()) {
                    throw ModuleException::missingDependency($id, $depId);
                }
                $visit($depId);
            }

            unset($visiting[$id]);
            $visited[$id] = true;
            $ordered[] = $module;
        };

        foreach (array_keys($this->modules) as $id) {
            $visit($id);
        }

        return $ordered;
    }

    /**
     * Execute two-phase lifecycle (register all, then boot all in topological order).
     */
    public function bootAll(ContainerInterface $container): void
    {
        if ($this->hasBooted) {
            return;
        }

        $ordered = $this->resolveBootOrder();

        foreach ($ordered as $module) {
            $module->register($container);
        }

        foreach ($ordered as $module) {
            $module->boot($container);
            $this->booted[$module->id()] = true;
        }

        $this->hasBooted = true;
    }

    /**
     * Trigger WordPress discovery hook so external independent DJGear plugins can self-register.
     */
    public function discoverViaWordPress(): void
    {
        if (function_exists('do_action')) {
            do_action('djgear_core_register_modules', $this);
        }
    }

    /**
     * @return list<ModuleDescriptor>
     */
    public function descriptors(): array
    {
        $list = [];

        foreach ($this->modules as $id => $module) {
            $list[] = new ModuleDescriptor(
                id: $module->id(),
                name: $module->name(),
                version: $module->version(),
                dependencies: $module->dependencies(),
                enabled: $module->isEnabled(),
                booted: $this->booted[$id] ?? false,
            );
        }

        return $list;
    }
}
