<?php

declare(strict_types=1);

namespace DJGear\Core\Container;

use RuntimeException;

/**
 * Exception raised for container lookup or resolution failures.
 */
class ContainerException extends RuntimeException
{
    public static function notFound(string $id): self
    {
        return new self(sprintf('Service "%s" is not registered in the DJGear container.', $id));
    }

    /**
     * @param list<string> $chain
     */
    public static function circularDependency(string $id, array $chain): self
    {
        $path = implode(' -> ', [...$chain, $id]);

        return new self(sprintf('Circular dependency detected while resolving "%s": %s', $id, $path));
    }
}
