<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\Container\Container;
use DJGear\Core\Container\ContainerInterface;
use DJGear\Core\Modules\AbstractModule;
use DJGear\Core\Modules\ModuleException;
use DJGear\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testRegistersAndBootsModulesInDependencyOrder(): void
    {
        $events = [];
        $registry = new ModuleRegistry();
        $container = new Container();

        $dependent = $this->makeModule('djgear-dashboard', 'Dashboard', ['djgear-core-base'], true, $events);
        $base = $this->makeModule('djgear-core-base', 'Base', [], true, $events);
        $disabled = $this->makeModule('djgear-disabled', 'Disabled', [], false, $events);

        // Register out of order to verify topological sorting
        $registry->register($dependent);
        $registry->register($base);
        $registry->register($disabled);

        $registry->bootAll($container);

        self::assertSame([
            'register:djgear-core-base',
            'register:djgear-dashboard',
            'boot:djgear-core-base',
            'boot:djgear-dashboard',
        ], $events);

        $descriptors = [];
        foreach ($registry->descriptors() as $d) {
            $descriptors[$d->id] = $d;
        }

        self::assertTrue($descriptors['djgear-core-base']->booted);
        self::assertTrue($descriptors['djgear-dashboard']->booted);
        self::assertFalse($descriptors['djgear-disabled']->booted);
    }

    public function testDuplicateModuleRegistrationThrows(): void
    {
        $events = [];
        $registry = new ModuleRegistry();
        $registry->register($this->makeModule('djgear-crm', 'CRM', [], true, $events));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('already registered');
        $registry->register($this->makeModule('djgear-crm', 'CRM Duplicate', [], true, $events));
    }

    public function testInvalidModuleIdThrows(): void
    {
        $events = [];
        $registry = new ModuleRegistry();

        $this->expectException(ModuleException::class);
        $registry->register($this->makeModule('Invalid_Module ID!', 'Bad', [], true, $events));
    }

    public function testMissingDependencyThrows(): void
    {
        $events = [];
        $registry = new ModuleRegistry();
        $registry->register($this->makeModule('djgear-analytics', 'Analytics', ['djgear-crm'], true, $events));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('missing or disabled module "djgear-crm"');
        $registry->resolveBootOrder();
    }

    public function testCircularDependencyThrows(): void
    {
        $events = [];
        $registry = new ModuleRegistry();
        $registry->register($this->makeModule('mod-a', 'A', ['mod-b'], true, $events));
        $registry->register($this->makeModule('mod-b', 'B', ['mod-a'], true, $events));

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Circular module dependency');
        $registry->resolveBootOrder();
    }

    /**
     * @param list<string> $deps
     * @param list<string> $events
     */
    private function makeModule(
        string $id,
        string $name,
        array $deps,
        bool $enabled,
        array &$events,
    ): AbstractModule {
        return new class ($id, $name, $deps, $enabled, $events) extends AbstractModule {
            /**
             * @param list<string> $deps
             * @param list<string> $events
             */
            public function __construct(
                private readonly string $modId,
                private readonly string $modName,
                private readonly array $deps,
                private readonly bool $modEnabled,
                private array &$events,
            ) {
            }

            public function id(): string
            {
                return $this->modId;
            }

            public function name(): string
            {
                return $this->modName;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function dependencies(): array
            {
                return $this->deps;
            }

            public function isEnabled(): bool
            {
                return $this->modEnabled;
            }

            public function register(ContainerInterface $container): void
            {
                $this->events[] = 'register:' . $this->modId;
            }

            public function boot(ContainerInterface $container): void
            {
                $this->events[] = 'boot:' . $this->modId;
            }
        };
    }
}
