<?php

declare(strict_types=1);

namespace DJGear\Core\Rest;

/**
 * Contract for versioned REST API route controllers under the djgear/v1 namespace.
 */
interface RouteInterface
{
    /**
     * Register all endpoints owned by this controller under the provided REST namespace.
     */
    public function registerRoutes(string $namespace): void;
}
