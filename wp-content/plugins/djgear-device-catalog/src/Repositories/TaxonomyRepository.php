<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Repositories;

use DJGear\DeviceCatalog\Domain\CanonicalKeyGenerator;
use DJGear\DeviceCatalog\Domain\CatalogValidator;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Taxonomy\CatalogPostTypes;
use WP_Error;
use WP_Term;

/**
 * Manages Manufacturers (`djgear_brand`) and Device Categories (`djgear_device_category`) via native WordPress Taxonomy APIs.
 */
final class TaxonomyRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listBrands(): array
    {
        $terms = get_terms([
            'taxonomy'   => CatalogPostTypes::TAXONOMY_BRAND,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            $items[] = $this->formatBrand($term);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createBrand(array $input): array
    {
        $nameEn = CatalogValidator::cleanText($input['name_en'] ?? '', 120);
        if ($nameEn === '') {
            throw ValidationException::invalidField('name_en', 'English brand name is required.');
        }
        $nameFa = CatalogValidator::cleanText($input['name_fa'] ?? $nameEn, 120);
        if ($nameFa === '') {
            $nameFa = $nameEn;
        }
        $slug = CanonicalKeyGenerator::slugify(is_string($input['slug'] ?? null) && $input['slug'] !== '' ? $input['slug'] : $nameEn);
        if ($slug === '') {
            throw ValidationException::invalidField('slug', 'Valid brand slug is required.');
        }

        if (get_term_by('slug', $slug, CatalogPostTypes::TAXONOMY_BRAND) instanceof WP_Term) {
            throw ValidationException::conflict('slug', "Brand slug '{$slug}' already exists.");
        }

        $inserted = wp_insert_term($nameEn, CatalogPostTypes::TAXONOMY_BRAND, ['slug' => $slug]);
        if ($inserted instanceof WP_Error) {
            throw new ValidationException($inserted->get_error_message(), 422);
        }

        $termId = (int) $inserted['term_id'];
        $this->saveBrandMeta($termId, $nameFa, $nameEn, $input);

        $term = get_term($termId, CatalogPostTypes::TAXONOMY_BRAND);
        if (!$term instanceof WP_Term) {
            throw ValidationException::notFound('Brand', $termId);
        }

        return $this->formatBrand($term);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateBrand(int $termId, array $input): array
    {
        $existing = get_term($termId, CatalogPostTypes::TAXONOMY_BRAND);
        if (!$existing instanceof WP_Term) {
            throw ValidationException::notFound('Brand', $termId);
        }

        $nameEn = CatalogValidator::cleanText($input['name_en'] ?? $existing->name, 120);
        $nameFa = CatalogValidator::cleanText($input['name_fa'] ?? get_term_meta($termId, 'name_fa', true), 120);
        if ($nameEn === '') {
            $nameEn = $existing->name;
        }
        if ($nameFa === '') {
            $nameFa = $nameEn;
        }

        $args = ['name' => $nameEn];
        if (isset($input['slug']) && is_string($input['slug']) && trim($input['slug']) !== '') {
            $args['slug'] = CanonicalKeyGenerator::slugify($input['slug']);
        }

        $updated = wp_update_term($termId, CatalogPostTypes::TAXONOMY_BRAND, $args);
        if ($updated instanceof WP_Error) {
            throw new ValidationException($updated->get_error_message(), 422);
        }

        $this->saveBrandMeta($termId, $nameFa, $nameEn, $input);

        $refreshed = get_term($termId, CatalogPostTypes::TAXONOMY_BRAND);
        if (!$refreshed instanceof WP_Term) {
            throw ValidationException::notFound('Brand', $termId);
        }

        return $this->formatBrand($refreshed);
    }

    public function deleteBrand(int $termId, int $attachedDeviceCount): void
    {
        $existing = get_term($termId, CatalogPostTypes::TAXONOMY_BRAND);
        if (!$existing instanceof WP_Term) {
            throw ValidationException::notFound('Brand', $termId);
        }
        if ($attachedDeviceCount > 0) {
            throw ValidationException::conflict(
                'brand_term_id',
                "Cannot delete brand while {$attachedDeviceCount} device(s) reference it."
            );
        }

        wp_delete_term($termId, CatalogPostTypes::TAXONOMY_BRAND);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCategories(): array
    {
        $terms = get_terms([
            'taxonomy'   => CatalogPostTypes::TAXONOMY_CATEGORY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            $items[] = $this->formatCategory($term);
        }

        usort($items, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCategory(array $input): array
    {
        $nameEn = CatalogValidator::cleanText($input['name_en'] ?? '', 120);
        if ($nameEn === '') {
            throw ValidationException::invalidField('name_en', 'English category name is required.');
        }
        $nameFa = CatalogValidator::cleanText($input['name_fa'] ?? $nameEn, 120);
        if ($nameFa === '') {
            $nameFa = $nameEn;
        }
        $slug = CanonicalKeyGenerator::slugify(is_string($input['slug'] ?? null) && $input['slug'] !== '' ? $input['slug'] : $nameEn);
        if ($slug === '') {
            throw ValidationException::invalidField('slug', 'Valid category slug is required.');
        }

        if (get_term_by('slug', $slug, CatalogPostTypes::TAXONOMY_CATEGORY) instanceof WP_Term) {
            throw ValidationException::conflict('slug', "Category slug '{$slug}' already exists.");
        }

        $parentId = isset($input['parent_id']) && is_numeric($input['parent_id']) ? max(0, (int) $input['parent_id']) : 0;

        $inserted = wp_insert_term($nameEn, CatalogPostTypes::TAXONOMY_CATEGORY, [
            'slug'   => $slug,
            'parent' => $parentId,
        ]);
        if ($inserted instanceof WP_Error) {
            throw new ValidationException($inserted->get_error_message(), 422);
        }

        $termId = (int) $inserted['term_id'];
        $this->saveCategoryMeta($termId, $nameFa, $nameEn, $input);

        $term = get_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY);
        if (!$term instanceof WP_Term) {
            throw ValidationException::notFound('Category', $termId);
        }

        return $this->formatCategory($term);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCategory(int $termId, array $input): array
    {
        $existing = get_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY);
        if (!$existing instanceof WP_Term) {
            throw ValidationException::notFound('Category', $termId);
        }

        $nameEn = CatalogValidator::cleanText($input['name_en'] ?? $existing->name, 120);
        $nameFa = CatalogValidator::cleanText($input['name_fa'] ?? get_term_meta($termId, 'name_fa', true), 120);
        if ($nameEn === '') {
            $nameEn = $existing->name;
        }
        if ($nameFa === '') {
            $nameFa = $nameEn;
        }

        $args = ['name' => $nameEn];
        if (isset($input['slug']) && is_string($input['slug']) && trim($input['slug']) !== '') {
            $args['slug'] = CanonicalKeyGenerator::slugify($input['slug']);
        }
        if (isset($input['parent_id']) && is_numeric($input['parent_id'])) {
            $parentId = max(0, (int) $input['parent_id']);
            if ($parentId !== $termId) {
                $args['parent'] = $parentId;
            }
        }

        $updated = wp_update_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY, $args);
        if ($updated instanceof WP_Error) {
            throw new ValidationException($updated->get_error_message(), 422);
        }

        $this->saveCategoryMeta($termId, $nameFa, $nameEn, $input);

        $refreshed = get_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY);
        if (!$refreshed instanceof WP_Term) {
            throw ValidationException::notFound('Category', $termId);
        }

        return $this->formatCategory($refreshed);
    }

    public function deleteCategory(int $termId, int $attachedDeviceCount): void
    {
        $existing = get_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY);
        if (!$existing instanceof WP_Term) {
            throw ValidationException::notFound('Category', $termId);
        }
        if ($attachedDeviceCount > 0) {
            throw ValidationException::conflict(
                'category_term_id',
                "Cannot delete category while {$attachedDeviceCount} device(s) reference it."
            );
        }

        wp_delete_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBrandById(int $termId): ?array
    {
        $term = get_term($termId, CatalogPostTypes::TAXONOMY_BRAND);

        return $term instanceof WP_Term ? $this->formatBrand($term) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCategoryById(int $termId): ?array
    {
        $term = get_term($termId, CatalogPostTypes::TAXONOMY_CATEGORY);

        return $term instanceof WP_Term ? $this->formatCategory($term) : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function saveBrandMeta(int $termId, string $nameFa, string $nameEn, array $input): void
    {
        update_term_meta($termId, 'name_fa', $nameFa);
        update_term_meta($termId, 'name_en', $nameEn);

        if (array_key_exists('logo_attachment_id', $input)) {
            $logoId = is_numeric($input['logo_attachment_id']) ? max(0, (int) $input['logo_attachment_id']) : 0;
            update_term_meta($termId, 'logo_attachment_id', $logoId);
        }
        if (array_key_exists('country_code', $input)) {
            $cc = strtoupper(CatalogValidator::cleanText($input['country_code'], 4));
            update_term_meta($termId, 'country_code', $cc);
        }
        if (array_key_exists('website_url', $input)) {
            $url = is_string($input['website_url']) ? esc_url_raw(trim($input['website_url'])) : '';
            update_term_meta($termId, 'website_url', $url);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function saveCategoryMeta(int $termId, string $nameFa, string $nameEn, array $input): void
    {
        update_term_meta($termId, 'name_fa', $nameFa);
        update_term_meta($termId, 'name_en', $nameEn);

        if (array_key_exists('sort_order', $input) && is_numeric($input['sort_order'])) {
            update_term_meta($termId, 'sort_order', max(0, (int) $input['sort_order']));
        }
        if (array_key_exists('icon_key', $input)) {
            update_term_meta($termId, 'icon_key', CatalogValidator::cleanText($input['icon_key'], 40));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBrand(WP_Term $term): array
    {
        $logoId = (int) get_term_meta($term->term_id, 'logo_attachment_id', true);
        $logoUrl = $logoId > 0 ? (wp_get_attachment_image_url($logoId, 'medium') ?: null) : null;
        $nameFa = (string) get_term_meta($term->term_id, 'name_fa', true);
        $nameEn = (string) get_term_meta($term->term_id, 'name_en', true);

        return [
            'id'                 => (int) $term->term_id,
            'slug'               => (string) $term->slug,
            'name_fa'            => $nameFa !== '' ? $nameFa : (string) $term->name,
            'name_en'            => $nameEn !== '' ? $nameEn : (string) $term->name,
            'logo_attachment_id' => $logoId > 0 ? $logoId : null,
            'logo_url'           => $logoUrl,
            'country_code'       => (string) get_term_meta($term->term_id, 'country_code', true),
            'website_url'        => (string) get_term_meta($term->term_id, 'website_url', true),
            'device_count'       => (int) $term->count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCategory(WP_Term $term): array
    {
        $nameFa = (string) get_term_meta($term->term_id, 'name_fa', true);
        $nameEn = (string) get_term_meta($term->term_id, 'name_en', true);

        return [
            'id'           => (int) $term->term_id,
            'slug'         => (string) $term->slug,
            'parent_id'    => (int) $term->parent,
            'name_fa'      => $nameFa !== '' ? $nameFa : (string) $term->name,
            'name_en'      => $nameEn !== '' ? $nameEn : (string) $term->name,
            'icon_key'     => (string) get_term_meta($term->term_id, 'icon_key', true),
            'sort_order'   => (int) get_term_meta($term->term_id, 'sort_order', true),
            'device_count' => (int) $term->count,
        ];
    }
}
