<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\Container\Container;
use DJGear\Core\Container\ContainerException;
use DJGear\Core\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testBindReturnsFreshInstanceEachCall(): void
    {
        $container = new Container();
        $container->bind('transient', static fn (): stdClass => new stdClass());

        $first = $container->get('transient');
        $second = $container->get('transient');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertNotSame($first, $second);
    }

    public function testSingletonResolvesLazilyAndCachesSameInstance(): void
    {
        $container = new Container();
        $invocations = 0;

        $container->singleton('shared', static function () use (&$invocations): stdClass {
            $invocations++;
            return new stdClass();
        });

        self::assertSame(0, $invocations);
        $first = $container->get('shared');
        $second = $container->get('shared');

        self::assertSame(1, $invocations);
        self::assertSame($first, $second);
    }

    public function testInstanceAndKeysAndRebinding(): void
    {
        $container = new Container();
        $obj = new stdClass();
        $container->instance('cfg', $obj);

        self::assertTrue($container->has('cfg'));
        self::assertSame($obj, $container->get('cfg'));
        self::assertSame(['cfg'], $container->keys());

        $container->bind('cfg', static fn (): string => 'replaced');
        self::assertSame('replaced', $container->get('cfg'));
    }

    public function testMissingServiceThrowsContainerException(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('missing.service');
        $container->get('missing.service');
    }

    public function testCircularDependencyThrowsContainerException(): void
    {
        $container = new Container();
        $container->singleton('a', static fn (ContainerInterface $c): mixed => $c->get('b'));
        $container->singleton('b', static fn (ContainerInterface $c): mixed => $c->get('a'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');
        $container->get('a');
    }
}
