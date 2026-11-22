<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Repositories;

use DJGear\DeviceCatalog\Domain\CanonicalKeyGenerator;
use DJGear\DeviceCatalog\Domain\CatalogValidator;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Lifecycle\Migrations\CreateCatalogTables;
use DJGear\DeviceCatalog\Taxonomy\CatalogPostTypes;
use WP_Error;
use WP_Post;
use wpdb;

/**
 * Canonical repository for DJ device models (1:1 `djgear_device` CPT + `{prefix}djgear_device_index`).
 *
 * Canonical Source-of-Truth Rule:
 * - Zero duplicate custom post_meta; all structured device fields live strictly in `djgear_device_index`.
 */
final class DeviceRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TaxonomyRepository $taxonomies,
    ) {
    }

    public function indexTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_INDEX;
    }

    public function specsTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_SPECS;
    }

    public function mediaTable(): string
    {
        return $this->wpdb->prefix . CreateCatalogTables::TABLE_MEDIA;
    }

    /**
     * Create a new DJ device model with database-enforced canonical duplicate prevention.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = CatalogValidator::validateDevice($input);

        $brand = $this->taxonomies->getBrandById($clean['brand_term_id']);
        if ($brand === null) {
            throw ValidationException::invalidField('brand_term_id', 'Brand does not exist.');
        }

        $category = $this->taxonomies->getCategoryById($clean['category_term_id']);
        if ($category === null) {
            throw ValidationException::invalidField('category_term_id', 'Category does not exist.');
        }

        $canonicalKey = CanonicalKeyGenerator::deviceKey((string) $brand['slug'], $clean['model_code']);
        $this->assertUniqueIdentity($canonicalKey, $clean['mpn'], null);

        $postStatus = $clean['lifecycle_status'] === 'draft' ? 'draft' : 'publish';
        $desiredSlug = CanonicalKeyGenerator::slugify((string) $brand['slug'] . '-' . $clean['model_code']);

        $postId = wp_insert_post([
            'post_type'   => CatalogPostTypes::POST_TYPE,
            'post_title'  => $clean['name_en'],
            'post_name'   => $desiredSlug,
            'post_status' => $postStatus,
        ], true);

        if ($postId instanceof WP_Error) {
            throw new ValidationException($postId->get_error_message(), 422);
        }

        wp_set_object_terms($postId, [$clean['brand_term_id']], CatalogPostTypes::TAXONOMY_BRAND, false);
        wp_set_object_terms($postId, [$clean['category_term_id']], CatalogPostTypes::TAXONOMY_CATEGORY, false);

        $searchTokens = trim(implode(' ', array_filter([
            (string) $brand['name_en'],
            (string) $brand['name_fa'],
            (string) $category['name_en'],
            (string) $category['name_fa'],
            $clean['model_code'],
            $clean['mpn'] ?? '',
            $clean['search_tokens'],
        ])));

        $inserted = $this->wpdb->insert(
            $this->indexTable(),
            [
                'device_id'             => $postId,
                'brand_term_id'         => $clean['brand_term_id'],
                'category_term_id'      => $clean['category_term_id'],
                'model_code'            => $clean['model_code'],
                'canonical_key'         => $canonicalKey,
                'mpn'                   => $clean['mpn'],
                'name_fa'               => $clean['name_fa'],
                'name_en'               => $clean['name_en'],
                'summary_fa'            => $clean['summary_fa'],
                'summary_en'            => $clean['summary_en'],
                'release_year'          => $clean['release_year'],
                'lifecycle_status'      => $clean['lifecycle_status'],
                'skin_ready'            => 0,
                'primary_wc_product_id' => $clean['primary_wc_product_id'],
                'search_tokens'         => $searchTokens,
                'updated_at'            => gmdate('Y-m-d H:i:s'),
            ]
        );

        if ($inserted === false) {
            wp_delete_post($postId, true);
            throw ValidationException::conflict('canonical_key', 'Duplicate device or database write error: ' . $this->wpdb->last_error);
        }

        $created = $this->findById($postId);
        if ($created === null) {
            throw ValidationException::notFound('Device', $postId);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $deviceId, array $input): array
    {
        $existing = $this->findById($deviceId);
        if ($existing === null) {
            throw ValidationException::notFound('Device', $deviceId);
        }

        $merged = array_merge($existing, $input);
        $clean = CatalogValidator::validateDevice($merged);

        $brand = $this->taxonomies->getBrandById($clean['brand_term_id']);
        if ($brand === null) {
            throw ValidationException::invalidField('brand_term_id', 'Brand does not exist.');
        }

        $category = $this->taxonomies->getCategoryById($clean['category_term_id']);
        if ($category === null) {
            throw ValidationException::invalidField('category_term_id', 'Category does not exist.');
        }

        $canonicalKey = CanonicalKeyGenerator::deviceKey((string) $brand['slug'], $clean['model_code']);
        $this->assertUniqueIdentity($canonicalKey, $clean['mpn'], $deviceId);

        $postStatus = $clean['lifecycle_status'] === 'draft' ? 'draft' : 'publish';
        wp_update_post([
            'ID'          => $deviceId,
            'post_title'  => $clean['name_en'],
            'post_status' => $postStatus,
        ]);

        wp_set_object_terms($deviceId, [$clean['brand_term_id']], CatalogPostTypes::TAXONOMY_BRAND, false);
        wp_set_object_terms($deviceId, [$clean['category_term_id']], CatalogPostTypes::TAXONOMY_CATEGORY, false);

        $searchTokens = trim(implode(' ', array_filter([
            (string) $brand['name_en'],
            (string) $brand['name_fa'],
            (string) $category['name_en'],
            (string) $category['name_fa'],
            $clean['model_code'],
            $clean['mpn'] ?? '',
            $clean['search_tokens'],
        ])));

        $this->wpdb->update(
            $this->indexTable(),
            [
                'brand_term_id'         => $clean['brand_term_id'],
                'category_term_id'      => $clean['category_term_id'],
                'model_code'            => $clean['model_code'],
                'canonical_key'         => $canonicalKey,
                'mpn'                   => $clean['mpn'],
                'name_fa'               => $clean['name_fa'],
                'name_en'               => $clean['name_en'],
                'summary_fa'            => $clean['summary_fa'],
                'summary_en'            => $clean['summary_en'],
                'release_year'          => $clean['release_year'],
                'lifecycle_status'      => $clean['lifecycle_status'],
                'primary_wc_product_id' => $clean['primary_wc_product_id'],
                'search_tokens'         => $searchTokens,
                'updated_at'            => gmdate('Y-m-d H:i:s'),
            ],
            ['device_id' => $deviceId]
        );

        $updated = $this->findById($deviceId);
        if ($updated === null) {
            throw ValidationException::notFound('Device', $deviceId);
        }

        return $updated;
    }

    public function delete(int $deviceId): void
    {
        $existing = $this->findById($deviceId);
        if ($existing === null) {
            throw ValidationException::notFound('Device', $deviceId);
        }

        $p = $this->wpdb->prefix;
        $this->wpdb->delete($p . CreateCatalogTables::TABLE_SPECS, ['device_id' => $deviceId], ['%d']);
        $this->wpdb->delete($p . CreateCatalogTables::TABLE_MEDIA, ['device_id' => $deviceId], ['%d']);
        $this->wpdb->delete($p . CreateCatalogTables::TABLE_COMPATIBILITY, ['source_device_id' => $deviceId], ['%d']);
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$p}" . CreateCatalogTables::TABLE_COMPATIBILITY . " WHERE target_type = 'device' AND target_id = %d",
                $deviceId
            )
        );
        $this->wpdb->delete($this->indexTable(), ['device_id' => $deviceId], ['%d']);
        wp_delete_post($deviceId, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $deviceId): ?array
    {
        $table = $this->indexTable();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE device_id = %d", $deviceId),
            'ARRAY_A'
        );

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, bool $activeOnly = false): ?array
    {
        $postsTable = $this->wpdb->posts;
        $indexTable = $this->indexTable();

        $sql = $activeOnly
            ? $this->wpdb->prepare(
                "SELECT idx.* FROM {$indexTable} idx
                 INNER JOIN {$postsTable} p ON p.ID = idx.device_id
                 WHERE p.post_type = %s AND p.post_name = %s AND p.post_status = 'publish' AND idx.lifecycle_status = 'active'
                 LIMIT 1",
                CatalogPostTypes::POST_TYPE,
                $slug
            )
            : $this->wpdb->prepare(
                "SELECT idx.* FROM {$indexTable} idx
                 INNER JOIN {$postsTable} p ON p.ID = idx.device_id
                 WHERE p.post_type = %s AND p.post_name = %s
                 LIMIT 1",
                CatalogPostTypes::POST_TYPE,
                $slug
            );

        $row = $this->wpdb->get_row($sql, 'ARRAY_A');

        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCanonicalKey(string $canonicalKey): ?array
    {
        $table = $this->indexTable();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE canonical_key = %s LIMIT 1", $canonicalKey),
            'ARRAY_A'
        );

        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    /**
     * Paginated search and multi-facet filter across devices.
     *
     * @param array{
     *   q?: string,
     *   brand?: string|int,
     *   category?: string|int,
     *   status?: string,
     *   skin_ready?: ?bool,
     *   spec?: array<string, string>,
     *   page?: int,
     *   per_page?: int,
     *   active_only?: bool
     * } $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function search(array $filters): array
    {
        $indexTable = $this->indexTable();
        $postsTable = $this->wpdb->posts;
        $specsTable = $this->specsTable();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['p.post_type = %s'];
        $params = [CatalogPostTypes::POST_TYPE];
        $joins = ["INNER JOIN {$postsTable} p ON p.ID = idx.device_id"];

        if (!empty($filters['active_only'])) {
            $where[] = "p.post_status = 'publish'";
            $where[] = "idx.lifecycle_status = 'active'";
        } elseif (!empty($filters['status']) && in_array($filters['status'], CatalogValidator::LIFECYCLE_STATUSES, true)) {
            $where[] = 'idx.lifecycle_status = %s';
            $params[] = $filters['status'];
        }

        if (isset($filters['brand']) && $filters['brand'] !== '') {
            $brandId = is_numeric($filters['brand']) ? (int) $filters['brand'] : 0;
            if ($brandId === 0) {
                $term = get_term_by('slug', (string) $filters['brand'], CatalogPostTypes::TAXONOMY_BRAND);
                $brandId = $term instanceof \WP_Term ? (int) $term->term_id : -1;
            }
            $where[] = 'idx.brand_term_id = %d';
            $params[] = $brandId;
        }

        if (isset($filters['category']) && $filters['category'] !== '') {
            $catId = is_numeric($filters['category']) ? (int) $filters['category'] : 0;
            if ($catId === 0) {
                $term = get_term_by('slug', (string) $filters['category'], CatalogPostTypes::TAXONOMY_CATEGORY);
                $catId = $term instanceof \WP_Term ? (int) $term->term_id : -1;
            }
            $catIds = [$catId];
            if ($catId > 0) {
                $children = get_term_children($catId, CatalogPostTypes::TAXONOMY_CATEGORY);
                if (is_array($children)) {
                    foreach ($children as $childId) {
                        $catIds[] = (int) $childId;
                    }
                }
            }
            $placeholders = implode(',', array_fill(0, count($catIds), '%d'));
            $where[] = "idx.category_term_id IN ({$placeholders})";
            foreach ($catIds as $cid) {
                $params[] = $cid;
            }
        }

        if (isset($filters['skin_ready'])) {
            $where[] = 'idx.skin_ready = %d';
            $params[] = $filters['skin_ready'] ? 1 : 0;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $this->wpdb->esc_like($q) . '%';
            $where[] = '(idx.model_code LIKE %s OR idx.name_en LIKE %s OR idx.name_fa LIKE %s OR idx.mpn LIKE %s OR idx.search_tokens LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['spec'])) {
            $specIdx = 0;
            foreach ($filters['spec'] as $specKey => $specVal) {
                $cleanKey = str_replace('-', '_', CanonicalKeyGenerator::slugify((string) $specKey));
                $cleanVal = CanonicalKeyGenerator::slugify((string) $specVal);
                if ($cleanKey === '' || $cleanVal === '') {
                    continue;
                }
                $alias = 'sp' . $specIdx++;
                $joins[] = "INNER JOIN {$specsTable} {$alias} ON {$alias}.device_id = idx.device_id AND {$alias}.spec_key = %s AND {$alias}.filter_slug = %s";
                $params[] = $cleanKey;
                $params[] = $cleanVal;
            }
        }

        $joinSql = implode(' ', $joins);
        $whereSql = implode(' AND ', $where);

        $countSql = $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT idx.device_id) FROM {$indexTable} idx {$joinSql} WHERE {$whereSql}",
            ...$params
        );
        $total = (int) $this->wpdb->get_var($countSql);

        $rowsParams = [...$params, $perPage, $offset];
        $rowsSql = $this->wpdb->prepare(
            "SELECT DISTINCT idx.* FROM {$indexTable} idx {$joinSql} WHERE {$whereSql} ORDER BY idx.updated_at DESC, idx.device_id DESC LIMIT %d OFFSET %d",
            ...$rowsParams
        );
        $rows = $this->wpdb->get_results($rowsSql, 'ARRAY_A');
        if (!is_array($rows)) {
            $rows = [];
        }

        return [
            'items'    => array_map([$this, 'hydrateRow'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function countByBrand(int $brandTermId): int
    {
        $table = $this->indexTable();

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE brand_term_id = %d", $brandTermId)
        );
    }

    public function countByCategory(int $categoryTermId): int
    {
        $table = $this->indexTable();

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE category_term_id = %d", $categoryTermId)
        );
    }

    public function setSkinReady(int $deviceId, bool $ready): void
    {
        $this->wpdb->update(
            $this->indexTable(),
            ['skin_ready' => $ready ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['device_id' => $deviceId],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Collect filterable spec facets across active devices for public/admin filter UIs.
     *
     * @return list<array{spec_key: string, label_fa: string, label_en: string, values: list<array{slug: string, value_fa: string, value_en: string, count: int}>}>
     */
    public function availableSpecFacets(bool $activeOnly = true): array
    {
        $specsTable = $this->specsTable();
        $indexTable = $this->indexTable();
        $where = $activeOnly ? "AND idx.lifecycle_status = 'active'" : '';

        $rows = $this->wpdb->get_results(
            "SELECT sp.spec_key, sp.label_fa, sp.label_en, sp.filter_slug, sp.value_fa, sp.value_en, COUNT(DISTINCT sp.device_id) AS cnt
             FROM {$specsTable} sp
             INNER JOIN {$indexTable} idx ON idx.device_id = sp.device_id
             WHERE sp.is_filterable = 1 AND sp.filter_slug IS NOT NULL AND sp.filter_slug != '' {$where}
             GROUP BY sp.spec_key, sp.label_fa, sp.label_en, sp.filter_slug, sp.value_fa, sp.value_en
             ORDER BY sp.spec_key ASC, cnt DESC",
            'ARRAY_A'
        );

        if (!is_array($rows)) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row['spec_key'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'spec_key' => $key,
                    'label_fa' => (string) $row['label_fa'],
                    'label_en' => (string) $row['label_en'],
                    'values'   => [],
                ];
            }
            $grouped[$key]['values'][] = [
                'slug'     => (string) $row['filter_slug'],
                'value_fa' => (string) $row['value_fa'],
                'value_en' => (string) $row['value_en'],
                'count'    => (int) $row['cnt'],
            ];
        }

        return array_values($grouped);
    }

    private function assertUniqueIdentity(string $canonicalKey, ?string $mpn, ?int $ignoreDeviceId): void
    {
        $table = $this->indexTable();

        $existingId = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT device_id FROM {$table} WHERE canonical_key = %s LIMIT 1", $canonicalKey)
        );
        if ($existingId !== null && (int) $existingId !== $ignoreDeviceId) {
            throw ValidationException::conflict(
                'canonical_key',
                "A device with canonical key '{$canonicalKey}' already exists (ID #{$existingId})."
            );
        }

        if ($mpn !== null && $mpn !== '') {
            $mpnOwner = $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT device_id FROM {$table} WHERE mpn = %s LIMIT 1", $mpn)
            );
            if ($mpnOwner !== null && (int) $mpnOwner !== $ignoreDeviceId) {
                throw ValidationException::conflict(
                    'mpn',
                    "A device with MPN '{$mpn}' already exists (ID #{$mpnOwner})."
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $deviceId = (int) $row['device_id'];
        $post = get_post($deviceId);
        $slug = $post instanceof WP_Post ? (string) $post->post_name : '';

        $brandId = (int) $row['brand_term_id'];
        $catId = (int) $row['category_term_id'];

        $heroUrl = $this->resolveHeroImageUrl($deviceId);

        return [
            'id'                    => $deviceId,
            'slug'                  => $slug,
            'brand_term_id'         => $brandId,
            'category_term_id'      => $catId,
            'brand'                 => $this->taxonomies->getBrandById($brandId),
            'category'              => $this->taxonomies->getCategoryById($catId),
            'model_code'            => (string) $row['model_code'],
            'canonical_key'         => (string) $row['canonical_key'],
            'mpn'                   => $row['mpn'] !== null ? (string) $row['mpn'] : null,
            'name_fa'               => (string) $row['name_fa'],
            'name_en'               => (string) $row['name_en'],
            'summary_fa'            => (string) ($row['summary_fa'] ?? ''),
            'summary_en'            => (string) ($row['summary_en'] ?? ''),
            'release_year'          => $row['release_year'] !== null ? (int) $row['release_year'] : null,
            'lifecycle_status'      => (string) $row['lifecycle_status'],
            'skin_ready'            => ((int) $row['skin_ready']) === 1,
            'primary_wc_product_id' => $row['primary_wc_product_id'] !== null ? (int) $row['primary_wc_product_id'] : null,
            'hero_image_url'        => $heroUrl,
            'updated_at'            => (string) $row['updated_at'],
        ];
    }

    private function resolveHeroImageUrl(int $deviceId): ?string
    {
        $mediaTable = $this->mediaTable();
        $attachmentId = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT attachment_id FROM {$mediaTable} WHERE device_id = %d AND media_role = 'hero' ORDER BY sort_order ASC LIMIT 1",
                $deviceId
            )
        );

        if ($attachmentId !== null && (int) $attachmentId > 0) {
            return wp_get_attachment_image_url((int) $attachmentId, 'large') ?: null;
        }

        $thumbId = (int) get_post_thumbnail_id($deviceId);

        return $thumbId > 0 ? (wp_get_attachment_image_url($thumbId, 'large') ?: null) : null;
    }
}
