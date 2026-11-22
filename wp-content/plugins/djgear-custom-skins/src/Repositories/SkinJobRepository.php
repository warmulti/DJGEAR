<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Repositories;

use DJGear\CustomSkins\Domain\SkinJobStateMachine;
use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Lifecycle\Migrations\CreateCustomSkinTables;
use wpdb;

/**
 * Repository for Custom Skin jobs (`djgear_skin_jobs`), immutable artwork/proof revisions
 * (`djgear_skin_artworks`), and immutable status history (`djgear_skin_status_history`).
 */
final class SkinJobRepository
{
    public const REVISION_CUSTOMER_ARTWORK = 'customer_artwork';
    public const REVISION_DESIGNER_PROOF   = 'designer_proof';

    public function __construct(
        private readonly wpdb $wpdb,
    ) {
    }

    public function jobsTable(): string
    {
        return $this->wpdb->prefix . CreateCustomSkinTables::TABLE_JOBS;
    }

    public function artworksTable(): string
    {
        return $this->wpdb->prefix . CreateCustomSkinTables::TABLE_ARTWORKS;
    }

    public function historyTable(): string
    {
        return $this->wpdb->prefix . CreateCustomSkinTables::TABLE_STATUS_HISTORY;
    }

    /**
     * Create a draft skin job with frozen commercial snapshot.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function createDraft(
        string $jobUuid,
        int $customerUserId,
        int $deviceId,
        int $skinVariantId,
        int $planId,
        array $snapshot,
        string $customerNotes = '',
    ): array {
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->wpdb->insert($this->jobsTable(), [
            'job_uuid'         => $jobUuid,
            'customer_user_id' => $customerUserId,
            'device_id'        => $deviceId,
            'skin_variant_id'  => $skinVariantId,
            'plan_id'          => $planId,
            'snapshot_json'    => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: '{}',
            'status'           => SkinJobStateMachine::STATUS_DRAFT,
            'customer_notes'   => $customerNotes !== '' ? $customerNotes : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        if ($inserted === false) {
            throw new SkinValidationException('Failed to create custom skin job: ' . $this->wpdb->last_error, 500);
        }

        $jobId = (int) $this->wpdb->insert_id;
        $this->appendHistory(
            $jobId,
            null,
            SkinJobStateMachine::STATUS_DRAFT,
            $customerUserId,
            'customer',
            true,
            'Skin configuration draft created.'
        );

        $job = $this->findById($jobId, true);
        if ($job === null) {
            throw new SkinValidationException('Created skin job could not be reloaded.', 500);
        }

        return $job;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id, bool $withDetails = false): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $table = $this->jobsTable();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id),
            'ARRAY_A'
        );
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateJob($row, $withDetails);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid, bool $withDetails = false): ?array
    {
        $table = $this->jobsTable();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE job_uuid = %s", $uuid),
            'ARRAY_A'
        );
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateJob($row, $withDetails);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByWcOrderId(int $wcOrderId): array
    {
        $table = $this->jobsTable();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE wc_order_id = %d ORDER BY id ASC", $wcOrderId),
            'ARRAY_A'
        );

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->hydrateJob($row, false);
            }
        }

        return $items;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listForCustomer(int $customerUserId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;
        $table = $this->jobsTable();

        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE customer_user_id = %d", $customerUserId)
        );
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE customer_user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                $customerUserId,
                $perPage,
                $offset
            ),
            'ARRAY_A'
        );

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->hydrateJob($row, true);
            }
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, counts: array<string, int>}
     */
    public function listAdminQueue(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $table = $this->jobsTable();

        $where = ['1=1'];
        $params = [];

        if (isset($filters['status']) && is_string($filters['status']) && in_array($filters['status'], SkinJobStateMachine::allStatuses(), true)) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (isset($filters['wc_order_id']) && is_numeric($filters['wc_order_id']) && (int) $filters['wc_order_id'] > 0) {
            $where[] = 'wc_order_id = %d';
            $params[] = (int) $filters['wc_order_id'];
        }
        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $like = '%' . $this->wpdb->esc_like(trim($filters['q'])) . '%';
            $where[] = '(job_uuid LIKE %s OR snapshot_json LIKE %s OR tracking_code LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}";
        $total = $params !== []
            ? (int) $this->wpdb->get_var($this->wpdb->prepare($countSql, ...$params))
            : (int) $this->wpdb->get_var($countSql);

        $rowsSql = "SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
        $queryParams = [...$params, $perPage, $offset];
        $rows = $this->wpdb->get_results($this->wpdb->prepare($rowsSql, ...$queryParams), 'ARRAY_A');

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->hydrateJob($row, true);
            }
        }

        $counts = [];
        $countRows = $this->wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM `{$table}` GROUP BY status", 'ARRAY_A');
        if (is_array($countRows)) {
            foreach ($countRows as $cRow) {
                if (isset($cRow['status'])) {
                    $counts[(string) $cRow['status']] = (int) ($cRow['cnt'] ?? 0);
                }
            }
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'counts'   => $counts,
        ];
    }

    /**
     * Add an immutable customer artwork or designer proof revision to a job.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addArtworkRevision(int $jobId, string $revisionType, array $data): array
    {
        $table = $this->artworksTable();
        $maxVer = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(MAX(version_number), 0) FROM `{$table}` WHERE job_id = %d AND revision_type = %s",
                $jobId,
                $revisionType
            )
        );
        $nextVersion = $maxVer + 1;
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $this->wpdb->insert($table, [
            'job_id'                => $jobId,
            'revision_type'         => $revisionType,
            'version_number'        => $nextVersion,
            'uploaded_by_user_id'   => (int) ($data['uploaded_by_user_id'] ?? 0),
            'storage_rel_path'      => (string) ($data['storage_rel_path'] ?? ''),
            'original_filename'     => (string) ($data['original_filename'] ?? 'artwork'),
            'mime_type'             => (string) ($data['mime_type'] ?? 'application/octet-stream'),
            'file_size_bytes'       => (int) ($data['file_size_bytes'] ?? 0),
            'sha256_hash'           => (string) ($data['sha256_hash'] ?? ''),
            'pixel_width'           => $data['pixel_width'] ?? null,
            'pixel_height'          => $data['pixel_height'] ?? null,
            'computed_dpi'          => $data['computed_dpi'] ?? null,
            'dpi_status'            => (string) ($data['dpi_status'] ?? 'not_applicable'),
            'preflight_report_json' => isset($data['preflight_report'])
                ? (wp_json_encode($data['preflight_report'], JSON_UNESCAPED_UNICODE) ?: null)
                : null,
            'low_dpi_acknowledged'  => !empty($data['low_dpi_acknowledged']) ? 1 : 0,
            'customer_decision'     => (string) ($data['customer_decision'] ?? ($revisionType === self::REVISION_DESIGNER_PROOF ? 'pending' : 'not_applicable')),
            'note_fa'               => isset($data['note_fa']) && is_string($data['note_fa']) ? $data['note_fa'] : null,
            'note_en'               => isset($data['note_en']) && is_string($data['note_en']) ? $data['note_en'] : null,
            'created_at'            => $now,
        ]);

        if ($inserted === false) {
            throw new SkinValidationException('Failed to record artwork revision: ' . $this->wpdb->last_error, 500);
        }

        $revId = (int) $this->wpdb->insert_id;

        if ($revisionType === self::REVISION_CUSTOMER_ARTWORK) {
            $this->wpdb->update(
                $this->jobsTable(),
                ['current_artwork_id' => $revId, 'updated_at' => $now],
                ['id' => $jobId]
            );
        } elseif ($revisionType === self::REVISION_DESIGNER_PROOF) {
            $this->wpdb->update(
                $this->jobsTable(),
                ['current_proof_id' => $revId, 'updated_at' => $now],
                ['id' => $jobId]
            );
        }

        $rev = $this->findArtworkById($revId);
        if ($rev === null) {
            throw new SkinValidationException('Created artwork revision could not be reloaded.', 500);
        }

        return $rev;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findArtworkById(int $revId): ?array
    {
        if ($revId <= 0) {
            return null;
        }
        $table = $this->artworksTable();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $revId),
            'ARRAY_A'
        );

        return is_array($row) ? $this->hydrateArtwork($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listArtworksForJob(int $jobId): array
    {
        $table = $this->artworksTable();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE job_id = %d ORDER BY created_at ASC, id ASC", $jobId),
            'ARRAY_A'
        );

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->hydrateArtwork($row);
            }
        }

        return $items;
    }

    public function countProofRevisionRequests(int $jobId): int
    {
        $table = $this->artworksTable();
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE job_id = %d AND revision_type = %s AND customer_decision = 'changes_requested'",
                $jobId,
                self::REVISION_DESIGNER_PROOF
            )
        );
    }

    public function acknowledgeLowDpi(int $artworkId): void
    {
        $this->wpdb->update(
            $this->artworksTable(),
            ['low_dpi_acknowledged' => 1],
            ['id' => $artworkId],
            ['%d'],
            ['%d']
        );
    }

    public function recordProofDecision(int $jobId, int $proofId, string $decision): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->wpdb->update(
            $this->artworksTable(),
            [
                'customer_decision' => $decision,
                'decision_at'       => $now,
            ],
            ['id' => $proofId],
            ['%s', '%s'],
            ['%d']
        );

        if ($decision === 'approved') {
            $this->wpdb->update(
                $this->jobsTable(),
                [
                    'approved_proof_id' => $proofId,
                    'approved_at'       => $now,
                    'updated_at'        => $now,
                ],
                ['id' => $jobId]
            );
        }

        return $now;
    }

    public function bindWooCommerceOrderItem(int $jobId, int $wcOrderId, int $wcOrderItemId): void
    {
        $this->wpdb->update(
            $this->jobsTable(),
            [
                'wc_order_id'      => $wcOrderId,
                'wc_order_item_id' => $wcOrderItemId,
                'updated_at'       => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $jobId]
        );
    }

    /**
     * Transition a job's status with server-side state-machine validation and immutable history recording.
     *
     * @param array<string, mixed> $extraFields Optional fields (`tracking_code`, `internal_notes`, `assigned_staff_id`)
     * @return array<string, mixed>
     */
    public function transitionStatus(
        int $jobId,
        string $toStatus,
        ?int $actorUserId,
        string $actorRole,
        bool $isCustomerVisible = true,
        ?string $comment = null,
        array $extraFields = [],
    ): array {
        $job = $this->findById($jobId, false);
        if ($job === null) {
            throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
        }

        $fromStatus = (string) $job['status'];
        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnapshot = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $requiresProofApproval = (bool) ($planSnapshot['requires_proof_approval'] ?? false);

        SkinJobStateMachine::assertTransition($fromStatus, $toStatus, $requiresProofApproval);

        $update = [
            'status'     => $toStatus,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if (array_key_exists('tracking_code', $extraFields)) {
            $update['tracking_code'] = $extraFields['tracking_code'];
        }
        if (array_key_exists('internal_notes', $extraFields)) {
            $update['internal_notes'] = $extraFields['internal_notes'];
        }
        if (array_key_exists('assigned_staff_id', $extraFields)) {
            $update['assigned_staff_id'] = $extraFields['assigned_staff_id'];
        }

        $this->wpdb->update($this->jobsTable(), $update, ['id' => $jobId]);
        $this->appendHistory($jobId, $fromStatus, $toStatus, $actorUserId, $actorRole, $isCustomerVisible, $comment);

        $reloaded = $this->findById($jobId, true);
        if ($reloaded === null) {
            throw SkinValidationException::notFound('skin_job');
        }

        return $reloaded;
    }

    public function appendHistory(
        int $jobId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        string $actorRole,
        bool $isCustomerVisible,
        ?string $comment = null,
    ): void {
        $this->wpdb->insert($this->historyTable(), [
            'job_id'              => $jobId,
            'from_status'         => $fromStatus,
            'to_status'           => $toStatus,
            'actor_user_id'       => $actorUserId,
            'actor_role'          => $actorRole,
            'is_customer_visible' => $isCustomerVisible ? 1 : 0,
            'comment'             => $comment,
            'created_at'          => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHistoryForJob(int $jobId): array
    {
        $table = $this->historyTable();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE job_id = %d ORDER BY created_at ASC, id ASC", $jobId),
            'ARRAY_A'
        );

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = [
                    'id'                  => (int) ($row['id'] ?? 0),
                    'job_id'              => (int) ($row['job_id'] ?? 0),
                    'from_status'         => $row['from_status'] ?? null,
                    'to_status'           => (string) ($row['to_status'] ?? ''),
                    'actor_user_id'       => isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
                    'actor_role'          => (string) ($row['actor_role'] ?? 'system'),
                    'is_customer_visible' => ((int) ($row['is_customer_visible'] ?? 1)) === 1,
                    'comment'             => $row['comment'] ?? null,
                    'created_at'          => (string) ($row['created_at'] ?? ''),
                ];
            }
        }

        return $items;
    }

    /**
     * Hard delete a job and its child records (used strictly in automated test teardown).
     */
    public function purgeJob(int $jobId): void
    {
        $this->wpdb->delete($this->historyTable(), ['job_id' => $jobId], ['%d']);
        $this->wpdb->delete($this->artworksTable(), ['job_id' => $jobId], ['%d']);
        $this->wpdb->delete($this->jobsTable(), ['id' => $jobId], ['%d']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateJob(array $row, bool $withDetails): array
    {
        $id = (int) ($row['id'] ?? 0);
        $snapshot = json_decode((string) ($row['snapshot_json'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        $wcOrderId = isset($row['wc_order_id']) ? (int) $row['wc_order_id'] : null;
        $wcOrderStatus = null;
        $wcOrderUrl = null;
        $wcOrderAdminUrl = null;
        $customerDisplay = null;

        if ($wcOrderId !== null && $wcOrderId > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($wcOrderId);
            if ($order instanceof \WC_Order) {
                $wcOrderStatus = $order->get_status();
                $wcOrderUrl = $order->get_view_order_url();
                $wcOrderAdminUrl = $order->get_edit_order_url();
            }
        }

        $customerId = (int) ($row['customer_user_id'] ?? 0);
        if ($customerId > 0 && function_exists('get_userdata')) {
            $user = get_userdata($customerId);
            if ($user instanceof \WP_User) {
                $customerDisplay = $user->display_name !== '' ? $user->display_name : $user->user_login;
            }
        }

        $planSnapshot = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $requiresProof = (bool) ($planSnapshot['requires_proof_approval'] ?? false);
        $status = (string) ($row['status'] ?? SkinJobStateMachine::STATUS_DRAFT);

        $hydrated = [
            'id'                 => $id,
            'job_uuid'           => (string) ($row['job_uuid'] ?? ''),
            'customer_user_id'   => $customerId,
            'customer_name'      => $customerDisplay,
            'wc_order_id'        => $wcOrderId,
            'wc_order_item_id'   => isset($row['wc_order_item_id']) ? (int) $row['wc_order_item_id'] : null,
            'wc_order_status'    => $wcOrderStatus,
            'wc_order_url'       => $wcOrderUrl,
            'wc_order_admin_url' => $wcOrderAdminUrl,
            'device_id'          => (int) ($row['device_id'] ?? 0),
            'skin_variant_id'    => (int) ($row['skin_variant_id'] ?? 0),
            'plan_id'            => (int) ($row['plan_id'] ?? 0),
            'snapshot'           => $snapshot,
            'status'             => $status,
            'allowed_next'       => SkinJobStateMachine::allowedNextStatuses($status, $requiresProof),
            'current_artwork_id' => isset($row['current_artwork_id']) ? (int) $row['current_artwork_id'] : null,
            'current_proof_id'   => isset($row['current_proof_id']) ? (int) $row['current_proof_id'] : null,
            'approved_proof_id'  => isset($row['approved_proof_id']) ? (int) $row['approved_proof_id'] : null,
            'approved_at'        => $row['approved_at'] ?? null,
            'assigned_staff_id'  => isset($row['assigned_staff_id']) ? (int) $row['assigned_staff_id'] : null,
            'customer_notes'     => $row['customer_notes'] ?? null,
            'internal_notes'     => $row['internal_notes'] ?? null,
            'tracking_code'      => $row['tracking_code'] ?? null,
            'created_at'         => (string) ($row['created_at'] ?? ''),
            'updated_at'         => (string) ($row['updated_at'] ?? ''),
        ];

        if ($withDetails) {
            $hydrated['artworks'] = $this->listArtworksForJob($id);
            $hydrated['status_history'] = $this->listHistoryForJob($id);
        }

        return $hydrated;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateArtwork(array $row): array
    {
        $preflight = null;
        if (isset($row['preflight_report_json']) && is_string($row['preflight_report_json']) && $row['preflight_report_json'] !== '') {
            $decoded = json_decode($row['preflight_report_json'], true);
            if (is_array($decoded)) {
                $preflight = $decoded;
            }
        }

        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'job_id'               => (int) ($row['job_id'] ?? 0),
            'revision_type'        => (string) ($row['revision_type'] ?? ''),
            'version_number'       => (int) ($row['version_number'] ?? 1),
            'uploaded_by_user_id'  => (int) ($row['uploaded_by_user_id'] ?? 0),
            'storage_rel_path'     => (string) ($row['storage_rel_path'] ?? ''),
            'original_filename'    => (string) ($row['original_filename'] ?? ''),
            'mime_type'            => (string) ($row['mime_type'] ?? ''),
            'file_size_bytes'      => (int) ($row['file_size_bytes'] ?? 0),
            'sha256_hash'          => (string) ($row['sha256_hash'] ?? ''),
            'pixel_width'          => isset($row['pixel_width']) ? (int) $row['pixel_width'] : null,
            'pixel_height'         => isset($row['pixel_height']) ? (int) $row['pixel_height'] : null,
            'computed_dpi'         => isset($row['computed_dpi']) ? (float) $row['computed_dpi'] : null,
            'dpi_status'           => (string) ($row['dpi_status'] ?? 'not_applicable'),
            'preflight_report'     => $preflight,
            'low_dpi_acknowledged' => ((int) ($row['low_dpi_acknowledged'] ?? 0)) === 1,
            'customer_decision'    => (string) ($row['customer_decision'] ?? 'pending'),
            'decision_at'          => $row['decision_at'] ?? null,
            'note_fa'              => $row['note_fa'] ?? null,
            'note_en'              => $row['note_en'] ?? null,
            'created_at'           => (string) ($row['created_at'] ?? ''),
        ];
    }
}
