<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Domain;

/**
 * Pure, unit-testable validation and sanitization rules for all Device Catalog entities.
 */
final class CatalogValidator
{
    /** @var list<string> */
    public const LIFECYCLE_STATUSES = ['active', 'discontinued', 'upcoming', 'draft'];

    /** @var list<string> */
    public const SPEC_GROUPS = ['general', 'controls', 'audio', 'io', 'dimensions', 'software'];

    /** @var list<string> */
    public const SPEC_VALUE_TYPES = ['string', 'number', 'boolean', 'enum'];

    /** @var list<string> */
    public const MEDIA_ROLES = ['hero', 'gallery', 'top_view', 'rear_io', 'skin_preview'];

    /** @var list<string> */
    public const COMPAT_TARGET_TYPES = ['device', 'wc_product', 'skin_definition'];

    /** @var list<string> */
    public const COMPAT_RELATIONS = [
        'accessory',
        'decksaver',
        'flight_case',
        'bag',
        'cable',
        'spare_part',
        'premade_skin',
        'compatible_skin',
        'companion',
        'successor',
    ];

    /** @var list<string> */
    public const FIT_CONFIDENCES = ['verified', 'manufacturer_spec', 'community_reported'];

    /** @var list<string> */
    public const SKIN_STATUSES = ['draft', 'active', 'deprecated'];

    /** @var list<string> */
    public const ALLOWED_FINISHES = ['matte', 'gloss', 'satin', 'carbon', 'brushed_metal', 'transparent'];

    /**
     * Validate and sanitize a device model payload.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   brand_term_id: int,
     *   category_term_id: int,
     *   model_code: string,
     *   mpn: ?string,
     *   name_fa: string,
     *   name_en: string,
     *   summary_fa: string,
     *   summary_en: string,
     *   release_year: ?int,
     *   lifecycle_status: string,
     *   primary_wc_product_id: ?int,
     *   search_tokens: string
     * }
     */
    public static function validateDevice(array $input): array
    {
        $brandId = isset($input['brand_term_id']) && is_numeric($input['brand_term_id']) ? (int) $input['brand_term_id'] : 0;
        $categoryId = isset($input['category_term_id']) && is_numeric($input['category_term_id']) ? (int) $input['category_term_id'] : 0;
        $modelCode = self::cleanText($input['model_code'] ?? '', 120);
        $nameEn = self::cleanText($input['name_en'] ?? '', 255);
        $nameFa = self::cleanText($input['name_fa'] ?? '', 255);

        if ($brandId <= 0) {
            throw ValidationException::invalidField('brand_term_id', 'A valid manufacturer/brand is required.');
        }
        if ($categoryId <= 0) {
            throw ValidationException::invalidField('category_term_id', 'A valid device category is required.');
        }
        if ($modelCode === '') {
            throw ValidationException::invalidField('model_code', 'Model code is required (e.g., DDJ-FLX4).');
        }
        if ($nameEn === '') {
            throw ValidationException::invalidField('name_en', 'English device name is required.');
        }
        if ($nameFa === '') {
            $nameFa = $nameEn;
        }

        $mpnRaw = self::cleanText($input['mpn'] ?? '', 100);
        $mpn = $mpnRaw !== '' ? strtoupper($mpnRaw) : null;

        $releaseYear = null;
        if (isset($input['release_year']) && $input['release_year'] !== '') {
            if (!is_numeric($input['release_year'])) {
                throw ValidationException::invalidField('release_year', 'Release year must be numeric.');
            }
            $year = (int) $input['release_year'];
            $maxYear = (int) gmdate('Y') + 2;
            if ($year < 1970 || $year > $maxYear) {
                throw ValidationException::invalidField('release_year', "Release year must be between 1970 and {$maxYear}.");
            }
            $releaseYear = $year;
        }

        $status = is_string($input['lifecycle_status'] ?? null) ? trim($input['lifecycle_status']) : 'active';
        if (!in_array($status, self::LIFECYCLE_STATUSES, true)) {
            throw ValidationException::invalidField('lifecycle_status', 'Invalid lifecycle status.');
        }

        $wcProductId = null;
        if (isset($input['primary_wc_product_id']) && is_numeric($input['primary_wc_product_id']) && (int) $input['primary_wc_product_id'] > 0) {
            $wcProductId = (int) $input['primary_wc_product_id'];
        }

        return [
            'brand_term_id'         => $brandId,
            'category_term_id'      => $categoryId,
            'model_code'            => $modelCode,
            'mpn'                   => $mpn,
            'name_fa'               => $nameFa,
            'name_en'               => $nameEn,
            'summary_fa'            => self::cleanText($input['summary_fa'] ?? '', 2000),
            'summary_en'            => self::cleanText($input['summary_en'] ?? '', 2000),
            'release_year'          => $releaseYear,
            'lifecycle_status'      => $status,
            'primary_wc_product_id' => $wcProductId,
            'search_tokens'         => self::cleanText($input['search_tokens'] ?? '', 1000),
        ];
    }

