<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Repositories;

use DJGear\DeviceCatalog\Domain\CatalogValidator;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Lifecycle\Migrations\CreateCatalogTables;
use DJGear\DeviceCatalog\Services\WooCommerceProductHydrator;
use wpdb;

/**
 * Manages normalized specs (`djgear_device_specs`), public media (`djgear_device_media`),
 * and compatibility relationships (`djgear_device_compatibility`).
 */
final class CatalogDetailsRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly DeviceRepository $devices,
        private readonly WooCommerceProductHydrator $wcHydrator,
    ) {
    }

    public function specsTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_SPECS;
    }

    public function mediaTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_MEDIA;
    }

    public function compatibilityTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_COMPATIBILITY;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSpecs(int $deviceId): array
    {
        $table = $this->specsTable();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE device_id = %d ORDER BY spec_group ASC, sort_order ASC, id ASC",
                $deviceId
            ),
            'ARRAY_A'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'device_id'     => (int) $r['device_id'],
            'spec_group'    => (string) $r['spec_group'],
            'spec_key'      => (string) $r['spec_key'],
            'label_fa'      => (string) $r['label_fa'],
            'label_en'      => (string) $r['label_en'],
            'value_fa'      => (string) $r['value_fa'],
            'value_en'      => (string) $r['value_en'],
            'value_type'    => (string) $r['value_type'],
            'numeric_value' => $r['numeric_value'] !== null ? (float) $r['numeric_value'] : null,
            'filter_slug'   => $r['filter_slug'] !== null ? (string) $r['filter_slug'] : null,
            'unit'          => (string) $r['unit'],
            'is_filterable' => ((int) $r['is_filterable']) === 1,
            'sort_order'    => (int) $r['sort_order'],
        ], $rows);
    }

    /**
     * Atomically replace all specs for a device with a validated payload.
     *
     * @param list<array<string, mixed>> $rawSpecs
     * @return list<array<string, mixed>>
     */
    public function replaceSpecs(int $deviceId, array $rawSpecs): array
    {
        if ($this->devices->findById($deviceId) === null) {
            throw ValidationException::notFound('Device', $deviceId);
        }

        $validated = CatalogValidator::validateSpecs($rawSpecs);
        $table = $this->specsTable();
        $this->wpdb->delete($table, ['device_id' => $deviceId], ['%d']);

        foreach ($validated as $spec) {
            $this->wpdb->insert($table, array_merge(['device_id' => $deviceId], $spec));
        }

        return $this->listSpecs($deviceId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMedia(int $deviceId): array
    {
        $table = $this->mediaTable();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE device_id = %d ORDER BY media_role ASC, sort_order ASC, id ASC",
                $deviceId
            ),
            'ARRAY_A'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function (array $r): array {
            $attId = (int) $r['attachment_id'];

            return [
                'id'            => (int) $r['id'],
                'device_id'     => (int) $r['device_id'],
                'attachment_id' => $attId,
                'media_role'    => (string) $r['media_role'],
                'url'           => wp_get_attachment_url($attId) ?: null,
                'caption_fa'    => (string) $r['caption_fa'],
                'caption_en'    => (string) $r['caption_en'],
                'sort_order'    => (int) $r['sort_order'],
            ];
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rawMedia
     * @return list<array<string, mixed>>
     */
    public function replaceMedia(int $deviceId, array $rawMedia): array
    {
        if ($this->devices->findById($deviceId) === null) {
            throw ValidationException::notFound('Device', $deviceId);
        }

        $validated = CatalogValidator::validateMedia($rawMedia);
        foreach ($validated as $item) {
            if (get_post_type($item['attachment_id']) !== 'attachment') {
                throw ValidationException::invalidField(
                    'attachment_id',
                    "Attachment #{$item['attachment_id']} does not exist in WordPress Media Library."
                );
            }
        }

        $table = $this->mediaTable();
        $this->wpdb->delete($table, ['device_id' => $deviceId], ['%d']);

        foreach ($validated as $item) {
            $this->wpdb->insert($table, array_merge(['device_id' => $deviceId], $item));
        }

        return $this->listMedia($deviceId);
    }

    /**
     * List compatibility edges for a device, hydrated with live WooCommerce product or target device summaries.
     *
     * @return list<array<string, mixed>>
     */
    public function listCompatibility(int $deviceId, bool $excludeSkins = false): array
    {
        $table = $this->compatibilityTable();
        $filter = $excludeSkins ? "AND target_type != 'skin_definition'" : '';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_device_id = %d {$filter} ORDER BY relation_type ASC, id ASC",
                $deviceId
            ),
            'ARRAY_A'
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $wcIds = [];
        foreach ($rows as $row) {
            if ($row['target_type'] === 'wc_product') {
                $wcIds[] = (int) $row['target_id'];
            }
        }
        $wcMap = $this->wcHydrator->hydrateMany($wcIds);

        $items = [];
        foreach ($rows as $row) {
            $targetType = (string) $row['target_type'];
            $targetId = (int) $row['target_id'];

            $entry = [
                'id'               => (int) $row['id'],
                'source_device_id' => (int) $row['source_device_id'],
                'target_type'      => $targetType,
                'target_id'        => $targetId,
                'relation_type'    => (string) $row['relation_type'],
                'fit_confidence'   => (string) $row['fit_confidence'],
                'notes_fa'         => (string) $row['notes_fa'],
                'notes_en'         => (string) $row['notes_en'],
                'created_at'       => (string) $row['created_at'],
            ];

            if ($targetType === 'wc_product' && isset($wcMap[$targetId])) {
                $entry['wc_product'] = $wcMap[$targetId];
            } elseif ($targetType === 'device') {
                $targetDev = $this->devices->findById($targetId);
                if ($targetDev !== null) {
                    $entry['device'] = [
                        'id'         => $targetDev['id'],
                        'slug'       => $targetDev['slug'],
                        'model_code' => $targetDev['model_code'],
                        'name_fa'    => $targetDev['name_fa'],
                        'name_en'    => $targetDev['name_en'],
                    ];
                }
            }

            $items[] = $entry;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addCompatibility(int $sourceDeviceId, array $input): array
    {
        if ($this->devices->findById($sourceDeviceId) === null) {
            throw ValidationException::notFound('Device', $sourceDeviceId);
        }

        $clean = CatalogValidator::validateCompatibility($sourceDeviceId, $input);

        if ($clean['target_type'] === 'device' && $this->devices->findById($clean['target_id']) === null) {
            throw ValidationException::notFound('Target device', $clean['target_id']);
        }
        if ($clean['target_type'] === 'wc_product' && !$this->wcHydrator->productExists($clean['target_id'])) {
            throw ValidationException::notFound('WooCommerce product', $clean['target_id']);
        }

        $table = $this->compatibilityTable();
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_device_id = %d AND target_type = %s AND target_id = %d AND relation_type = %s LIMIT 1",
                $clean['source_device_id'],
                $clean['target_type'],
                $clean['target_id'],
                $clean['relation_type']
            )
        );
        if ($exists !== null) {
            throw ValidationException::conflict('compatibility', 'This compatibility relationship already exists.');
        }

        $this->wpdb->insert($table, array_merge($clean, ['created_at' => gmdate('Y-m-d H:i:s')]));
        $insertedId = (int) $this->wpdb->insert_id;

        foreach ($this->listCompatibility($sourceDeviceId) as $edge) {
            if ($edge['id'] === $insertedId) {
                return $edge;
            }
        }

        throw ValidationException::notFound('Compatibility', $insertedId);
    }

    public function deleteCompatibility(int $sourceDeviceId, int $edgeId): void
    {
        $deleted = $this->wpdb->delete(
            $this->compatibilityTable(),
            ['id' => $edgeId, 'source_device_id' => $sourceDeviceId],
            ['%d', '%d']
        );

        if ($deleted === false || $deleted === 0) {
            throw ValidationException::notFound('Compatibility', $edgeId);
        }
    }

    /**
     * Reverse lookup: find all compatible DJ devices for a given WooCommerce product ID.
     *
     * @return list<array<string, mixed>>
     */
    public function findDevicesForWooCommerceProduct(int $wcProductId, bool $activeOnly = true): array
    {
        $table = $this->compatibilityTable();
        $indexTable = $this->devices->indexTable();
        $whereActive = $activeOnly ? "AND idx.lifecycle_status = 'active'" : '';

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT c.source_device_id, c.relation_type, c.fit_confidence
                 FROM {$table} c
                 INNER JOIN {$indexTable} idx ON idx.device_id = c.source_device_id
                 WHERE c.target_type = 'wc_product' AND c.target_id = %d {$whereActive}",
                $wcProductId
            ),
            'ARRAY_A'
        );

        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $device = $this->devices->findById((int) $row['source_device_id']);
            if ($device !== null) {
                $device['compatibility_relation'] = (string) $row['relation_type'];
                $device['fit_confidence'] = (string) $row['fit_confidence'];
                $result[] = $device;
            }
        }

        return $result;
    }

    /**
     * Clean up stale WooCommerce product edges when a product is permanently deleted.
     */
    public function purgeWooCommerceProductLinks(int $wcProductId): void
    {
        $this->wpdb->delete(
            $this->compatibilityTable(),
            ['target_type' => 'wc_product', 'target_id' => $wcProductId],
            ['%s', '%d']
        );
    }
}
