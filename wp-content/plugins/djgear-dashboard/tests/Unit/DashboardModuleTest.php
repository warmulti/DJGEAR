<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Tests\Unit;

use DJGear\Core\Container\Container;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\Dashboard\DashboardModule;
use DJGear\Dashboard\Services\CommerceSummaryService;
use DJGear\Dashboard\Services\SettingsService;
use PHPUnit\Framework\TestCase;

final class DashboardModuleTest extends TestCase
{
    public function testModuleIntegratesWithCoreRegistryAndRegistersServices(): void
    {
        $registry = new ModuleRegistry();
        $container = new Container();

        $module = new DashboardModule('/tmp/djgear-dashboard.php');
        $registry->register($module);

        self::assertTrue($registry->has('djgear-dashboard'));
        self::assertSame('djgear-dashboard', $module->id());
        self::assertSame('0.1.0', $module->version());

        $ordered = $registry->resolveBootOrder();
        self::assertCount(1, $ordered);
        self::assertSame($module, $ordered[0]);

        $module->register($container);
        self::assertTrue($container->has(CommerceSummaryService::class));
        self::assertTrue($container->has(SettingsService::class));
    }
}
