<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Tests\Unit;

use DJGear\CustomSkins\Domain\SkinJobStateMachine;
use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Domain\SkinValidator;
use DJGear\CustomSkins\Lifecycle\SkinMigrationRunner;
use DJGear\CustomSkins\Security\PublicSkinsSerializer;
use DJGear\CustomSkins\Security\SkinCapabilities;
use DJGear\CustomSkins\Services\ArtworkDpiValidator;
use DJGear\CustomSkins\Services\CustomerArtworkVault;
use PHPUnit\Framework\TestCase;

final class CustomSkinsDomainTest extends TestCase
{
    public function testDualTrackStateMachineEnforcesStandardVsCustomDesign(): void
    {
        // Standard plan (requiresProofApproval = false) routes payment directly to queued_for_production
        self::assertSame(
            SkinJobStateMachine::STATUS_QUEUED_FOR_PRODUCTION,
            SkinJobStateMachine::postPaymentStatus(false)
        );
        self::assertTrue(SkinJobStateMachine::canTransition(
            SkinJobStateMachine::STATUS_AWAITING_PAYMENT,
            SkinJobStateMachine::STATUS_QUEUED_FOR_PRODUCTION,
            false
        ));
        self::assertFalse(SkinJobStateMachine::canTransition(
            SkinJobStateMachine::STATUS_AWAITING_PAYMENT,
            SkinJobStateMachine::STATUS_SUBMITTED_FOR_REVIEW,
            false
        ));

        // Custom Design plan (requiresProofApproval = true) MUST enter submitted_for_review first
        self::assertSame(
            SkinJobStateMachine::STATUS_SUBMITTED_FOR_REVIEW,
            SkinJobStateMachine::postPaymentStatus(true)
        );
        self::assertTrue(SkinJobStateMachine::canTransition(
            SkinJobStateMachine::STATUS_AWAITING_PAYMENT,
            SkinJobStateMachine::STATUS_SUBMITTED_FOR_REVIEW,
            true
        ));
        self::assertFalse(SkinJobStateMachine::canTransition(
            SkinJobStateMachine::STATUS_AWAITING_PAYMENT,
            SkinJobStateMachine::STATUS_QUEUED_FOR_PRODUCTION,
            true
        ));

        // Full Custom Design track
        self::assertTrue(SkinJobStateMachine::canTransition('submitted_for_review', 'in_design_review', true));
        self::assertTrue(SkinJobStateMachine::canTransition('in_design_review', 'proof_sent', true));
        self::assertTrue(SkinJobStateMachine::canTransition('proof_sent', 'approved_for_production', true));
        self::assertTrue(SkinJobStateMachine::canTransition('approved_for_production', 'queued_for_production', true));
        self::assertTrue(SkinJobStateMachine::canTransition('queued_for_production', 'in_production', true));
        self::assertTrue(SkinJobStateMachine::canTransition('in_production', 'quality_check', true));
        self::assertTrue(SkinJobStateMachine::canTransition('quality_check', 'packed', true));
        self::assertTrue(SkinJobStateMachine::canTransition('packed', 'shipped', true));
        self::assertTrue(SkinJobStateMachine::canTransition('shipped', 'completed', true));

        // Terminal states cannot transition
        self::assertFalse(SkinJobStateMachine::canTransition('completed', 'shipped', true));
        self::assertFalse(SkinJobStateMachine::canTransition('cancelled', 'draft', false));

        // Cancellation behavior: pre-production -> cancelled, in-production -> on_hold
        self::assertSame('cancelled', SkinJobStateMachine::statusOnOrderCancel('submitted_for_review'));
        self::assertSame('cancelled', SkinJobStateMachine::statusOnOrderCancel('queued_for_production'));
        self::assertSame('on_hold', SkinJobStateMachine::statusOnOrderCancel('in_production'));
        self::assertNull(SkinJobStateMachine::statusOnOrderCancel('shipped'));
    }

