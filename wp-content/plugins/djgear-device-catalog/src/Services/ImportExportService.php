<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Services;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\DeviceCatalog\Cache\CatalogCacheService;
use DJGear\DeviceCatalog\Domain\CanonicalKeyGenerator;
use DJGear\DeviceCatalog\Domain\CatalogValidator;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Repositories\CatalogDetailsRepository;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\SkinDefinitionRepository;
use DJGear\DeviceCatalog\Repositories\TaxonomyRepository;
use WP_Term;
use DJGear\DeviceCatalog\Taxonomy\CatalogPostTypes;

/**
 * Versioned JSON & CSV dry-run validation, idempotent batch import, and safe export service.
 */
final class ImportExportService
{
    public const SCHEMA_VERSION = '1.0';
    public const MAX_IMPORT_ITEMS = 500;

    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly DeviceRepository $devices,
        private readonly CatalogDetailsRepository $details,
        private readonly SkinDefinitionRepository $skins,
        private readonly CatalogCacheService $cache,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {
    }

    /**
     * Dry-run validation preview for JSON device arrays without writing to the database.
     *
     * @param array<string, mixed> $payload
     * @return array{valid: bool, schema_version: string, summary: array{total: int, create: int, update: int, skip: int, errors: int}, rows: list<array<string, mixed>>}
     */
    public function validateImport(array $payload, string $conflictPolicy = 'skip'): array
    {
        if (!in_array($conflictPolicy, ['skip', 'update', 'fail'], true)) {
            throw ValidationException::invalidField('conflict_policy', 'Allowed policies: skip, update, fail.');
        }

        $rawDevices = is_array($payload['devices'] ?? null) ? $payload['devices'] : [];
        if (count($rawDevices) > self::MAX_IMPORT_ITEMS) {
            throw ValidationException::invalidField('devices', 'Maximum ' . self::MAX_IMPORT_ITEMS . ' devices per import batch.');
        }

        $summary = ['total' => count($rawDevices), 'create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];
        $rows = [];
        $seenKeys = [];

        foreach ($rawDevices as $idx => $item) {
            if (!is_array($item)) {
                $summary['errors']++;
                $rows[] = ['index' => $idx, 'action' => 'error', 'error' => 'Row must be an object.'];
                continue;
            }

            try {
                $brandSlug = CanonicalKeyGenerator::slugify(is_string($item['brand_slug'] ?? null) ? $item['brand_slug'] : '');
                $catSlug = CanonicalKeyGenerator::slugify(is_string($item['category_slug'] ?? null) ? $item['category_slug'] : '');
                $modelCode = CatalogValidator::cleanText($item['model_code'] ?? '', 120);
                $nameEn = CatalogValidator::cleanText($item['name_en'] ?? '', 255);

                if ($brandSlug === '' || $catSlug === '' || $modelCode === '' || $nameEn === '') {
                    throw new ValidationException('brand_slug, category_slug, model_code, and name_en are required.');
                }

                $canonicalKey = CanonicalKeyGenerator::deviceKey($brandSlug, $modelCode);
                if (isset($seenKeys[$canonicalKey])) {
                    throw ValidationException::conflict('canonical_key', "Duplicate canonical_key '{$canonicalKey}' inside import file.");
                }
                $seenKeys[$canonicalKey] = true;

                $existing = $this->devices->findByCanonicalKey($canonicalKey);
                if ($existing !== null) {
                    if ($conflictPolicy === 'fail') {
                        throw ValidationException::conflict('canonical_key', "Device '{$canonicalKey}' already exists.");
                    }
                    $action = $conflictPolicy === 'update' ? 'update' : 'skip';
                } else {
                    $action = 'create';
                }

                $summary[$action]++;
                $rows[] = [
                    'index'         => $idx,
                    'canonical_key' => $canonicalKey,
                    'model_code'    => $modelCode,
                    'action'        => $action,
                    'existing_id'   => $existing !== null ? $existing['id'] : null,
                ];
            } catch (\Throwable $e) {
                $summary['errors']++;
                $rows[] = [
                    'index'  => $idx,
                    'action' => 'error',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return [
            'valid'          => $summary['errors'] === 0,
            'schema_version' => self::SCHEMA_VERSION,
            'summary'        => $summary,
            'rows'           => $rows,
        ];
    }

    /**
     * Execute validated idempotent batch import.
     *
     * @param array<string, mixed> $payload
     * @return array{created: int, updated: int, skipped: int, errors: list<array{index: int, message: string}>}
     */
    public function executeImport(array $payload, string $conflictPolicy = 'skip'): array
    {
        $dryRun = $this->validateImport($payload, $conflictPolicy);
        if (!$dryRun['valid']) {
            throw new ValidationException('Import payload failed validation; resolve errors before executing.', 422);
        }

        // Auto-create brands/categories listed in payload if missing
        foreach (is_array($payload['brands'] ?? null) ? $payload['brands'] : [] as $brandIn) {
            if (!is_array($brandIn)) {
                continue;
            }
            $slug = CanonicalKeyGenerator::slugify(is_string($brandIn['slug'] ?? null) ? $brandIn['slug'] : (string) ($brandIn['name_en'] ?? ''));
            if ($slug !== '' && !(get_term_by('slug', $slug, CatalogPostTypes::TAXONOMY_BRAND) instanceof WP_Term)) {
                $this->taxonomies->createBrand($brandIn);
            }
        }

        foreach (is_array($payload['categories'] ?? null) ? $payload['categories'] : [] as $catIn) {
            if (!is_array($catIn)) {
                continue;
            }
            $slug = CanonicalKeyGenerator::slugify(is_string($catIn['slug'] ?? null) ? $catIn['slug'] : (string) ($catIn['name_en'] ?? ''));
            if ($slug !== '' && !(get_term_by('slug', $slug, CatalogPostTypes::TAXONOMY_CATEGORY) instanceof WP_Term)) {
                $this->taxonomies->createCategory($catIn);
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $rawDevices = is_array($payload['devices'] ?? null) ? $payload['devices'] : [];
        foreach ($rawDevices as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $brandSlug = CanonicalKeyGenerator::slugify((string) ($item['brand_slug'] ?? ''));
                $catSlug = CanonicalKeyGenerator::slugify((string) ($item['category_slug'] ?? ''));

                $brandTerm = get_term_by('slug', $brandSlug, CatalogPostTypes::TAXONOMY_BRAND);
                if (!$brandTerm instanceof WP_Term) {
                    $createdBrand = $this->taxonomies->createBrand(['slug' => $brandSlug, 'name_en' => $brandSlug, 'name_fa' => $brandSlug]);
                    $brandId = (int) $createdBrand['id'];
                } else {
                    $brandId = (int) $brandTerm->term_id;
                }

                $catTerm = get_term_by('slug', $catSlug, CatalogPostTypes::TAXONOMY_CATEGORY);
                if (!$catTerm instanceof WP_Term) {
                    $createdCat = $this->taxonomies->createCategory(['slug' => $catSlug, 'name_en' => $catSlug, 'name_fa' => $catSlug]);
                    $catId = (int) $createdCat['id'];
                } else {
                    $catId = (int) $catTerm->term_id;
                }

                $canonicalKey = CanonicalKeyGenerator::deviceKey($brandSlug, (string) $item['model_code']);
                $existing = $this->devices->findByCanonicalKey($canonicalKey);

                $deviceData = array_merge($item, [
                    'brand_term_id'    => $brandId,
                    'category_term_id' => $catId,
                ]);

                if ($existing !== null) {
                    if ($conflictPolicy === 'skip') {
                        $skipped++;
                        continue;
                    }
                    $saved = $this->devices->update((int) $existing['id'], $deviceData);
                    $updated++;
                } else {
                    $saved = $this->devices->create($deviceData);
                    $created++;
                }

                if (isset($item['specs']) && is_array($item['specs'])) {
                    $this->details->replaceSpecs((int) $saved['id'], $item['specs']);
                }
            } catch (\Throwable $e) {
                $errors[] = ['index' => $idx, 'message' => $e->getMessage()];
            }
        }

        $this->cache->invalidatePublicCache();

        $this->auditLogger?->record(new AuditEvent(
            action: 'catalog.imported',
            resourceType: 'catalog',
            resourceId: 'batch',
            context: [
                'conflict_policy' => $conflictPolicy,
                'created'         => $created,
                'updated'         => $updated,
                'skipped'         => $skipped,
                'error_count'     => count($errors),
            ],
        ));

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    /**
     * Export full relational catalog snapshot in canonical versioned JSON.
     *
     * @return array<string, mixed>
     */
    public function exportJson(): array
    {
        $brands = $this->taxonomies->listBrands();
        $categories = $this->taxonomies->listCategories();
        $allDevices = $this->devices->search(['page' => 1, 'per_page' => 50])['items'];

        $exportedDevices = [];
        foreach ($allDevices as $device) {
            $id = (int) $device['id'];
            $exportedDevices[] = [
                'canonical_key'         => $device['canonical_key'],
                'brand_slug'            => $device['brand']['slug'] ?? '',
                'category_slug'         => $device['category']['slug'] ?? '',
                'model_code'            => $device['model_code'],
                'mpn'                   => $device['mpn'],
                'name_fa'               => $device['name_fa'],
                'name_en'               => $device['name_en'],
                'summary_fa'            => $device['summary_fa'],
                'summary_en'            => $device['summary_en'],
                'release_year'          => $device['release_year'],
                'lifecycle_status'      => $device['lifecycle_status'],
                'primary_wc_product_id' => $device['primary_wc_product_id'],
                'specs'                 => $this->details->listSpecs($id),
                'media'                 => $this->details->listMedia($id),
                'compatibilities'       => $this->details->listCompatibility($id, true),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at'    => gmdate('c'),
            'brands'         => $brands,
            'categories'     => $categories,
            'devices'        => $exportedDevices,
            'skins'          => $this->skins->listAll(),
        ];
    }

    /**
     * Export formula-injection-safe CSV string of catalog devices.
     */
    public function exportCsv(): string
    {
        $allDevices = $this->devices->search(['page' => 1, 'per_page' => 50])['items'];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'canonical_key',
            'brand_slug',
            'category_slug',
            'model_code',
            'mpn',
            'name_en',
            'name_fa',
            'release_year',
            'lifecycle_status',
            'skin_ready',
        ]);

        foreach ($allDevices as $device) {
            fputcsv($handle, [
                CatalogValidator::escapeCsvCell((string) $device['canonical_key']),
                CatalogValidator::escapeCsvCell((string) ($device['brand']['slug'] ?? '')),
                CatalogValidator::escapeCsvCell((string) ($device['category']['slug'] ?? '')),
                CatalogValidator::escapeCsvCell((string) $device['model_code']),
                CatalogValidator::escapeCsvCell((string) ($device['mpn'] ?? '')),
                CatalogValidator::escapeCsvCell((string) $device['name_en']),
                CatalogValidator::escapeCsvCell((string) $device['name_fa']),
                (string) ($device['release_year'] ?? ''),
                CatalogValidator::escapeCsvCell((string) $device['lifecycle_status']),
                $device['skin_ready'] ? '1' : '0',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }
}
