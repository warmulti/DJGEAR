<?php

declare(strict_types=1);

namespace DJGear\Core\Modules;

use RuntimeException;

/**
 * Exception raised for invalid module registration or dependency resolution.
 */
class ModuleException extends RuntimeException
{
    public static function duplicate(string $id): self
    {
        return new self(sprintf('DJGear module "%s" is already registered.', $id));
    }

    public static function invalidId(string $id): self
    {
        return new self(sprintf('Invalid DJGear module ID "%s". Expected lowercase slug format.', $id));
    }

    public static function missingDependency(string $moduleId, string $dependencyId): self
    {
        return new self(sprintf('DJGear module "%s" requires missing or disabled module "%s".', $moduleId, $dependencyId));
    }

    /**
     * @param list<string> $chain
     */
    public static function circularDependency(string $moduleId, array $chain): self
    {
        $path = implode(' -> ', [...$chain, $moduleId]);

        return new self(sprintf('Circular module dependency detected: %s', $path));
    }
}