    public function testDpiValidatorEnforces180Reject180To299WarningAnd300Pass(): void
    {
        $validator = new ArtworkDpiValidator();

        // Target: 254mm x 254mm (10 x 10 inches) for clean exact DPI math
        // 1500x1500 px = 150 DPI -> REJECTED (< 180)
        $low = $validator->evaluateDimensions(1500, 1500, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $low['dpi_status']);
        self::assertSame(150.0, $low['computed_dpi']);
        self::assertSame(1800, $low['min_required_px_width']);
        self::assertSame(3000, $low['recommended_px_width']);

        // 2200x2200 px = 220 DPI -> WARNING (180-299)
        $warn = $validator->evaluateDimensions(2200, 2200, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_WARNING, $warn['dpi_status']);
        self::assertSame(220.0, $warn['computed_dpi']);

        // 3000x3000 px = 300 DPI -> PASS (>= 300)
        $pass = $validator->evaluateDimensions(3000, 3000, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_PASS, $pass['dpi_status']);
        self::assertSame(300.0, $pass['computed_dpi']);

        // Boundary: 180.0 DPI -> WARNING, 179.9 -> REJECTED
        $edgeWarn = $validator->evaluateDimensions(1800, 1800, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_WARNING, $edgeWarn['dpi_status']);

        $edgeReject = $validator->evaluateDimensions(1799, 1799, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $edgeReject['dpi_status']);
    }

    public function testStandardPlanForcesArtworkUploadDisabled(): void
    {
        $clean = SkinValidator::validatePlan([
            'slug'                    => 'standard-fast',
            'name_fa'                 => 'استاندارد',
            'name_en'                 => 'Standard Fast',
            'plan_type'               => 'standard',
            'allows_artwork_upload'   => true, // Should be overridden to false
            'requires_artwork'        => true, // Should be overridden to false
            'requires_proof_approval' => true, // Should be overridden to false
            'max_revisions'           => 5,    // Should be overridden to 0
            'price_surcharge'         => 0,
        ]);

        self::assertFalse($clean['allows_artwork_upload']);
        self::assertFalse($clean['requires_artwork']);
        self::assertFalse($clean['requires_proof_approval']);
        self::assertSame(0, $clean['max_revisions']);
    }

    public function testValidatorRejectsInvalidVariantColorHex(): void
    {
        $this->expectException(SkinValidationException::class);
        SkinValidator::validateVariant([
            'device_id'     => 10,
            'wc_product_id' => 20,
            'model_code'    => 'stealth',
            'model_name_fa' => 'استلت',
            'model_name_en' => 'Stealth',
            'color_code'    => 'black',
            'color_name_fa' => 'مشکی',
            'color_name_en' => 'Black',
            'color_hex'     => 'not-a-hex',
        ]);
    }

    public function testCapabilitiesHaveZeroEngineeringPermissionsAndRestrictPlansToAdmin(): void
    {
        $matrix = SkinCapabilities::roleMatrix();

        self::assertContains(SkinCapabilities::MANAGE_SKIN_PLANS, $matrix['administrator']);
        self::assertNotContains(SkinCapabilities::MANAGE_SKIN_PLANS, $matrix['shop_manager']);
        self::assertContains(SkinCapabilities::VIEW_SKIN_ORDERS, $matrix['shop_manager']);
        self::assertContains(SkinCapabilities::MANAGE_SKIN_ORDERS, $matrix['shop_manager']);
        self::assertContains(SkinCapabilities::MANAGE_SKIN_CATALOG, $matrix['shop_manager']);

        foreach (SkinCapabilities::all() as $cap) {
            self::assertStringNotContainsString('dieline', $cap);
            self::assertStringNotContainsString('engineering', $cap);
        }
    }

