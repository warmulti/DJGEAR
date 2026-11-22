<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\Security\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testCapabilitiesAndRoleMappingsAreConsistent(): void
    {
        $all = Capabilities::all();
        $map = Capabilities::defaultRoleMap();

        self::assertCount(5, $all);
        self::assertArrayHasKey('administrator', $map);
        self::assertArrayHasKey('shop_manager', $map);
        self::assertSame($all, $map['administrator']);

        foreach ($map['shop_manager'] as $cap) {
            self::assertContains($cap, $all);
        }
        self::assertNotContains(Capabilities::MANAGE_CORE, $map['shop_manager']);
    }
}