    /**
     * Validate a batch of structured specs for a device.
     *
     * @param list<array<string, mixed>> $specs
     * @return list<array{
     *   spec_group: string,
     *   spec_key: string,
     *   label_fa: string,
     *   label_en: string,
     *   value_fa: string,
     *   value_en: string,
     *   value_type: string,
     *   numeric_value: ?float,
     *   filter_slug: ?string,
     *   unit: string,
     *   is_filterable: int,
     *   sort_order: int
     * }>
     */
    public static function validateSpecs(array $specs): array
    {
        $seenKeys = [];
        $result = [];

        foreach ($specs as $idx => $item) {
            $group = is_string($item['spec_group'] ?? null) ? trim($item['spec_group']) : 'general';
            if (!in_array($group, self::SPEC_GROUPS, true)) {
                throw ValidationException::invalidField("specs.{$idx}.spec_group", "Unsupported spec group '{$group}'.");
            }

            $key = CanonicalKeyGenerator::slugify(is_string($item['spec_key'] ?? null) ? $item['spec_key'] : '');
            $key = str_replace('-', '_', $key);
            if ($key === '') {
                throw ValidationException::invalidField("specs.{$idx}.spec_key", 'Specification key is required.');
            }
            if (isset($seenKeys[$key])) {
                throw ValidationException::conflict("specs.{$idx}.spec_key", "Duplicate specification key '{$key}' in payload.");
            }
            $seenKeys[$key] = true;

            $labelEn = self::cleanText($item['label_en'] ?? $key, 128);
            $labelFa = self::cleanText($item['label_fa'] ?? $labelEn, 128);
            $valueEn = self::cleanText($item['value_en'] ?? '', 255);
            $valueFa = self::cleanText($item['value_fa'] ?? $valueEn, 255);

            if ($valueEn === '' && $valueFa === '') {
                throw ValidationException::invalidField("specs.{$idx}.value_en", "Specification '{$key}' requires a value.");
            }

            $valueType = is_string($item['value_type'] ?? null) ? trim($item['value_type']) : 'string';
            if (!in_array($valueType, self::SPEC_VALUE_TYPES, true)) {
                throw ValidationException::invalidField("specs.{$idx}.value_type", 'Invalid specification value_type.');
            }

            $numericValue = null;
            if (isset($item['numeric_value']) && $item['numeric_value'] !== '' && is_numeric($item['numeric_value'])) {
                $numericValue = (float) $item['numeric_value'];
            } elseif ($valueType === 'number' && is_numeric($valueEn)) {
                $numericValue = (float) $valueEn;
            }

            $isFilterable = filter_var($item['is_filterable'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $filterSlugRaw = is_string($item['filter_slug'] ?? null) ? $item['filter_slug'] : ($isFilterable === 1 ? $valueEn : '');
            $filterSlug = $filterSlugRaw !== '' ? substr(CanonicalKeyGenerator::slugify($filterSlugRaw), 0, 64) : null;

            $result[] = [
                'spec_group'    => $group,
                'spec_key'      => substr($key, 0, 64),
                'label_fa'      => $labelFa,
                'label_en'      => $labelEn,
                'value_fa'      => $valueFa,
                'value_en'      => $valueEn,
                'value_type'    => $valueType,
                'numeric_value' => $numericValue,
                'filter_slug'   => $filterSlug,
                'unit'          => self::cleanText($item['unit'] ?? '', 32),
                'is_filterable' => $isFilterable,
                'sort_order'    => isset($item['sort_order']) && is_numeric($item['sort_order']) ? max(0, (int) $item['sort_order']) : $idx,
            ];
        }

        return $result;
    }

    /**
     * Validate ordered public gallery/media references (engineering dielines are strictly forbidden here).
     *
     * @param list<array<string, mixed>> $media
     * @return list<array{attachment_id: int, media_role: string, caption_fa: string, caption_en: string, sort_order: int}>
     */
    public static function validateMedia(array $media): array
    {
        $seen = [];
        $result = [];

        foreach ($media as $idx => $item) {
            $attachmentId = isset($item['attachment_id']) && is_numeric($item['attachment_id']) ? (int) $item['attachment_id'] : 0;
            if ($attachmentId <= 0) {
                throw ValidationException::invalidField("media.{$idx}.attachment_id", 'Valid WordPress attachment_id is required.');
            }

            $role = is_string($item['media_role'] ?? null) ? trim($item['media_role']) : 'gallery';
            if (!in_array($role, self::MEDIA_ROLES, true)) {
                throw ValidationException::invalidField("media.{$idx}.media_role", "Unsupported media role '{$role}'.");
            }

            $dedup = $attachmentId . ':' . $role;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;

            $result[] = [
                'attachment_id' => $attachmentId,
                'media_role'    => $role,
                'caption_fa'    => self::cleanText($item['caption_fa'] ?? '', 255),
                'caption_en'    => self::cleanText($item['caption_en'] ?? '', 255),
                'sort_order'    => isset($item['sort_order']) && is_numeric($item['sort_order']) ? max(0, (int) $item['sort_order']) : $idx,
            ];
        }

        return $result;
    }

    /**
     * Validate a compatibility edge.
     *
     * @param array<string, mixed> $input
     * @return array{source_device_id: int, target_type: string, target_id: int, relation_type: string, fit_confidence: string, notes_fa: string, notes_en: string}
     */
    public static function validateCompatibility(int $sourceDeviceId, array $input): array
    {
        $targetType = is_string($input['target_type'] ?? null) ? trim($input['target_type']) : '';
        $targetId = isset($input['target_id']) && is_numeric($input['target_id']) ? (int) $input['target_id'] : 0;
        $relation = is_string($input['relation_type'] ?? null) ? trim($input['relation_type']) : '';
        $confidence = is_string($input['fit_confidence'] ?? null) ? trim($input['fit_confidence']) : 'verified';

        if (!in_array($targetType, self::COMPAT_TARGET_TYPES, true)) {
            throw ValidationException::invalidField('target_type', 'Invalid compatibility target_type.');
        }
        if ($targetId <= 0) {
            throw ValidationException::invalidField('target_id', 'Valid target_id is required.');
        }
        if ($targetType === 'device' && $targetId === $sourceDeviceId) {
            throw ValidationException::invalidField('target_id', 'A device cannot be marked compatible with itself.');
        }
        if (!in_array($relation, self::COMPAT_RELATIONS, true)) {
            throw ValidationException::invalidField('relation_type', 'Invalid compatibility relation_type.');
        }
        if (!in_array($confidence, self::FIT_CONFIDENCES, true)) {
            throw ValidationException::invalidField('fit_confidence', 'Invalid fit_confidence value.');
        }

        return [
            'source_device_id' => $sourceDeviceId,
            'target_type'      => $targetType,
            'target_id'        => $targetId,
            'relation_type'    => $relation,
            'fit_confidence'   => $confidence,
            'notes_fa'         => self::cleanText($input['notes_fa'] ?? '', 255),
            'notes_en'         => self::cleanText($input['notes_en'] ?? '', 255),
        ];
    }

    /**
     * Validate a versioned Compatible Skin Definition (supporting multiple compatible device IDs).
     *
     * @param array<string, mixed> $input
     * @return array{
     *   primary_device_id: int,
     *   compatible_device_ids: list<int>,
     *   template_code: string,
     *   template_version: string,
     *   status: string,
     *   width_mm: float,
     *   height_mm: float,
     *   bleed_mm: float,
     *   min_dpi: int,
     *   zones: list<array{id: string, label_fa: string, label_en: string, required: bool, printable: bool}>,
     *   supported_finishes: list<string>,
     *   preview_attachment_id: ?int,
     *   dieline_attachment_id: ?int,
     *   base_wc_product_id: ?int,
     *   internal_notes: string,
     *   notes_fa: string,
     *   notes_en: string
     * }
     */
    public static function validateSkinDefinition(array $input): array
    {
        $primaryDeviceId = isset($input['primary_device_id']) && is_numeric($input['primary_device_id'])
            ? (int) $input['primary_device_id']
            : 0;
        if ($primaryDeviceId <= 0) {
            throw ValidationException::invalidField('primary_device_id', 'Valid primary_device_id is required.');
        }

        $compatIds = [$primaryDeviceId];
        if (isset($input['compatible_device_ids']) && is_array($input['compatible_device_ids'])) {
            foreach ($input['compatible_device_ids'] as $cid) {
                if (is_numeric($cid) && (int) $cid > 0) {
                    $compatIds[] = (int) $cid;
                }
            }
        }
        $compatIds = array_values(array_unique($compatIds));

        $templateCode = CanonicalKeyGenerator::normalizeTemplateCode(
            is_string($input['template_code'] ?? null) ? $input['template_code'] : ''
        );
        if ($templateCode === '') {
            throw ValidationException::invalidField('template_code', 'Unique template_code is required (e.g., SKIN-PIONEER-DDJFLX4-V1).');
        }

        $version = self::cleanText($input['template_version'] ?? '1.0.0', 24);
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)?$/', $version)) {
            throw ValidationException::invalidField('template_version', 'template_version must follow semantic versioning (e.g., 1.0.0).');
        }

        $status = is_string($input['status'] ?? null) ? trim($input['status']) : 'draft';
        if (!in_array($status, self::SKIN_STATUSES, true)) {
            throw ValidationException::invalidField('status', 'Invalid skin definition status.');
        }

        $width = isset($input['width_mm']) && is_numeric($input['width_mm']) ? (float) $input['width_mm'] : 0.0;
        $height = isset($input['height_mm']) && is_numeric($input['height_mm']) ? (float) $input['height_mm'] : 0.0;
        $bleed = isset($input['bleed_mm']) && is_numeric($input['bleed_mm']) ? (float) $input['bleed_mm'] : 3.0;
        $minDpi = isset($input['min_dpi']) && is_numeric($input['min_dpi']) ? (int) $input['min_dpi'] : 300;

        if ($width <= 0.0 || $width > 3000.0) {
            throw ValidationException::invalidField('width_mm', 'width_mm must be between 0.1 and 3000 mm.');
        }
        if ($height <= 0.0 || $height > 3000.0) {
            throw ValidationException::invalidField('height_mm', 'height_mm must be between 0.1 and 3000 mm.');
        }
        if ($bleed < 0.0 || $bleed > 50.0) {
            throw ValidationException::invalidField('bleed_mm', 'bleed_mm must be between 0 and 50 mm.');
        }
        if ($minDpi < 150 || $minDpi > 1200) {
            throw ValidationException::invalidField('min_dpi', 'min_dpi must be between 150 and 1200.');
        }

        $rawZones = is_array($input['zones'] ?? null) ? $input['zones'] : [];
        $zones = [];
        foreach ($rawZones as $idx => $z) {
            if (!is_array($z)) {
                continue;
            }
            $zid = CanonicalKeyGenerator::slugify(is_string($z['id'] ?? null) ? $z['id'] : '');
            if ($zid === '') {
                throw ValidationException::invalidField("zones.{$idx}.id", 'Each skin zone requires a slug id.');
            }
            $labelEn = self::cleanText($z['label_en'] ?? $zid, 80);
            $labelFa = self::cleanText($z['label_fa'] ?? $labelEn, 80);
            $zones[] = [
                'id'        => $zid,
                'label_fa'  => $labelFa,
                'label_en'  => $labelEn,
                'required'  => filter_var($z['required'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'printable' => filter_var($z['printable'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $rawFinishes = is_array($input['supported_finishes'] ?? null) ? $input['supported_finishes'] : ['matte', 'gloss'];
        $finishes = [];
        foreach ($rawFinishes as $f) {
            if (is_string($f) && in_array($f, self::ALLOWED_FINISHES, true)) {
                $finishes[] = $f;
            }
        }
        $finishes = array_values(array_unique($finishes));
        if ($finishes === []) {
            $finishes = ['matte'];
        }

        $previewId = isset($input['preview_attachment_id']) && is_numeric($input['preview_attachment_id']) && (int) $input['preview_attachment_id'] > 0
            ? (int) $input['preview_attachment_id']
            : null;
        $dielineId = isset($input['dieline_attachment_id']) && is_numeric($input['dieline_attachment_id']) && (int) $input['dieline_attachment_id'] > 0
            ? (int) $input['dieline_attachment_id']
            : null;
        $baseWcId = isset($input['base_wc_product_id']) && is_numeric($input['base_wc_product_id']) && (int) $input['base_wc_product_id'] > 0
            ? (int) $input['base_wc_product_id']
            : null;

        return [
            'primary_device_id'     => $primaryDeviceId,
            'compatible_device_ids' => $compatIds,
            'template_code'         => substr($templateCode, 0, 96),
            'template_version'      => $version,
            'status'                => $status,
            'width_mm'              => round($width, 2),
            'height_mm'             => round($height, 2),
            'bleed_mm'              => round($bleed, 2),
            'min_dpi'               => $minDpi,
            'zones'                 => $zones,
            'supported_finishes'    => $finishes,
            'preview_attachment_id' => $previewId,
            'dieline_attachment_id' => $dielineId,
            'base_wc_product_id'    => $baseWcId,
            'internal_notes'        => self::cleanText($input['internal_notes'] ?? '', 2000),
            'notes_fa'              => self::cleanText($input['notes_fa'] ?? '', 2000),
            'notes_en'              => self::cleanText($input['notes_en'] ?? '', 2000),
        ];
    }

    /**
     * Neutralize spreadsheet formula injection (`=`, `+`, `-`, `@`, tab, CR) before CSV export.
     */
    public static function escapeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    public static function cleanText(mixed $raw, int $maxLength): string
    {
        if (!is_string($raw)) {
            return '';
        }

        $clean = trim(strip_tags($raw));

        return mb_substr($clean, 0, $maxLength);
    }
}
