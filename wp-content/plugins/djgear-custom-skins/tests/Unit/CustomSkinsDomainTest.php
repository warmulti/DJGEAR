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
    public function testDualTrackStateMachineEnforcesStandardVsCustomDesignAndProofApprovalGate(): void
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
            true,
            false
        ));

        // CRITICAL REGRESSION: Custom-Design job on_hold CANNOT bypass customer proof approval into production!
        self::assertFalse(SkinJobStateMachine::canTransition('on_hold', 'queued_for_production', true, false));
        self::assertFalse(SkinJobStateMachine::canTransition('on_hold', 'in_production', true, false));
        self::assertFalse(SkinJobStateMachine::canTransition('approved_for_production', 'queued_for_production', true, false));

        // Once customer has approved proof (hasApprovedProof = true), production transitions are permitted
        self::assertTrue(SkinJobStateMachine::canTransition('on_hold', 'queued_for_production', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('on_hold', 'in_production', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('approved_for_production', 'queued_for_production', true, true));

        // Staff allowedNextStatuses NEVER exposes customer-only `approved_for_production`
        $staffNextFromProof = SkinJobStateMachine::allowedNextStatuses('proof_sent', true, false, true);
        self::assertNotContains(SkinJobStateMachine::STATUS_APPROVED_FOR_PRODUCTION, $staffNextFromProof);

        // Full Custom Design track with approved proof
        self::assertTrue(SkinJobStateMachine::canTransition('submitted_for_review', 'in_design_review', true, false));
        self::assertTrue(SkinJobStateMachine::canTransition('in_design_review', 'proof_sent', true, false));
        self::assertTrue(SkinJobStateMachine::canTransition('proof_sent', 'approved_for_production', true, false));
        self::assertTrue(SkinJobStateMachine::canTransition('approved_for_production', 'queued_for_production', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('queued_for_production', 'in_production', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('in_production', 'quality_check', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('quality_check', 'packed', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('packed', 'shipped', true, true));
        self::assertTrue(SkinJobStateMachine::canTransition('shipped', 'completed', true, true));

        // Terminal states cannot transition
        self::assertFalse(SkinJobStateMachine::canTransition('completed', 'shipped', true, true));
        self::assertFalse(SkinJobStateMachine::canTransition('cancelled', 'draft', false, false));

        // Cancellation behavior: pre-production -> cancelled, in-production -> on_hold
        self::assertSame('cancelled', SkinJobStateMachine::statusOnOrderCancel('submitted_for_review'));
        self::assertSame('cancelled', SkinJobStateMachine::statusOnOrderCancel('queued_for_production'));
        self::assertSame('on_hold', SkinJobStateMachine::statusOnOrderCancel('in_production'));
        self::assertNull(SkinJobStateMachine::statusOnOrderCancel('shipped'));
    }

    public function testDpiValidatorEnforces180Reject180To299WarningAnd300Pass(): void
    {
        $validator = new ArtworkDpiValidator();

        // Target: 254mm x 254mm (10 x 10 inches)
        $low = $validator->evaluateDimensions(1500, 1500, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $low['dpi_status']);
        self::assertSame(150.0, $low['computed_dpi']);
        self::assertSame(1800, $low['min_required_px_width']);
        self::assertSame(3000, $low['recommended_px_width']);

        $warn = $validator->evaluateDimensions(2200, 2200, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_WARNING, $warn['dpi_status']);
        self::assertSame(220.0, $warn['computed_dpi']);

        $pass = $validator->evaluateDimensions(3000, 3000, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_PASS, $pass['dpi_status']);
        self::assertSame(300.0, $pass['computed_dpi']);

        $edgeWarn = $validator->evaluateDimensions(1800, 1800, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_WARNING, $edgeWarn['dpi_status']);

        $edgeReject = $validator->evaluateDimensions(1799, 1799, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $edgeReject['dpi_status']);
    }

    public function testPdfValidatorNeverBlindlyPassesAndDetectsMultipageAndLowResEmbeddedRasters(): void
    {
        $validator = new ArtworkDpiValidator();
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'djgear_pdf_test_' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0750, true);

        // 1. Multi-page PDF -> REJECTED
        $multiPdf = $tmpDir . DIRECTORY_SEPARATOR . 'multi.pdf';
        file_put_contents($multiPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 2 >> endobj\n2 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n3 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n%%EOF");
        $multiReport = $validator->evaluatePdf($multiPdf, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $multiReport['dpi_status']);
        self::assertSame(2, $multiReport['page_count']);

        // 2. Single-page PDF containing low-res embedded raster image (1000x1000 on 254x254mm = 100 DPI) -> REJECTED
        $lowResPdf = $tmpDir . DIRECTORY_SEPARATOR . 'lowres-raster.pdf';
        file_put_contents($lowResPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 1 >> endobj\n2 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n3 0 obj << /Type /XObject /Subtype /Image /Width 1000 /Height 1000 >> endobj\n%%EOF");
        $lowResReport = $validator->evaluatePdf($lowResPdf, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $lowResReport['dpi_status']);
        self::assertSame(100.0, $lowResReport['computed_dpi']);

        // 3. Single-page PDF containing 300 DPI embedded raster (3000x3000 on 254x254mm) -> PASS
        $highResPdf = $tmpDir . DIRECTORY_SEPARATOR . 'highres-raster.pdf';
        file_put_contents($highResPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 1 >> endobj\n2 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n3 0 obj << /Type /XObject /Subtype /Image /Width 3000 /Height 3000 >> endobj\n%%EOF");
        $highResReport = $validator->evaluatePdf($highResPdf, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_PASS, $highResReport['dpi_status']);
        self::assertSame(300.0, $highResReport['computed_dpi']);

        // 4. Single-page vector-only PDF (no measurable embedded raster) -> MANUAL_REVIEW_REQUIRED (never silent pass!)
        $vectorPdf = $tmpDir . DIRECTORY_SEPARATOR . 'vector.pdf';
        file_put_contents($vectorPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 1 >> endobj\n2 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n%%EOF");
        $vectorReport = $validator->evaluatePdf($vectorPdf, 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_MANUAL_REVIEW_REQUIRED, $vectorReport['dpi_status']);
        self::assertNull($vectorReport['computed_dpi']);
        self::assertSame(254.0, $vectorReport['pdf_page_mm']['width_mm']);

        @unlink($multiPdf);
        @unlink($lowResPdf);
        @unlink($highResPdf);
        @unlink($vectorPdf);
        @rmdir($tmpDir);
    }

    public function testMultipageTiffIsDetectedAndRejected(): void
    {
        $validator = new ArtworkDpiValidator();
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'djgear_tiff_test_' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0750, true);

        // Minimal valid Little-Endian 2-IFD Classic TIFF header (II + 42 + offset 8 -> IFD1(0 entries, next=14) -> IFD2(0 entries, next=0))
        $twoPageTiffBytes = 'II' . pack('vV', 42, 8) . pack('vV', 0, 14) . pack('vV', 0, 0);
        $tiffPath = $tmpDir . DIRECTORY_SEPARATOR . 'multipage.tif';
        file_put_contents($tiffPath, $twoPageTiffBytes);

        self::assertSame(2, $validator->countTiffPages($tiffPath));
        $report = $validator->evaluateFile($tiffPath, 'image/tiff', 254.0, 254.0);
        self::assertSame(ArtworkDpiValidator::STATUS_REJECTED, $report['dpi_status']);

        $vault = new CustomerArtworkVault($tmpDir . DIRECTORY_SEPARATOR . 'vault');
        $rejectedByVault = false;
        try {
            $vault->storeFile($tiffPath, 'multipage.tif', '11111111-2222-4333-8444-555555555555', false);
        } catch (SkinValidationException $e) {
            $rejectedByVault = ($e->statusCode === 400 && $e->errorCode === 'djgear_skins_multipage_tiff_unsupported');
        }
        self::assertTrue($rejectedByVault, 'Multi-page TIFF must be rejected by CustomerArtworkVault.');

        @unlink($tiffPath);
        @rmdir($tmpDir);
    }

    public function testStandardPlanForcesArtworkUploadDisabled(): void
    {
        $clean = SkinValidator::validatePlan([
            'slug'                    => 'standard-fast',
            'name_fa'                 => 'استاندارد',
            'name_en'                 => 'Standard Fast',
            'plan_type'               => 'standard',
            'allows_artwork_upload'   => true,
            'requires_artwork'        => true,
            'requires_proof_approval' => true,
            'max_revisions'           => 5,
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
                    'id'                => 77,
                    'revision_type'     => 'customer_artwork',
                    'version_number'    => 1,
                    'storage_rel_path'  => '2026/11/secret/file.png',
                    'original_filename' => 'logo.png',
                    'mime_type'         => 'image/png',
                    'file_size_bytes'   => 1234,
                    'sha256_hash'       => str_repeat('a', 64),
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

        // Symlink pointing outside vault root must throw forbidden
        $outsideFile = $tmpRoot . DIRECTORY_SEPARATOR . 'outside-secret.txt';
        file_put_contents($outsideFile, 'SECRET');
        $symlinkRel = 'escape-link.png';
        $symlinkPath = $vaultDir . DIRECTORY_SEPARATOR . $symlinkRel;
        if (@symlink($outsideFile, $symlinkPath)) {
            $symlinkBlocked = false;
            try {
                $vault->resolveSafePath($symlinkRel);
            } catch (SkinValidationException $e) {
                $symlinkBlocked = ($e->statusCode === 403);
            }
            self::assertTrue($symlinkBlocked, 'Symlink pointing outside vault must be blocked with 403.');
            @unlink($symlinkPath);
        }

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

        // Cleanup
        $vault->deleteFile($stored['storage_rel_path']);
        @unlink($tmpPng);
        @unlink($tmpSvg);
        @unlink($tmpPhp);
        @unlink($outsideFile);
    }

    public function testRollbackGuardBlocksAccidentalTableDrops(): void
    {
        $wpdb = $this->createStub(\wpdb::class);
        $runner = new SkinMigrationRunner($wpdb, []);

        $this->expectException(\RuntimeException::class);
        $runner->rollbackLast('WRONG_TOKEN');
    }
}
