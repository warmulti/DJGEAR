<?php

declare(strict_types=1);

namespace DJGear\Core\Logging;

/**
 * Immutable domain event recorded in the DJGear audit log table.
 */
final readonly class AuditEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $action,
        public string $resourceType,
        public string $resourceId = '',
        public ?int $actorId = null,
        public array $context = [],
        public string $ipAddress = '',
        public ?string $createdAt = null,
        public ?int $id = null,
    ) {
    }

    /**
     * @return array{id: ?int, action: string, resource_type: string, resource_id: string, actor_id: ?int, context: array<string, mixed>, ip_address: string, created_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'action'        => $this->action,
            'resource_type' => $this->resourceType,
            'resource_id'   => $this->resourceId,
            'actor_id'      => $this->actorId,
            'context'       => $this->context,
            'ip_address'    => $this->ipAddress,
            'created_at'    => $this->createdAt,
        ];
    }
}
