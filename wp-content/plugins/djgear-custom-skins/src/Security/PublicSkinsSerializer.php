<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Security;

/**
 * Explicit allowlist serializers for public storefront options and customer-facing job payloads.
 *
 * Security Guarantees:
 * - Public payloads NEVER expose `internal_notes`, staff assignments, private storage paths,
 *   or inactive variants/plans.
 * - Customer payloads NEVER expose `internal_notes`, `storage_rel_path`, or internal-only history rows.
 */
final class PublicSkinsSerializer
{
    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    public static function publicVariant(array $variant): array
    {
        return [
            'id'                => (int) ($variant['id'] ?? 0),
            'device_id'         => (int) ($variant['device_id'] ?? 0),
            'wc_product_id'     => (int) ($variant['wc_product_id'] ?? 0),
            'wc_variation_id'   => isset($variant['wc_variation_id']) ? (int) $variant['wc_variation_id'] : null,
            'model_code'        => (string) ($variant['model_code'] ?? ''),
            'model_name_fa'     => (string) ($variant['model_name_fa'] ?? ''),
            'model_name_en'     => (string) ($variant['model_name_en'] ?? ''),
            'color_code'        => (string) ($variant['color_code'] ?? ''),
            'color_name_fa'     => (string) ($variant['color_name_fa'] ?? ''),
            'color_name_en'     => (string) ($variant['color_name_en'] ?? ''),
            'color_hex'         => (string) ($variant['color_hex'] ?? '#111827'),
            'finish'            => (string) ($variant['finish'] ?? 'matte'),
            'preview_image_url' => isset($variant['preview_image_url']) && is_string($variant['preview_image_url'])
                ? $variant['preview_image_url']
                : null,
            'print_width_mm'    => isset($variant['print_width_mm']) ? (float) $variant['print_width_mm'] : null,
            'print_height_mm'   => isset($variant['print_height_mm']) ? (float) $variant['print_height_mm'] : null,
            'base_price'        => isset($variant['base_price']) ? (float) $variant['base_price'] : null,
            'currency'          => (string) ($variant['currency'] ?? ''),
            'in_stock'          => (bool) ($variant['in_stock'] ?? true),
            'sort_order'        => (int) ($variant['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function publicPlan(array $plan): array
    {
        return [
            'id'                      => (int) ($plan['id'] ?? 0),
            'slug'                    => (string) ($plan['slug'] ?? ''),
            'name_fa'                 => (string) ($plan['name_fa'] ?? ''),
            'name_en'                 => (string) ($plan['name_en'] ?? ''),
            'description_fa'          => (string) ($plan['description_fa'] ?? ''),
            'description_en'          => (string) ($plan['description_en'] ?? ''),
            'plan_type'               => (string) ($plan['plan_type'] ?? 'standard'),
            'allows_artwork_upload'   => (bool) ($plan['allows_artwork_upload'] ?? false),
            'requires_artwork'        => (bool) ($plan['requires_artwork'] ?? false),
            'requires_proof_approval' => (bool) ($plan['requires_proof_approval'] ?? false),
            'max_revisions'           => (int) ($plan['max_revisions'] ?? 0),
            'price_surcharge'         => (float) ($plan['price_surcharge'] ?? 0.0),
            'estimated_lead_days'     => (int) ($plan['estimated_lead_days'] ?? 3),
            'features_fa'             => is_array($plan['features_fa'] ?? null) ? array_values($plan['features_fa']) : [],
            'features_en'             => is_array($plan['features_en'] ?? null) ? array_values($plan['features_en']) : [],
            'sort_order'              => (int) ($plan['sort_order'] ?? 0),
        ];
    }

    /**
     * Customer-safe representation of an artwork or designer proof revision.
     * Strips filesystem storage path (`storage_rel_path`).
     *
     * @param array<string, mixed> $artwork
     * @return array<string, mixed>
     */
    public static function customerArtwork(array $artwork, string $jobUuid): array
    {
        $id = (int) ($artwork['id'] ?? 0);

        return [
            'id'                   => $id,
            'revision_type'        => (string) ($artwork['revision_type'] ?? ''),
            'version_number'       => (int) ($artwork['version_number'] ?? 1),
            'original_filename'    => (string) ($artwork['original_filename'] ?? ''),
            'mime_type'            => (string) ($artwork['mime_type'] ?? ''),
            'file_size_bytes'      => (int) ($artwork['file_size_bytes'] ?? 0),
            'sha256_hash'          => (string) ($artwork['sha256_hash'] ?? ''),
            'pixel_width'          => isset($artwork['pixel_width']) ? (int) $artwork['pixel_width'] : null,
            'pixel_height'         => isset($artwork['pixel_height']) ? (int) $artwork['pixel_height'] : null,
            'computed_dpi'         => isset($artwork['computed_dpi']) ? (float) $artwork['computed_dpi'] : null,
            'dpi_status'           => (string) ($artwork['dpi_status'] ?? 'not_applicable'),
            'preflight_report'     => is_array($artwork['preflight_report'] ?? null) ? $artwork['preflight_report'] : null,
            'low_dpi_acknowledged' => (bool) ($artwork['low_dpi_acknowledged'] ?? false),
            'customer_decision'    => (string) ($artwork['customer_decision'] ?? 'pending'),
            'decision_at'          => $artwork['decision_at'] ?? null,
            'note_fa'              => $artwork['note_fa'] ?? null,
            'note_en'              => $artwork['note_en'] ?? null,
            'created_at'           => (string) ($artwork['created_at'] ?? ''),
            'download_url'         => function_exists('rest_url')
                ? rest_url(sprintf('djgear/v1/skins/jobs/%s/artworks/%d/download', $jobUuid, $id))
                : sprintf('/wp-json/djgear/v1/skins/jobs/%s/artworks/%d/download', $jobUuid, $id),
        ];
    }

    /**
     * Customer-safe job payload (strictly omits `internal_notes`, `assigned_staff_id`, and internal history entries).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function customerJob(array $job): array
    {
        $uuid = (string) ($job['job_uuid'] ?? '');

        $artworks = [];
        if (is_array($job['artworks'] ?? null)) {
            foreach ($job['artworks'] as $rev) {
                if (is_array($rev)) {
                    $artworks[] = self::customerArtwork($rev, $uuid);
                }
            }
        }

        $history = [];
        if (is_array($job['status_history'] ?? null)) {
            foreach ($job['status_history'] as $row) {
                if (!is_array($row) || !((bool) ($row['is_customer_visible'] ?? false))) {
                    continue;
                }
                $history[] = [
                    'id'          => (int) ($row['id'] ?? 0),
                    'from_status' => $row['from_status'] ?? null,
                    'to_status'   => (string) ($row['to_status'] ?? ''),
                    'actor_role'  => (string) ($row['actor_role'] ?? 'system'),
                    'comment'     => $row['comment'] ?? null,
                    'created_at'  => (string) ($row['created_at'] ?? ''),
                ];
            }
        }

        return [
            'job_uuid'           => $uuid,
            'wc_order_id'        => isset($job['wc_order_id']) ? (int) $job['wc_order_id'] : null,
            'wc_order_status'    => $job['wc_order_status'] ?? null,
            'wc_order_url'       => $job['wc_order_url'] ?? null,
            'device_id'          => (int) ($job['device_id'] ?? 0),
            'skin_variant_id'    => (int) ($job['skin_variant_id'] ?? 0),
            'plan_id'            => (int) ($job['plan_id'] ?? 0),
            'snapshot'           => is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [],
            'status'             => (string) ($job['status'] ?? 'draft'),
            'current_artwork_id' => isset($job['current_artwork_id']) ? (int) $job['current_artwork_id'] : null,
            'current_proof_id'   => isset($job['current_proof_id']) ? (int) $job['current_proof_id'] : null,
            'approved_proof_id'  => isset($job['approved_proof_id']) ? (int) $job['approved_proof_id'] : null,
            'approved_at'        => $job['approved_at'] ?? null,
            'customer_notes'     => $job['customer_notes'] ?? null,
            'tracking_code'      => $job['tracking_code'] ?? null,
            'artworks'           => $artworks,
            'status_history'     => $history,
            'created_at'         => (string) ($job['created_at'] ?? ''),
            'updated_at'         => (string) ($job['updated_at'] ?? ''),
        ];
    }
}
