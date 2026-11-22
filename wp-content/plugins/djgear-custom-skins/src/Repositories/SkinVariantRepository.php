<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Repositories;

use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Domain\SkinValidator;
use DJGear\CustomSkins\Lifecycle\Migrations\CreateCustomSkinTables;
use wpdb;

/**
 * Repository for commercial skin variants (`{prefix}djgear_skin_variants`).
 * Links DJ devices from `djgear-device-catalog` to commercial skin models, colors/finishes, and WooCommerce products.
 */
final class SkinVariantRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
    ) {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . CreateCustomSkinTables::TABLE_VARIANTS;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if (isset($filters['device_id']) && is_numeric($filters['device_id']) && (int) $filters['device_id'] > 0) {
            $where[] = 'device_id = %d';
            $params[] = (int) $filters['device_id'];
        }
        if (isset($filters['status']) && is_string($filters['status']) && in_array($filters['status'], SkinValidator::VALID_VARIANT_STATUSES, true)) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $like = '%' . $this->wpdb->esc_like(trim($filters['q'])) . '%';
            $where[] = '(model_code LIKE %s OR model_name_fa LIKE %s OR model_name_en LIKE %s OR color_code LIKE %s OR color_name_fa LIKE %s OR color_name_en LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $table = $this->table();

        $countSql = "SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}";
        $total = $params !== []
            ? (int) $this->wpdb->get_var($this->wpdb->prepare($countSql, ...$params))
            : (int) $this->wpdb->get_var($countSql);

        $rowsSql = "SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY sort_order ASC, id DESC LIMIT %d OFFSET %d";
        $queryParams = [...$params, $perPage, $offset];
        $rows = $this->wpdb->get_results($this->wpdb->prepare($rowsSql, ...$queryParams), 'ARRAY_A');

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->hydrate($row);
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
     * @return list<array<string, mixed>>
     */
    public function listActiveForDevice(int $deviceId): array
    {
        $table = $this->table();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE device_id = %d AND status = 'active' ORDER BY sort_order ASC, id ASC",
                $deviceId
            ),
            'ARRAY_A'
        );

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = $this->hydrate($row);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $table = $this->table();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id),
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
        $clean = SkinValidator::validateVariant($input);
        $this->assertDeviceExists($clean['device_id']);
        $this->assertWcProductExists($clean['wc_product_id']);
        $this->assertUniqueDeviceModelColor($clean['device_id'], $clean['model_code'], $clean['color_code'], null);

        $inserted = $this->wpdb->insert($this->table(), [
            'device_id'             => $clean['device_id'],
            'wc_product_id'         => $clean['wc_product_id'],
            'wc_variation_id'       => $clean['wc_variation_id'],
            'model_code'            => $clean['model_code'],
            'model_name_fa'         => $clean['model_name_fa'],
            'model_name_en'         => $clean['model_name_en'],
            'color_code'            => $clean['color_code'],
            'color_name_fa'         => $clean['color_name_fa'],
            'color_name_en'         => $clean['color_name_en'],
            'color_hex'             => $clean['color_hex'],
            'finish'                => $clean['finish'],
            'preview_attachment_id' => $clean['preview_attachment_id'],
            'print_width_mm'        => $clean['print_width_mm'],
            'print_height_mm'       => $clean['print_height_mm'],
            'status'                => $clean['status'],
            'sort_order'            => $clean['sort_order'],
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ]);

        if ($inserted === false) {
            throw SkinValidationException::conflict('model_code', 'Failed to create skin variant: ' . $this->wpdb->last_error);
        }

        $created = $this->findById((int) $this->wpdb->insert_id);
        if ($created === null) {
            throw new SkinValidationException('Created skin variant could not be reloaded.', 500);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw SkinValidationException::notFound('skin_variant', 'Skin variant not found.');
        }

        $merged = array_merge($existing, $input);
        $clean = SkinValidator::validateVariant($merged);
        $this->assertDeviceExists($clean['device_id']);
        $this->assertWcProductExists($clean['wc_product_id']);
        $this->assertUniqueDeviceModelColor($clean['device_id'], $clean['model_code'], $clean['color_code'], $id);

        $updated = $this->wpdb->update(
            $this->table(),
            [
                'device_id'             => $clean['device_id'],
                'wc_product_id'         => $clean['wc_product_id'],
                'wc_variation_id'       => $clean['wc_variation_id'],
                'model_code'            => $clean['model_code'],
                'model_name_fa'         => $clean['model_name_fa'],
                'model_name_en'         => $clean['model_name_en'],
                'color_code'            => $clean['color_code'],
                'color_name_fa'         => $clean['color_name_fa'],
                'color_name_en'         => $clean['color_name_en'],
                'color_hex'             => $clean['color_hex'],
                'finish'                => $clean['finish'],
                'preview_attachment_id' => $clean['preview_attachment_id'],
                'print_width_mm'        => $clean['print_width_mm'],
                'print_height_mm'       => $clean['print_height_mm'],
                'status'                => $clean['status'],
                'sort_order'            => $clean['sort_order'],
                'updated_at'            => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id]
        );

        if ($updated === false) {
            throw SkinValidationException::conflict('model_code', 'Failed to update skin variant: ' . $this->wpdb->last_error);
        }

        $reloaded = $this->findById($id);
        if ($reloaded === null) {
            throw SkinValidationException::notFound('skin_variant');
        }

        return $reloaded;
    }

    public function delete(int $id): bool
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw SkinValidationException::notFound('skin_variant', 'Skin variant not found.');
        }

        $deleted = $this->wpdb->delete($this->table(), ['id' => $id], ['%d']);
        return $deleted !== false && $deleted > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $wcProductId = (int) ($row['wc_product_id'] ?? 0);
        $wcVariationId = isset($row['wc_variation_id']) ? (int) $row['wc_variation_id'] : null;
        $previewId = isset($row['preview_attachment_id']) ? (int) $row['preview_attachment_id'] : null;

        $previewUrl = null;
        if ($previewId !== null && $previewId > 0 && function_exists('wp_get_attachment_image_url')) {
            $url = wp_get_attachment_image_url($previewId, 'large');
            if (is_string($url) && $url !== '') {
                $previewUrl = $url;
            }
        }

        $basePrice = null;
        $inStock = true;
        $wcProductName = null;
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'IRT';

        if (function_exists('wc_get_product')) {
            $targetId = $wcVariationId ?? $wcProductId;
            $product = wc_get_product($targetId);
            if ($product === false && $wcVariationId !== null) {
                $product = wc_get_product($wcProductId);
            }
            if ($product instanceof \WC_Product) {
                $priceRaw = $product->get_price();
                $basePrice = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
                $inStock = $product->is_in_stock();
                $wcProductName = $product->get_name();
            }
        }

        return [
            'id'                    => (int) ($row['id'] ?? 0),
            'device_id'             => (int) ($row['device_id'] ?? 0),
            'wc_product_id'         => $wcProductId,
            'wc_variation_id'       => $wcVariationId,
            'wc_product_name'       => $wcProductName,
            'model_code'            => (string) ($row['model_code'] ?? ''),
            'model_name_fa'         => (string) ($row['model_name_fa'] ?? ''),
            'model_name_en'         => (string) ($row['model_name_en'] ?? ''),
            'color_code'            => (string) ($row['color_code'] ?? ''),
            'color_name_fa'         => (string) ($row['color_name_fa'] ?? ''),
            'color_name_en'         => (string) ($row['color_name_en'] ?? ''),
            'color_hex'             => (string) ($row['color_hex'] ?? '#111827'),
            'finish'                => (string) ($row['finish'] ?? 'matte'),
            'preview_attachment_id' => $previewId,
            'preview_image_url'     => $previewUrl,
            'print_width_mm'        => isset($row['print_width_mm']) ? (float) $row['print_width_mm'] : null,
            'print_height_mm'       => isset($row['print_height_mm']) ? (float) $row['print_height_mm'] : null,
            'status'                => (string) ($row['status'] ?? 'active'),
            'sort_order'            => (int) ($row['sort_order'] ?? 0),
            'base_price'            => $basePrice,
            'currency'              => $currency,
            'in_stock'              => $inStock,
            'updated_at'            => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function assertDeviceExists(int $deviceId): void
    {
        $indexTable = $this->wpdb->prefix . 'djgear_device_index';
        $found = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT device_id FROM `{$indexTable}` WHERE device_id = %d", $deviceId)
        );
        if ((int) $found !== $deviceId) {
            throw SkinValidationException::invalidField('device_id', sprintf('Device #%d does not exist in djgear-device-catalog.', $deviceId));
        }
    }

    private function assertWcProductExists(int $wcProductId): void
    {
        if (!function_exists('wc_get_product')) {
            return;
        }
        $product = wc_get_product($wcProductId);
        if (!$product instanceof \WC_Product) {
            throw SkinValidationException::invalidField('wc_product_id', sprintf('WooCommerce product #%d does not exist.', $wcProductId));
        }
    }

    private function assertUniqueDeviceModelColor(int $deviceId, string $modelCode, string $colorCode, ?int $ignoreId): void
    {
        $table = $this->table();
        if ($ignoreId !== null) {
            $existingId = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE device_id = %d AND model_code = %s AND color_code = %s AND id != %d",
                    $deviceId,
                    $modelCode,
                    $colorCode,
                    $ignoreId
                )
            );
        } else {
            $existingId = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE device_id = %d AND model_code = %s AND color_code = %s",
                    $deviceId,
                    $modelCode,
                    $colorCode
                )
            );
        }

        if ($existingId !== null) {
            throw SkinValidationException::conflict(
                'color_code',
                'A skin variant with this device, model code, and color code already exists.'
            );
        }
    }
}
