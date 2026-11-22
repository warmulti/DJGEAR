<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Domain;

/**
 * Pure input validation & normalization helpers for skin variants, ordering plans, and job requests.
 */
final class SkinValidator
{
    public const VALID_FINISHES = ['matte', 'gloss', 'satin', 'carbon', 'brushed_metal'];
    public const VALID_VARIANT_STATUSES = ['active', 'inactive'];
    public const VALID_PLAN_TYPES = ['standard', 'custom_design'];

    /**
     * Validate and normalize a skin variant (model + color + WC product link) payload.
     *
     * @param array<string, mixed> $input
     * @return array{
     *     device_id: int,
     *     wc_product_id: int,
     *     wc_variation_id: ?int,
     *     model_code: string,
     *     model_name_fa: string,
     *     model_name_en: string,
     *     color_code: string,
     *     color_name_fa: string,
     *     color_name_en: string,
     *     color_hex: string,
     *     finish: string,
     *     preview_attachment_id: ?int,
     *     print_width_mm: ?float,
     *     print_height_mm: ?float,
     *     status: string,
     *     sort_order: int
     * }
     */
    public static function validateVariant(array $input): array
    {
        $deviceId = self::positiveInt($input['device_id'] ?? null, 'device_id');
        $wcProductId = self::positiveInt($input['wc_product_id'] ?? null, 'wc_product_id');
        $wcVariationId = self::optionalPositiveInt($input['wc_variation_id'] ?? null, 'wc_variation_id');

        $modelCode = self::codeSlug($input['model_code'] ?? '', 'model_code', 64);
        $modelNameFa = self::requiredText($input['model_name_fa'] ?? '', 'model_name_fa', 140);
        $modelNameEn = self::requiredText($input['model_name_en'] ?? '', 'model_name_en', 140);

        $colorCode = self::codeSlug($input['color_code'] ?? '', 'color_code', 64);
        $colorNameFa = self::requiredText($input['color_name_fa'] ?? '', 'color_name_fa', 120);
        $colorNameEn = self::requiredText($input['color_name_en'] ?? '', 'color_name_en', 120);

        $colorHexRaw = is_string($input['color_hex'] ?? null) ? trim($input['color_hex']) : '#111827';
        if ($colorHexRaw === '') {
            $colorHexRaw = '#111827';
        }
        if (preg_match('/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $colorHexRaw) !== 1) {
            throw SkinValidationException::invalidField('color_hex', 'color_hex must be a valid CSS hex color (e.g. #111827).');
        }

        $finish = is_string($input['finish'] ?? null) ? strtolower(trim($input['finish'])) : 'matte';
        if (!in_array($finish, self::VALID_FINISHES, true)) {
            throw SkinValidationException::invalidField('finish', 'finish must be one of: ' . implode(', ', self::VALID_FINISHES) . '.');
        }

        $previewId = self::optionalPositiveInt($input['preview_attachment_id'] ?? null, 'preview_attachment_id');
        $printWidth = self::optionalPositiveFloat($input['print_width_mm'] ?? null, 'print_width_mm', 10.0, 3000.0);
        $printHeight = self::optionalPositiveFloat($input['print_height_mm'] ?? null, 'print_height_mm', 10.0, 3000.0);

        $status = is_string($input['status'] ?? null) ? strtolower(trim($input['status'])) : 'active';
        if (!in_array($status, self::VALID_VARIANT_STATUSES, true)) {
            throw SkinValidationException::invalidField('status', 'status must be active or inactive.');
        }

        $sortOrder = isset($input['sort_order']) && is_numeric($input['sort_order'])
            ? max(0, min(65535, (int) $input['sort_order']))
            : 0;

        return [
            'device_id'             => $deviceId,
            'wc_product_id'         => $wcProductId,
            'wc_variation_id'       => $wcVariationId,
            'model_code'            => $modelCode,
            'model_name_fa'         => $modelNameFa,
            'model_name_en'         => $modelNameEn,
            'color_code'            => $colorCode,
            'color_name_fa'         => $colorNameFa,
            'color_name_en'         => $colorNameEn,
            'color_hex'             => strtoupper($colorHexRaw),
            'finish'                => $finish,
            'preview_attachment_id' => $previewId,
            'print_width_mm'        => $printWidth,
            'print_height_mm'       => $printHeight,
            'status'                => $status,
            'sort_order'            => $sortOrder,
        ];
    }

    /**
     * Validate and normalize an ordering plan payload.
     *
     * @param array<string, mixed> $input
     * @return array{
     *     slug: string,
     *     name_fa: string,
     *     name_en: string,
     *     description_fa: string,
     *     description_en: string,
     *     plan_type: string,
     *     allows_artwork_upload: bool,
     *     requires_artwork: bool,
     *     requires_proof_approval: bool,
     *     max_revisions: int,
     *     price_surcharge: float,
     *     estimated_lead_days: int,
     *     features_fa: list<string>,
     *     features_en: list<string>,
     *     is_active: bool,
     *     sort_order: int
     * }
     */
    public static function validatePlan(array $input): array
    {
        $slug = self::codeSlug($input['slug'] ?? '', 'slug', 64);
        $nameFa = self::requiredText($input['name_fa'] ?? '', 'name_fa', 140);
        $nameEn = self::requiredText($input['name_en'] ?? '', 'name_en', 140);
        $descFa = self::optionalText($input['description_fa'] ?? '', 2000);
        $descEn = self::optionalText($input['description_en'] ?? '', 2000);

        $planType = is_string($input['plan_type'] ?? null) ? strtolower(trim($input['plan_type'])) : 'standard';
        if (!in_array($planType, self::VALID_PLAN_TYPES, true)) {
            throw SkinValidationException::invalidField('plan_type', 'plan_type must be standard or custom_design.');
        }

        $allowsArtwork = self::toBool($input['allows_artwork_upload'] ?? ($planType === 'custom_design'));
        $requiresArtwork = self::toBool($input['requires_artwork'] ?? false);
        $requiresProof = self::toBool($input['requires_proof_approval'] ?? ($planType === 'custom_design'));

        // Business rule: standard plans NEVER allow or require artwork uploads.
        if ($planType === 'standard') {
            $allowsArtwork = false;
            $requiresArtwork = false;
            $requiresProof = false;
        }

        if ($requiresArtwork && !$allowsArtwork) {
            throw SkinValidationException::invalidField(
                'requires_artwork',
                'requires_artwork cannot be enabled when allows_artwork_upload is disabled.'
            );
        }

        $maxRevisions = isset($input['max_revisions']) && is_numeric($input['max_revisions'])
            ? max(0, min(20, (int) $input['max_revisions']))
            : ($planType === 'custom_design' ? 3 : 0);
        if ($planType === 'standard') {
            $maxRevisions = 0;
        }

        $surchargeRaw = $input['price_surcharge'] ?? 0;
        if (!is_numeric($surchargeRaw) || (float) $surchargeRaw < 0.0) {
            throw SkinValidationException::invalidField('price_surcharge', 'price_surcharge must be a non-negative number.');
        }
        $priceSurcharge = round((float) $surchargeRaw, 2);

        $leadDays = isset($input['estimated_lead_days']) && is_numeric($input['estimated_lead_days'])
            ? max(1, min(90, (int) $input['estimated_lead_days']))
            : 3;

        $featuresFa = self::sanitizeFeatureList($input['features_fa'] ?? []);
        $featuresEn = self::sanitizeFeatureList($input['features_en'] ?? []);

        $isActive = self::toBool($input['is_active'] ?? true);
        $sortOrder = isset($input['sort_order']) && is_numeric($input['sort_order'])
            ? max(0, min(65535, (int) $input['sort_order']))
            : 0;

        return [
            'slug'                    => $slug,
            'name_fa'                 => $nameFa,
            'name_en'                 => $nameEn,
            'description_fa'          => $descFa,
            'description_en'          => $descEn,
            'plan_type'               => $planType,
            'allows_artwork_upload'   => $allowsArtwork,
            'requires_artwork'        => $requiresArtwork,
            'requires_proof_approval' => $requiresProof,
            'max_revisions'           => $maxRevisions,
            'price_surcharge'         => $priceSurcharge,
            'estimated_lead_days'     => $leadDays,
            'features_fa'             => $featuresFa,
            'features_en'             => $featuresEn,
            'is_active'               => $isActive,
            'sort_order'              => $sortOrder,
        ];
    }

    public static function sanitizeNotes(mixed $value, int $maxLength = 2000): string
    {
        return self::optionalText($value, $maxLength);
    }

    public static function sanitizeTrackingCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim(strip_tags($value));
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > 120) {
            throw SkinValidationException::invalidField('tracking_code', 'tracking_code cannot exceed 120 characters.');
        }

