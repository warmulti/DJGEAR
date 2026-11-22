<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Repositories;

use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Domain\SkinValidator;
use DJGear\CustomSkins\Lifecycle\Migrations\CreateCustomSkinTables;
use wpdb;

/**
 * Repository for commercial ordering plans (`{prefix}djgear_skin_plans`).
 * Standard plans do NOT allow artwork uploads; Custom Design plans enable authenticated upload + proof review.
 */
final class SkinPlanRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
    ) {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . CreateCustomSkinTables::TABLE_PLANS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(bool $onlyActive = false): array
    {
        $table = $this->table();
        $sql = $onlyActive
            ? "SELECT * FROM `{$table}` WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
            : "SELECT * FROM `{$table}` ORDER BY sort_order ASC, id ASC";

        $rows = $this->wpdb->get_results($sql, 'ARRAY_A');
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
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $table = $this->table();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE slug = %s", $slug),
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
        $clean = SkinValidator::validatePlan($input);
        $this->assertUniqueSlug($clean['slug'], null);

        $inserted = $this->wpdb->insert($this->table(), [
            'slug'                    => $clean['slug'],
            'name_fa'                 => $clean['name_fa'],
            'name_en'                 => $clean['name_en'],
            'description_fa'          => $clean['description_fa'],
            'description_en'          => $clean['description_en'],
            'plan_type'               => $clean['plan_type'],
            'allows_artwork_upload'   => $clean['allows_artwork_upload'] ? 1 : 0,
            'requires_artwork'        => $clean['requires_artwork'] ? 1 : 0,
            'requires_proof_approval' => $clean['requires_proof_approval'] ? 1 : 0,
            'max_revisions'           => $clean['max_revisions'],
            'price_surcharge'         => $clean['price_surcharge'],
            'estimated_lead_days'     => $clean['estimated_lead_days'],
            'features_fa_json'        => wp_json_encode($clean['features_fa'], JSON_UNESCAPED_UNICODE) ?: '[]',
            'features_en_json'        => wp_json_encode($clean['features_en'], JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_active'               => $clean['is_active'] ? 1 : 0,
            'sort_order'              => $clean['sort_order'],
            'updated_at'              => gmdate('Y-m-d H:i:s'),
        ]);

        if ($inserted === false) {
            throw SkinValidationException::conflict('slug', 'Failed to create ordering plan: ' . $this->wpdb->last_error);
        }

        $created = $this->findById((int) $this->wpdb->insert_id);
        if ($created === null) {
            throw new SkinValidationException('Created plan could not be reloaded.', 500);
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
            throw SkinValidationException::notFound('skin_plan', 'Ordering plan not found.');
        }

        $merged = array_merge($existing, $input);
        $clean = SkinValidator::validatePlan($merged);
        $this->assertUniqueSlug($clean['slug'], $id);

        $updated = $this->wpdb->update(
            $this->table(),
            [
                'slug'                    => $clean['slug'],
                'name_fa'                 => $clean['name_fa'],
                'name_en'                 => $clean['name_en'],
                'description_fa'          => $clean['description_fa'],
                'description_en'          => $clean['description_en'],
                'plan_type'               => $clean['plan_type'],
                'allows_artwork_upload'   => $clean['allows_artwork_upload'] ? 1 : 0,
                'requires_artwork'        => $clean['requires_artwork'] ? 1 : 0,
                'requires_proof_approval' => $clean['requires_proof_approval'] ? 1 : 0,
                'max_revisions'           => $clean['max_revisions'],
                'price_surcharge'         => $clean['price_surcharge'],
                'estimated_lead_days'     => $clean['estimated_lead_days'],
                'features_fa_json'        => wp_json_encode($clean['features_fa'], JSON_UNESCAPED_UNICODE) ?: '[]',
                'features_en_json'        => wp_json_encode($clean['features_en'], JSON_UNESCAPED_UNICODE) ?: '[]',
                'is_active'               => $clean['is_active'] ? 1 : 0,
                'sort_order'              => $clean['sort_order'],
                'updated_at'              => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id]
        );

        if ($updated === false) {
            throw SkinValidationException::conflict('slug', 'Failed to update ordering plan: ' . $this->wpdb->last_error);
        }

        $reloaded = $this->findById($id);
        if ($reloaded === null) {
            throw SkinValidationException::notFound('skin_plan');
        }

        return $reloaded;
    }

    public function delete(int $id): bool
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw SkinValidationException::notFound('skin_plan', 'Ordering plan not found.');
        }

        $deleted = $this->wpdb->delete($this->table(), ['id' => $id], ['%d']);
        return $deleted !== false && $deleted > 0;
    }

    /**
     * Seed default Standard + Custom Design ordering plans idempotently ONLY when table is empty.
     */
    public function seedDefaultsIfEmpty(): int
    {
        $table = $this->table();
        $count = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return 0;
        }

        $defaults = [
            [
                'slug'                    => 'standard',
                'name_fa'                 => 'پلن استاندارد (آماده تولید)',
                'name_en'                 => 'Standard Plan (Ready-Made Skin)',
                'description_fa'          => 'تولید مستقیم اسکین با رنگ و مدل انتخابی بدون نیاز به بارگذاری طرح اختصاصی.',
                'description_en'          => 'Direct production using your selected skin model and color without artwork upload.',
                'plan_type'               => 'standard',
                'allows_artwork_upload'   => false,
                'requires_artwork'        => false,
                'requires_proof_approval' => false,
                'max_revisions'           => 0,
                'price_surcharge'         => 0.0,
                'estimated_lead_days'     => 2,
                'features_fa'             => [
                    'بدون نیاز به ارسال طرح یا لوگو',
                    'ورود مستقیم به صف تولید پس از پرداخت',
                    'برش دقیق و لمینت محافظ حرفه‌ای',
                ],
                'features_en'             => [
                    'No artwork upload required',
                    'Enters production queue immediately after payment',
                    'Precision finish with protective lamination',
                ],
                'is_active'               => true,
                'sort_order'              => 10,
            ],
            [
                'slug'                    => 'custom-design',
                'name_fa'                 => 'پلن طراحی اختصاصی (چاپ طرح و لوگو)',
                'name_en'                 => 'Custom Design Plan (Artwork & Logo)',
                'description_fa'          => 'بارگذاری طرح، لوگو یا گرافیک اختصاصی به همراه بررسی کیفیت چاپ و ارسال پیش‌نمایش نهایی برای تأیید شما.',
                'description_en'          => 'Upload your own artwork or logo with automatic DPI verification and designer proof approval before printing.',
                'plan_type'               => 'custom_design',
                'allows_artwork_upload'   => true,
                'requires_artwork'        => true,
                'requires_proof_approval' => true,
                'max_revisions'           => 3,
                'price_surcharge'         => 250000.0,
                'estimated_lead_days'     => 4,
                'features_fa'             => [
                    'امکان بارگذاری طرح و لوگوی اختصاصی با بررسی خودکار DPI',
                    'ارسال پیش‌نمایش نهایی طراح جهت تأیید قبل از چاپ',
                    'تا ۳ مرتبه اصلاح رایگان پیش‌نمایش',
                ],
                'features_en'             => [
                    'Upload custom artwork or logo with automatic DPI check',
                    'Designer proof preview for your approval before printing',
                    'Up to 3 complimentary proof revisions',
                ],
                'is_active'               => true,
                'sort_order'              => 20,
            ],
        ];

        $inserted = 0;
        foreach ($defaults as $plan) {
            $this->create($plan);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $featuresFa = json_decode((string) ($row['features_fa_json'] ?? '[]'), true);
        $featuresEn = json_decode((string) ($row['features_en_json'] ?? '[]'), true);

        return [
            'id'                      => (int) ($row['id'] ?? 0),
            'slug'                    => (string) ($row['slug'] ?? ''),
            'name_fa'                 => (string) ($row['name_fa'] ?? ''),
            'name_en'                 => (string) ($row['name_en'] ?? ''),
            'description_fa'          => (string) ($row['description_fa'] ?? ''),
            'description_en'          => (string) ($row['description_en'] ?? ''),
            'plan_type'               => (string) ($row['plan_type'] ?? 'standard'),
            'allows_artwork_upload'   => ((int) ($row['allows_artwork_upload'] ?? 0)) === 1,
            'requires_artwork'        => ((int) ($row['requires_artwork'] ?? 0)) === 1,
            'requires_proof_approval' => ((int) ($row['requires_proof_approval'] ?? 0)) === 1,
            'max_revisions'           => (int) ($row['max_revisions'] ?? 0),
            'price_surcharge'         => (float) ($row['price_surcharge'] ?? 0.0),
            'estimated_lead_days'     => (int) ($row['estimated_lead_days'] ?? 3),
            'features_fa'             => is_array($featuresFa) ? array_values($featuresFa) : [],
            'features_en'             => is_array($featuresEn) ? array_values($featuresEn) : [],
            'is_active'               => ((int) ($row['is_active'] ?? 0)) === 1,
            'sort_order'              => (int) ($row['sort_order'] ?? 0),
            'updated_at'              => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function assertUniqueSlug(string $slug, ?int $ignoreId): void
    {
        $table = $this->table();
        if ($ignoreId !== null) {
            $existingId = $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s AND id != %d", $slug, $ignoreId)
            );
        } else {
            $existingId = $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $slug)
            );
        }

        if ($existingId !== null) {
            throw SkinValidationException::conflict('slug', sprintf('Ordering plan slug "%s" already exists.', $slug));
        }
    }
}