    public function testCustomerSerializerStripsInternalNotesAndPrivatePaths(): void
    {
        $serialized = PublicSkinsSerializer::customerJob([
            'job_uuid'          => '11111111-2222-4333-8444-555555555555',
            'device_id'         => 5,
            'skin_variant_id'   => 9,
            'plan_id'           => 2,
            'status'            => 'proof_sent',
            'internal_notes'    => 'SECRET STAFF NOTE',
            'assigned_staff_id' => 99,
            'artworks'          => [
                [
                    'id'               => 77,
                    'revision_type'    => 'customer_artwork',
                    'version_number'   => 1,
                    'storage_rel_path' => '2026/11/secret/file.png',
                    'original_filename' => 'logo.png',
                    'mime_type'        => 'image/png',
                    'file_size_bytes'  => 1234,
                    'sha256_hash'      => str_repeat('a', 64),
                ],
            ],
            'status_history'    => [
                ['id' => 1, 'to_status' => 'draft', 'is_customer_visible' => true, 'comment' => 'Visible'],
                ['id' => 2, 'to_status' => 'in_design_review', 'is_customer_visible' => false, 'comment' => 'INTERNAL ONLY'],
            ],
        ]);

        self::assertArrayNotHasKey('internal_notes', $serialized);
        self::assertArrayNotHasKey('assigned_staff_id', $serialized);
        self::assertArrayNotHasKey('storage_rel_path', $serialized['artworks'][0]);
        self::assertCount(1, $serialized['status_history']);
        self::assertSame('Visible', $serialized['status_history'][0]['comment']);
    }

    public function testArtworkVaultStoresValidPngAndRejectsPhpSvgAndTraversal(): void
    {
        $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'djgear_skins_vault_test_' . bin2hex(random_bytes(4));
        $vaultDir = $tmpRoot . DIRECTORY_SEPARATOR . 'djgear-private' . DIRECTORY_SEPARATOR . 'customer-artwork';
        $vault = new CustomerArtworkVault($vaultDir);

        // Generate a real tiny PNG via base64 (1x1 valid PNG)
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        self::assertIsString($pngBytes);

        $tmpPng = $tmpRoot . DIRECTORY_SEPARATOR . 'sample.png';
        @mkdir($tmpRoot, 0750, true);
        file_put_contents($tmpPng, $pngBytes);

        $stored = $vault->storeFile($tmpPng, 'my-logo.png', '11111111-2222-4333-8444-555555555555', false);
        self::assertSame('image/png', $stored['mime_type']);
        self::assertSame(1, $stored['pixel_width']);
        self::assertSame(1, $stored['pixel_height']);
        self::assertSame(64, strlen($stored['sha256_hash']));
        self::assertFileExists($vaultDir . DIRECTORY_SEPARATOR . '.htaccess');
        self::assertFileExists($vaultDir . DIRECTORY_SEPARATOR . 'index.php');

        $resolved = $vault->resolveSafePath($stored['storage_rel_path']);
        self::assertFileExists($resolved);

        // Path traversal must throw forbidden
        $traversalBlocked = false;
        try {
            $vault->resolveSafePath('../../../etc/passwd');
        } catch (SkinValidationException $e) {
            $traversalBlocked = ($e->statusCode === 403);
        }
        self::assertTrue($traversalBlocked, 'Path traversal should be blocked with 403.');

        // SVG upload must be rejected
        $tmpSvg = $tmpRoot . DIRECTORY_SEPARATOR . 'xss.svg';
        file_put_contents($tmpSvg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $svgRejected = false;
        try {
            $vault->storeFile($tmpSvg, 'xss.svg', '11111111-2222-4333-8444-555555555555', false);
        } catch (SkinValidationException $e) {
            $svgRejected = ($e->statusCode === 400);
        }
        self::assertTrue($svgRejected, 'SVG upload must be rejected.');

        // PHP upload disguised as .png must be rejected
        $tmpPhp = $tmpRoot . DIRECTORY_SEPARATOR . 'shell.png';
        file_put_contents($tmpPhp, '<?php echo "pwn"; ?>');
        $phpRejected = false;
        try {
            $vault->storeFile($tmpPhp, 'shell.png', '11111111-2222-4333-8444-555555555555', false);
        } catch (SkinValidationException $e) {
            $phpRejected = ($e->statusCode === 400);
        }
        self::assertTrue($phpRejected, 'PHP payload disguised as PNG must be rejected.');

        // Cleanup temp vault
        $vault->deleteFile($stored['storage_rel_path']);
        @unlink($tmpPng);
        @unlink($tmpSvg);
        @unlink($tmpPhp);
    }

    public function testRollbackGuardBlocksAccidentalTableDrops(): void
    {
        $wpdb = $this->createStub(\wpdb::class);
        $runner = new SkinMigrationRunner($wpdb, []);

        $this->expectException(\RuntimeException::class);
        $runner->rollbackLast('WRONG_TOKEN');
    }
}
