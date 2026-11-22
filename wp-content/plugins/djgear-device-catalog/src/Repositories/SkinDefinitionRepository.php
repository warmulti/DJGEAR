<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Repositories;

use DJGear\DeviceCatalog\Domain\CatalogValidator;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Lifecycle\Migrations\CreateCatalogTables;
use wpdb;

/**
 * Manages versioned Compatible Skin Definitions (`{prefix}djgear_skin_definitions`)
 * and multi-device compatibility links (`target_type = 'skin_definition'`).
 *
 * Security Invariant:
 * - Engineering dieline attachments (`dieline_attachment_id`) and `internal_notes` are stored here
 *   separately from public media (`djgear_device_media`) and are stripped by `PublicCatalogSerializer`.
 */
final class SkinDefinitionRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly DeviceRepository $devices,
    ) {
    }

    public function skinsTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_SKINS;
    }

    public function compatibilityTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_COMPATIBILITY;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(?string $status = null): array
    {
        $table = $this->skinsTable();
        if ($status !== null && in_array($status, CatalogValidator::SKIN_STATUSES, true)) {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC, id DESC", $status),
                'ARRAY_A'
            );
        } else {
            $rows = $this->wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC", 'ARRAY_A');
        }

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Fetch all skin definitions compatible with a given device (primary OR secondary compatible device).
     *
     * @return list<array<string, mixed>>
     */
    public function listForDevice(int $deviceId, bool $activeOnly = false): array
    {
        $skins = $this->skinsTable();
        $compat = $this->compatibilityTable();
        $statusClause = $activeOnly ? "AND s.status = 'active'" : '';

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT s.* FROM {$skins} s
                 LEFT JOIN {$compat} c ON c.target_type = 'skin_definition' AND c.target_id = s.id AND c.relation_type = 'compatible_skin'
                 WHERE (s.primary_device_id = %d OR c.source_device_id = %d) {$statusClause}
                 ORDER BY s.updated_at DESC, s.id DESC",
                $deviceId,
                $deviceId
            ),
            'ARRAY_A'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $skinId): ?array
    {
        $table = $this->skinsTable();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $skinId),
            'ARRAY_A'
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = CatalogValidator::validateSkinDefinition($input);

        foreach ($clean['compatible_device_ids'] as $cid) {
            if ($this->devices->findById($cid) === null) {
                throw ValidationException::notFound('Device', $cid);
            }
        }

        $this->assertUniqueTemplateCode($clean['template_code'], null);

        $zonesJson = json_encode($clean['zones'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $finishesJson = json_encode($clean['supported_finishes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["matte"]';

        $inserted = $this->wpdb->insert($this->skinsTable(), [
            'primary_device_id'       => $clean['primary_device_id'],
            'template_code'           => $clean['template_code'],
            'template_version'        => $clean['template_version'],
            'status'                  => $clean['status'],
            'width_mm'                => $clean['width_mm'],
            'height_mm'               => $clean['height_mm'],
            'bleed_mm'                => $clean['bleed_mm'],
            'min_dpi'                 => $clean['min_dpi'],
            'zones_json'              => $zonesJson,
            'supported_finishes_json' => $finishesJson,
            'preview_attachment_id'   => $clean['preview_attachment_id'],
            'dieline_attachment_id'   => $clean['dieline_attachment_id'],
            'base_wc_product_id'      => $clean['base_wc_product_id'],
            'internal_notes'          => $clean['internal_notes'],
            'notes_fa'                => $clean['notes_fa'],
            'notes_en'                => $clean['notes_en'],
            'updated_at'              => gmdate('Y-m-d H:i:s'),
        ]);

        if ($inserted === false) {
            throw ValidationException::conflict('template_code', 'Could not create skin definition: ' . $this->wpdb->last_error);
        }

        $skinId = (int) $this->wpdb->insert_id;
        $this->syncCompatibleDevices($skinId, $clean['compatible_device_ids']);
        $this->refreshSkinReadyFlags($clean['compatible_device_ids']);

        $created = $this->findById($skinId);
        if ($created === null) {
            throw ValidationException::notFound('SkinDefinition', $skinId);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $skinId, array $input): array
    {
        $existing = $this->findById($skinId);
        if ($existing === null) {
            throw ValidationException::notFound('SkinDefinition', $skinId);
        }

        $previousDevices = is_array($existing['compatible_device_ids']) ? $existing['compatible_device_ids'] : [];
        $merged = array_merge($existing, $input);
        $clean = CatalogValidator::validateSkinDefinition($merged);

        foreach ($clean['compatible_device_ids'] as $cid) {
            if ($this->devices->findById($cid) === null) {
                throw ValidationException::notFound('Device', $cid);
            }
        }

        $this->assertUniqueTemplateCode($clean['template_code'], $skinId);

        $zonesJson = json_encode($clean['zones'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $finishesJson = json_encode($clean['supported_finishes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '["matte"]';

        $this->wpdb->update(
            $this->skinsTable(),
            [
                'primary_device_id'       => $clean['primary_device_id'],
                'template_code'           => $clean['template_code'],
                'template_version'        => $clean['template_version'],
                'status'                  => $clean['status'],
                'width_mm'                => $clean['width_mm'],
                'height_mm'               => $clean['height_mm'],
                'bleed_mm'                => $clean['bleed_mm'],
                'min_dpi'                 => $clean['min_dpi'],
                'zones_json'              => $zonesJson,
                'supported_finishes_json' => $finishesJson,
                'preview_attachment_id'   => $clean['preview_attachment_id'],
                'dieline_attachment_id'   => $clean['dieline_attachment_id'],
                'base_wc_product_id'      => $clean['base_wc_product_id'],
                'internal_notes'          => $clean['internal_notes'],
                'notes_fa'                => $clean['notes_fa'],
                'notes_en'                => $clean['notes_en'],
                'updated_at'              => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $skinId]
        );

        $this->syncCompatibleDevices($skinId, $clean['compatible_device_ids']);
        $affectedDevices = array_values(array_unique(array_merge($previousDevices, $clean['compatible_device_ids'])));
        $this->refreshSkinReadyFlags($affectedDevices);

        $updated = $this->findById($skinId);
        if ($updated === null) {
            throw ValidationException::notFound('SkinDefinition', $skinId);
        }

        return $updated;
    }

    public function delete(int $skinId): void
    {
        $existing = $this->findById($skinId);
        if ($existing === null) {
            throw ValidationException::notFound('SkinDefinition', $skinId);
        }

        $affected = is_array($existing['compatible_device_ids']) ? $existing['compatible_device_ids'] : [];
        $this->wpdb->delete(
            $this->compatibilityTable(),
            ['target_type' => 'skin_definition', 'target_id' => $skinId],
            ['%s', '%d']
        );
        $this->wpdb->delete($this->skinsTable(), ['id' => $skinId], ['%d']);
        $this->refreshSkinReadyFlags($affected);
    }

    private function assertUniqueTemplateCode(string $templateCode, ?int $ignoreId): void
    {
        $table = $this->skinsTable();
        $owner = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT id FROM {$table} WHERE template_code = %s LIMIT 1", $templateCode)
        );
        if ($owner !== null && (int) $owner !== $ignoreId) {
            throw ValidationException::conflict('template_code', "Skin template code '{$templateCode}' already exists.");
        }
    }

    /**
     * @param list<int> $deviceIds
     */
    private function syncCompatibleDevices(int $skinId, array $deviceIds): void
    {
        $compat = $this->compatibilityTable();
        $this->wpdb->delete(
            $compat,
            ['target_type' => 'skin_definition', 'target_id' => $skinId, 'relation_type' => 'compatible_skin'],
            ['%s', '%d', '%s']
        );

        $now = gmdate('Y-m-d H:i:s');
        foreach ($deviceIds as $deviceId) {
            $this->wpdb->insert($compat, [
                'source_device_id' => $deviceId,
                'target_type'      => 'skin_definition',
                'target_id'        => $skinId,
                'relation_type'    => 'compatible_skin',
                'fit_confidence'   => 'verified',
                'notes_fa'         => '',
                'notes_en'         => '',
                'created_at'       => $now,
            ]);
        }
    }

    /**
     * Recompute canonical `skin_ready` flag on every affected device.
     *
     * @param list<int> $deviceIds
     */
    private function refreshSkinReadyFlags(array $deviceIds): void
    {
        foreach ($deviceIds as $deviceId) {
            if ($deviceId <= 0) {
                continue;
            }
            $activeSkins = $this->listForDevice($deviceId, true);
            $this->devices->setSkinReady($deviceId, count($activeSkins) > 0);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $skinId = (int) $row['id'];
        $primaryDeviceId = (int) $row['primary_device_id'];

        $compat = $this->compatibilityTable();
        $linkedIds = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT source_device_id FROM {$compat} WHERE target_type = 'skin_definition' AND target_id = %d AND relation_type = 'compatible_skin'",
                $skinId
            )
        );
        $compatibleDeviceIds = [$primaryDeviceId];
        foreach ($linkedIds as $lid) {
            $compatibleDeviceIds[] = (int) $lid;
        }
        $compatibleDeviceIds = array_values(array_unique($compatibleDeviceIds));

        $previewId = $row['preview_attachment_id'] !== null ? (int) $row['preview_attachment_id'] : null;
        $dielineId = $row['dieline_attachment_id'] !== null ? (int) $row['dieline_attachment_id'] : null;

        $zones = json_decode((string) $row['zones_json'], true);
        $finishes = json_decode((string) $row['supported_finishes_json'], true);

        return [
            'id'                    => $skinId,
            'primary_device_id'     => $primaryDeviceId,
            'compatible_device_ids' => $compatibleDeviceIds,
            'template_code'         => (string) $row['template_code'],
            'template_version'      => (string) $row['template_version'],
            'status'                => (string) $row['status'],
            'width_mm'              => (float) $row['width_mm'],
            'height_mm'             => (float) $row['height_mm'],
            'bleed_mm'              => (float) $row['bleed_mm'],
            'min_dpi'               => (int) $row['min_dpi'],
            'zones'                 => is_array($zones) ? $zones : [],
            'supported_finishes'    => is_array($finishes) ? $finishes : [],
            'preview_attachment_id' => $previewId,
            'preview_image_url'     => $previewId !== null ? (wp_get_attachment_image_url($previewId, 'large') ?: null) : null,
            'dieline_attachment_id' => $dielineId,
            'dieline_url'           => $dielineId !== null ? (wp_get_attachment_url($dielineId) ?: null) : null,
            'base_wc_product_id'    => $row['base_wc_product_id'] !== null ? (int) $row['base_wc_product_id'] : null,
            'internal_notes'        => (string) ($row['internal_notes'] ?? ''),
            'notes_fa'              => (string) ($row['notes_fa'] ?? ''),
            'notes_en'              => (string) ($row['notes_en'] ?? ''),
            'updated_at'            => (string) $row['updated_at'],
        ];
    }
}
