<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Tests\Unit;

use DJGear\Core\Container\Container;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\DeviceCatalog\DeviceCatalogModule;
use DJGear\DeviceCatalog\Domain\CanonicalKeyGenerator;
use DJGear\DeviceCatalog\Domain\CatalogValidator;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Security\CatalogCapabilities;
use DJGear\DeviceCatalog\Security\PublicCatalogSerializer;
use PHPUnit\Framework\TestCase;

final class CatalogDomainTest extends TestCase
{
    public function testCanonicalKeyNormalizationPreventsNearDuplicates(): void
    {
        $k1 = CanonicalKeyGenerator::deviceKey('Pioneer DJ', 'DDJ-FLX4');
        $k2 = CanonicalKeyGenerator::deviceKey('pioneer-dj', 'ddj flx 4');

        self::assertSame('pioneer-dj::ddjflx4', $k1);
        self::assertSame($k1, $k2);
        self::assertSame('SKIN-PIONEER-DDJFLX4-V1', CanonicalKeyGenerator::normalizeTemplateCode(' skin pioneer ddjflx4 v1 '));
    }

    public function testValidatorEnforcesDeviceSpecsCompatibilityAndSkinRules(): void
    {
        $device = CatalogValidator::validateDevice([
            'brand_term_id'    => 10,
            'category_term_id' => 20,
            'model_code'       => 'CDJ-3000',
            'mpn'              => 'cdj3000-jp',
            'name_en'          => 'Pioneer DJ CDJ-3000',
            'name_fa'          => 'پایونیر دی‌جی CDJ-3000',
            'release_year'     => 2020,
            'lifecycle_status' => 'active',
        ]);
        self::assertSame('CDJ3000-JP', $device['mpn']);

        $specs = CatalogValidator::validateSpecs([
            [
                'spec_group'    => 'controls',
                'spec_key'      => 'Jog Size',
                'label_en'      => 'Jog Wheel Size',
                'label_fa'      => 'اندازه جاگ ویل',
                'value_en'      => '8.1',
                'value_fa'      => '۸.۱',
                'value_type'    => 'number',
                'unit'          => 'inch',
                'is_filterable' => true,
            ],
        ]);
        self::assertSame('jog_size', $specs[0]['spec_key']);
        self::assertSame(8.1, $specs[0]['numeric_value']);

        $skin = CatalogValidator::validateSkinDefinition([
            'primary_device_id'     => 100,
            'compatible_device_ids' => [100, 101, 102],
            'template_code'         => 'SKIN-FLX4-V1',
            'template_version'      => '1.2.0',
            'status'                => 'active',
            'width_mm'              => 482.0,
            'height_mm'             => 272.8,
            'bleed_mm'              => 3.0,
            'min_dpi'               => 300,
            'zones'                 => [
                ['id' => 'top-plate', 'label_en' => 'Top Plate', 'label_fa' => 'صفحه رویی', 'required' => true, 'printable' => true],
            ],
            'supported_finishes'    => ['matte', 'carbon', 'invalid_finish'],
            'dieline_attachment_id' => 555,
            'internal_notes'        => 'Laser cut offset 0.2mm',
        ]);
        self::assertSame([100, 101, 102], $skin['compatible_device_ids']);
        self::assertSame(['matte', 'carbon'], $skin['supported_finishes']);
    }

    public function testSelfCompatibilityIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        CatalogValidator::validateCompatibility(42, [
            'target_type'   => 'device',
            'target_id'     => 42,
            'relation_type' => 'companion',
        ]);
    }

    public function testPublicSerializerStrictlyStripsPrivateEngineeringFieldsAndDraftSkins(): void
    {
        $detail = PublicCatalogSerializer::deviceDetail(
            [
                'id'               => 10,
                'slug'             => 'pioneer-dj-ddj-flx4',
                'model_code'       => 'DDJ-FLX4',
                'canonical_key'    => 'pioneer-dj::ddjflx4',
                'name_en'          => 'DDJ-FLX4',
                'name_fa'          => 'DDJ-FLX4',
                'edit_url'         => 'http://localhost:8080/wp-admin/post.php?post=10',
                'internal_notes'   => 'SECRET INTERNAL',
                'lifecycle_status' => 'active',
            ],
            [],
            [],
            [
                [
                    'target_type'   => 'wc_product',
                    'target_id'     => 99,
                    'relation_type' => 'decksaver',
                    'wc_product'    => [
                        'id'       => 99,
                        'name'     => 'Decksaver FLX4',
                        'price'    => '1200000',
                        'edit_url' => 'http://localhost:8080/wp-admin/post.php?post=99',
                    ],
                ],
            ],
            [
                [
                    'id'                    => 1,
                    'template_code'         => 'SKIN-ACTIVE',
                    'template_version'      => '1.0.0',
                    'status'                => 'active',
                    'width_mm'              => 482.0,
                    'height_mm'             => 272.8,
                    'dieline_attachment_id' => 777,
                    'dieline_url'           => 'http://localhost:8080/wp-content/uploads/dieline.svg',
                    'internal_notes'        => 'INTERNAL CUTTER CONFIG',
                    'preview_image_url'     => 'http://localhost:8080/wp-content/uploads/preview.png',
                ],
                [
                    'id'            => 2,
                    'template_code' => 'SKIN-DRAFT-MUST-NOT-LEAK',
                    'status'        => 'draft',
                ],
            ]
        );

        self::assertArrayNotHasKey('edit_url', $detail);
        self::assertArrayNotHasKey('internal_notes', $detail);
        self::assertArrayNotHasKey('edit_url', $detail['compatibilities'][0]['wc_product']);
        self::assertCount(1, $detail['skins']);
        self::assertSame('SKIN-ACTIVE', $detail['skins'][0]['template_code']);
        self::assertArrayNotHasKey('dieline_attachment_id', $detail['skins'][0]);
        self::assertArrayNotHasKey('dieline_url', $detail['skins'][0]);
        self::assertArrayNotHasKey('internal_notes', $detail['skins'][0]);
    }

    public function testCapabilitiesRoleSplitAndModuleContractAndCsvFormulaProtection(): void
    {
        $map = CatalogCapabilities::defaultRoleMap();
        self::assertSame(CatalogCapabilities::all(), $map['administrator']);
        self::assertSame([CatalogCapabilities::VIEW_CATALOG, CatalogCapabilities::MANAGE_CATALOG], $map['shop_manager']);
        self::assertNotContains(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS, $map['shop_manager']);
        self::assertNotContains(CatalogCapabilities::IMPORT_EXPORT_CATALOG, $map['shop_manager']);

        self::assertSame("'=CMD|'/C calc'!A0", CatalogValidator::escapeCsvCell("=CMD|'/C calc'!A0"));
        self::assertSame('DDJ-FLX4', CatalogValidator::escapeCsvCell('DDJ-FLX4'));

        $registry = new ModuleRegistry();
        $container = new Container();
        $module = new DeviceCatalogModule('/tmp/djgear-device-catalog.php');
        $registry->register($module);
        $module->register($container);
        self::assertTrue($registry->has('djgear-device-catalog'));
    }
}
