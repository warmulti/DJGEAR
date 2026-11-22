<?php

declare(strict_types=1);

namespace DJGear\Core\Logging;

use wpdb;

/**
 * WordPress database-backed audit log persistence using prepared statements.
 */
final class AuditLogger implements AuditLoggerInterface
{
    public const TABLE_SUFFIX = 'djgear_audit_logs';

    public function __construct(
        private readonly wpdb $wpdb,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function tableName(): string
    {
        return $this->wpdb->prefix . self::TABLE_SUFFIX;
    }

    public function record(AuditEvent $event): int
    {
        $actorId = $event->actorId;
        if ($actorId === null && function_exists('get_current_user_id')) {
            $currentId = (int) get_current_user_id();
            $actorId = $currentId > 0 ? $currentId : null;
        }

        $ip = $event->ipAddress !== ''
            ? $event->ipAddress
            : $this->resolveClientIp();

        $redacted = StructuredLogger::redactContext($event->context);
        $contextJson = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        $createdAt = $event->createdAt ?? gmdate('Y-m-d H:i:s');

        $inserted = $this->wpdb->insert(
            $this->tableName(),
            [
                'action'        => substr($event->action, 0, 128),
                'resource_type' => substr($event->resourceType, 0, 64),
                'resource_id'   => substr($event->resourceId, 0, 128),
                'actor_id'      => $actorId,
                'context_json'  => $contextJson,
                'ip_address'    => substr($ip, 0, 45),
                'created_at'    => $createdAt,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            $this->logger?->error('Failed to persist DJGear audit event.', [
                'action'        => $event->action,
                'resource_type' => $event->resourceType,
                'db_error'      => $this->wpdb->last_error,
            ]);

            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    public function query(int $page = 1, int $perPage = 20, ?string $action = null): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, min(100, $perPage));
        $offset = ($safePage - 1) * $safePerPage;
        $table = $this->tableName();

        if ($action !== null && $action !== '') {
            $countSql = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE action = %s",
                $action
            );
            $rowsSql = $this->wpdb->prepare(
                "SELECT id, action, resource_type, resource_id, actor_id, context_json, ip_address, created_at
                 FROM {$table}
                 WHERE action = %s
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $action,
                $safePerPage,
                $offset
            );
        } else {
            $countSql = "SELECT COUNT(*) FROM {$table}";
            $rowsSql = $this->wpdb->prepare(
                "SELECT id, action, resource_type, resource_id, actor_id, context_json, ip_address, created_at
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $safePerPage,
                $offset
            );
        }

        $total = (int) $this->wpdb->get_var($countSql);
        $rows = $this->wpdb->get_results($rowsSql, 'ARRAY_A');
        if (!is_array($rows)) {
            $rows = [];
        }

        $items = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['context_json'] ?? '{}'), true);
            $items[] = new AuditEvent(
                action: (string) ($row['action'] ?? ''),
                resourceType: (string) ($row['resource_type'] ?? ''),
                resourceId: (string) ($row['resource_id'] ?? ''),
                actorId: isset($row['actor_id']) ? (int) $row['actor_id'] : null,
                context: is_array($decoded) ? $decoded : [],
                ipAddress: (string) ($row['ip_address'] ?? ''),
                createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
                id: isset($row['id']) ? (int) $row['id'] : null,
            );
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $safePage,
            'per_page' => $safePerPage,
        ];
    }

    private function resolveClientIp(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        $validated = filter_var(trim($raw), FILTER_VALIDATE_IP);

        return $validated !== false ? $validated : '';
    }
}