        return $clean;
    }

    public static function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /**
     * @return list<string>
     */
    private static function sanitizeFeatureList(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode("\n", $raw)));
        }
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = trim(strip_tags((string) $item));
            if ($text !== '') {
                $clean[] = mb_substr($text, 0, 200);
            }
            if (count($clean) >= 15) {
                break;
            }
        }

        return $clean;
    }

    private static function codeSlug(mixed $raw, string $field, int $maxLength): string
    {
        if (!is_string($raw)) {
            throw SkinValidationException::invalidField($field, sprintf('%s is required.', $field));
        }

        $slug = strtolower(trim($raw));
        $slug = (string) preg_replace('/[^a-z0-9\-_]+/', '-', $slug);
        $slug = trim($slug, '-_');

        if ($slug === '' || strlen($slug) > $maxLength) {
            throw SkinValidationException::invalidField(
                $field,
                sprintf('%s must be 1-%d lowercase ASCII characters (a-z, 0-9, -, _).', $field, $maxLength)
            );
        }

        return $slug;
    }

    private static function requiredText(mixed $raw, string $field, int $maxLength): string
    {
        if (!is_string($raw)) {
            throw SkinValidationException::invalidField($field, sprintf('%s is required.', $field));
        }
        $clean = trim(strip_tags($raw));
        if ($clean === '' || mb_strlen($clean) > $maxLength) {
            throw SkinValidationException::invalidField(
                $field,
                sprintf('%s must be between 1 and %d characters.', $field, $maxLength)
            );
        }

        return $clean;
    }

    private static function optionalText(mixed $raw, int $maxLength): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $clean = trim(strip_tags($raw));

        return mb_substr($clean, 0, $maxLength);
    }

    private static function positiveInt(mixed $raw, string $field): int
    {
        if (!is_numeric($raw) || (int) $raw <= 0) {
            throw SkinValidationException::invalidField($field, sprintf('%s must be a positive integer.', $field));
        }

        return (int) $raw;
    }

    private static function optionalPositiveInt(mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        if (!is_numeric($raw) || (int) $raw <= 0) {
            throw SkinValidationException::invalidField($field, sprintf('%s must be a positive integer.', $field));
        }

        return (int) $raw;
    }

    private static function optionalPositiveFloat(mixed $raw, string $field, float $min, float $max): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw SkinValidationException::invalidField($field, sprintf('%s must be numeric.', $field));
        }
        $val = round((float) $raw, 2);
        if ($val < $min || $val > $max) {
            throw SkinValidationException::invalidField(
                $field,
                sprintf('%s must be between %.1f and %.1f.', $field, $min, $max)
            );
        }

        return $val;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
