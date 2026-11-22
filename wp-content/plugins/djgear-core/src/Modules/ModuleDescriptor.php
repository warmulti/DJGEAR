<?php

declare(strict_types=1);

namespace DJGear\Core\Modules;

/**
 * Immutable snapshot describing a registered DJGear module.
 */
final readonly class ModuleDescriptor
{
    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public array $dependencies,
        public bool $enabled,
        public bool $booted,
    ) {
    }

    /**
     * @return array{id: string, name: string, version: string, dependencies: list<string>, enabled: bool, booted: bool}
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'version'      => $this->version,
            'dependencies' => $this->dependencies,
            'enabled'      => $this->enabled,
            'booted'       => $this->booted,
        ];
    }
}
