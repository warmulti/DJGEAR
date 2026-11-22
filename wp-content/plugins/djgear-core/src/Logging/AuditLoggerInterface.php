<?php

declare(strict_types=1);

namespace DJGear\Core\Logging;

/**
 * Persistent audit logging contract for security and operational traceability.
 */
interface AuditLoggerInterface
{
    /**
     * Persist an audit event and return its inserted record ID (or 0 on failure).
     */
    public function record(AuditEvent $event): int;

    /**
     * Fetch recent audit events with pagination and optional action filter.
     *
     * @return array{items: list<AuditEvent>, total: int, page: int, per_page: int}
     */
    public function query(int $page = 1, int $perPage = 20, ?string $action = null): array;
}
