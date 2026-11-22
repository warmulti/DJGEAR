<?php
/**
 * Live WordPress 7.1 + WooCommerce 11.3 HPOS Integration Test Suite for djgear-custom-skins (Phase 4).
 *
 * Run via:
 *   wp eval-file wp-content/plugins/djgear-custom-skins/tests/Integration/wp-custom-skins-integration-test.php
 */

use DJGear\Core\Rest\RestKernel;
use DJGear\CustomSkins\CustomSkinsModule;
use DJGear\CustomSkins\Lifecycle\Migrations\CreateCustomSkinTables;
use DJGear\CustomSkins\Lifecycle\SkinMigrationRunner;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Repositories\SkinPlanRepository;
use DJGear\CustomSkins\Repositories\SkinVariantRepository;
use DJGear\CustomSkins\Security\SkinCapabilities;
use DJGear\CustomSkins\Services\ArtworkDpiValidator;
use DJGear\CustomSkins\Services\CustomerArtworkVault;
use DJGear\CustomSkins\WooCommerce\WooCommerceSkinBridge;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\TaxonomyRepository;

$passed = 0;
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $passed++;
    echo "PASS: {$label}\n";
};

/**
 * Helper to generate a real valid PNG file of given pixel dimensions (via Imagick or GD).
 */
$makePng = static function (string $path, int $width, int $height): void {
    if (class_exists(Imagick::class)) {
        $im = new Imagick();
        $im->newImage($width, $height, new ImagickPixel('#0f172a'));
        $im->setImageFormat('png');
        $im->setOption('png:compression-level', '1');
        $im->writeImage($path);
        $im->clear();
        $im->destroy();
        return;
    }
    if (function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor($width, $height);
        imagepng($img, $path, 1);
        imagedestroy($img);
        return;
    }
    throw new RuntimeException('Neither Imagick nor GD is available to generate test PNG fixtures.');
};

global $wpdb;

// --- Idempotent pre-cleanup of any leftover QA users ---
foreach (['qa_skins_mgr', 'qa_skins_cust_a', 'qa_skins_cust_b'] as $oldLogin) {
    $oldUser = get_user_by('login', $oldLogin);
    if ($oldUser instanceof WP_User) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($oldUser->ID);
    }
}

$createdUsers = [];
$createdProducts = [];
$createdOrders = [];
$createdJobs = [];
$createdVariants = [];
$createdPlans = [];
$createdDeviceId = null;
$createdBrandId = null;
$createdCategoryId = null;
$tempFiles = [];
$storedVaultPaths = [];

try {
    // 1. Module registration & dependency resolution
    $core = djgear_core();
    $skinModule = $core->modules()->get(CustomSkinsModule::MODULE_ID);
    $assert($skinModule !== null, 'djgear-custom-skins module is registered with djgear-core');
    $skinDesc = null;
    foreach ($core->modules()->descriptors() as $desc) {
        if ($desc->id === CustomSkinsModule::MODULE_ID) {
            $skinDesc = $desc;
            break;
        }
    }
    $assert($skinDesc !== null && $skinDesc->booted === true, 'djgear-custom-skins module booted after djgear-device-catalog');
    $assert(
        in_array('djgear-device-catalog', $skinModule->dependencies(), true),
        'djgear-custom-skins declares dependency on djgear-device-catalog'
    );

    // 2. Verify 5 normalized MySQL tables exist
    $expectedTables = [
        $wpdb->prefix . CreateCustomSkinTables::TABLE_VARIANTS,
        $wpdb->prefix . CreateCustomSkinTables::TABLE_PLANS,
        $wpdb->prefix . CreateCustomSkinTables::TABLE_JOBS,
        $wpdb->prefix . CreateCustomSkinTables::TABLE_ARTWORKS,
        $wpdb->prefix . CreateCustomSkinTables::TABLE_STATUS_HISTORY,
    ];
    foreach ($expectedTables as $tbl) {
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl));
        $assert($exists === $tbl, "Table exists: {$tbl}");
    }

    // 3. Verify rollback guard prevents accidental table drops
    $runner = new SkinMigrationRunner($wpdb, [new CreateCustomSkinTables()]);
    $rollbackBlocked = false;
    try {
        $runner->rollbackLast();
    } catch (RuntimeException $e) {
        $rollbackBlocked = str_contains($e->getMessage(), 'DJGEAR_ALLOW_DESTRUCTIVE_ROLLBACK');
    }
    $assert($rollbackBlocked, 'Migration rollback guard blocks accidental drops');

    // 4. Verify default seeded plans (Standard + Custom Design)
    /** @var SkinPlanRepository $planRepo */
    $planRepo = $core->container()->get(SkinPlanRepository::class);
    $standardPlan = $planRepo->findBySlug('standard');
    $customPlan = $planRepo->findBySlug('custom-design');
    $assert($standardPlan !== null && $standardPlan['allows_artwork_upload'] === false, 'Default Standard plan seeded with allows_artwork_upload=false');
    $assert($customPlan !== null && $customPlan['allows_artwork_upload'] === true && $customPlan['requires_proof_approval'] === true, 'Default Custom Design plan seeded with allows_artwork_upload=true and proof approval');

    // 5. Verify capabilities matrix & zero engineering caps
    $adminRole = get_role('administrator');
    $mgrRole = get_role('shop_manager');
    $assert($adminRole !== null && $adminRole->has_cap(SkinCapabilities::MANAGE_SKIN_PLANS), 'administrator has djgear_manage_skin_plans');
    $assert($mgrRole !== null && $mgrRole->has_cap(SkinCapabilities::MANAGE_SKIN_CATALOG), 'shop_manager has djgear_manage_skin_catalog');
    $assert($mgrRole !== null && $mgrRole->has_cap(SkinCapabilities::MANAGE_SKIN_ORDERS), 'shop_manager has djgear_manage_skin_orders');
    $assert($mgrRole !== null && !$mgrRole->has_cap(SkinCapabilities::MANAGE_SKIN_PLANS), 'shop_manager does NOT have djgear_manage_skin_plans');

    // 6. Create QA users (admin, shop_manager, customerA, customerB)
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $adminId = $admins !== [] ? (int) $admins[0]->ID : 1;

    $mgrId = wp_create_user('qa_skins_mgr', wp_generate_password(16, true), 'qa_skins_mgr@djgear.local');
    $assert(is_int($mgrId) && $mgrId > 0, 'Created QA shop_manager');
    (new WP_User($mgrId))->set_role('shop_manager');
    $createdUsers[] = $mgrId;

    $custAId = wp_create_user('qa_skins_cust_a', wp_generate_password(16, true), 'qa_skins_a@djgear.local');
    $assert(is_int($custAId) && $custAId > 0, 'Created QA customer A');
    (new WP_User($custAId))->set_role('customer');
    $createdUsers[] = $custAId;

    $custBId = wp_create_user('qa_skins_cust_b', wp_generate_password(16, true), 'qa_skins_b@djgear.local');
    $assert(is_int($custBId) && $custBId > 0, 'Created QA customer B');
    (new WP_User($custBId))->set_role('customer');
    $createdUsers[] = $custBId;

    // 7. Create QA Catalog Device + WooCommerce Product
    /** @var TaxonomyRepository $taxRepo */
    $taxRepo = $core->container()->get(TaxonomyRepository::class);
    /** @var DeviceRepository $deviceRepo */
    $deviceRepo = $core->container()->get(DeviceRepository::class);

    $brand = $taxRepo->createBrand([
        'slug'    => 'qa-skins-brand-' . time(),
        'name_fa' => 'برند تست اسکین',
        'name_en' => 'QA Skins Brand',
    ]);
    $createdBrandId = (int) $brand['id'];

    $cat = $taxRepo->createCategory([
        'slug'    => 'qa-skins-cat-' . time(),
        'name_fa' => 'کنترلر تست',
        'name_en' => 'QA Controllers',
    ]);
    $createdCategoryId = (int) $cat['id'];

    $product = new WC_Product_Simple();
    $product->set_name('QA DJ Controller Skin Base Product');
    $product->set_status('publish');
    $product->set_regular_price('1500000');
    $product->set_price('1500000');
    $product->set_sku('QA-SKIN-BASE-' . time());
    $wcProductId = (int) $product->save();
    $createdProducts[] = $wcProductId;
    $assert($wcProductId > 0, 'Created WooCommerce base skin product');

    $device = $deviceRepo->create([
        'brand_term_id'         => $createdBrandId,
        'category_term_id'      => $createdCategoryId,
        'model_code'            => 'QA-FLX4-' . time(),
        'name_fa'               => 'کنترلر تست FLX4',
        'name_en'               => 'QA Controller FLX4',
        'lifecycle_status'      => 'active',
        'primary_wc_product_id' => $wcProductId,
    ]);
    $createdDeviceId = (int) $device['id'];
    $assert($createdDeviceId > 0, 'Created active DJ device in djgear-device-catalog');

    // 8. Admin & Shop Manager RBAC via REST
    // 8a. shop_manager CAN create skin variant
    wp_set_current_user($mgrId);
    $req = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/variants');
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'wc_product_id'   => $wcProductId,
        'model_code'      => 'stealth-carbon',
        'model_name_fa'   => 'کربن مات استلت',
        'model_name_en'   => 'Stealth Carbon',
        'color_code'      => 'matte-black',
        'color_name_fa'   => 'مشکی مات',
        'color_name_en'   => 'Matte Black',
        'color_hex'       => '#111827',
        'finish'          => 'carbon',
        'print_width_mm'  => 254.0,
        'print_height_mm' => 254.0,
        'status'          => 'active',
    ]));
    $res = rest_do_request($req);
    $assert($res->get_status() === 201, 'shop_manager can create skin variant (HTTP 201)');
    $variantData = $res->get_data()['data'];
    $variantId = (int) $variantData['id'];
    $createdVariants[] = $variantId;

    // 8b. shop_manager CANNOT create/edit/delete ordering plans (HTTP 403)
    $planReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/plans');
    $planReq->set_header('Content-Type', 'application/json');
    $planReq->set_body(wp_json_encode([
        'slug'      => 'qa-forbidden-plan',
        'name_fa'   => 'تست',
        'name_en'   => 'Forbidden Plan',
        'plan_type' => 'standard',
    ]));
    $planRes = rest_do_request($planReq);
    $assert($planRes->get_status() === 403, 'shop_manager blocked from creating ordering plans (HTTP 403)');

    // 8c. administrator CAN create and delete a custom ordering plan
    wp_set_current_user($adminId);
    $adminPlanReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/plans');
    $adminPlanReq->set_header('Content-Type', 'application/json');
    $adminPlanReq->set_body(wp_json_encode([
        'slug'                  => 'qa-vip-plan-' . time(),
        'name_fa'               => 'پلن ویژه تست',
        'name_en'               => 'QA VIP Custom Plan',
        'plan_type'             => 'custom_design',
        'allows_artwork_upload' => true,
        'requires_artwork'      => true,
        'requires_proof_approval' => true,
        'max_revisions'         => 2,
        'price_surcharge'       => 350000,
    ]));
    $adminPlanRes = rest_do_request($adminPlanReq);
    $assert($adminPlanRes->get_status() === 201, 'administrator can create ordering plan (HTTP 201)');
    $createdPlans[] = (int) $adminPlanRes->get_data()['data']['id'];

    // 9. Public /skins/options & Anonymous 401 checks
    wp_set_current_user(0);
    $pubReq = new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/options');
    $pubReq->set_param('device_id', $createdDeviceId);
    $pubRes = rest_do_request($pubReq);
    $assert($pubRes->get_status() === 200, 'Public GET /djgear/v1/skins/options returns HTTP 200');
    $pubData = $pubRes->get_data()['data'];
    $assert(count($pubData['variants']) === 1 && (float) $pubData['variants'][0]['base_price'] === 1500000.0, 'Public options returns active variant with live WooCommerce base_price');
    $assert(count($pubData['plans']) >= 2, 'Public options returns active ordering plans');

    $anonJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $anonJobRes = rest_do_request($anonJobReq);
    $assert($anonJobRes->get_status() === 401, 'Anonymous POST /djgear/v1/skins/jobs blocked with HTTP 401');

    $anonMyReq = new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/my-jobs');
    $anonMyRes = rest_do_request($anonMyReq);
    $assert($anonMyRes->get_status() === 401, 'Anonymous GET /djgear/v1/skins/my-jobs blocked with HTTP 401');

    // 10. STANDARD PLAN END-TO-END (No Artwork Upload Allowed -> Direct to Production on Payment)
    wp_set_current_user($custAId);
    $stdJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $stdJobReq->set_header('Content-Type', 'application/json');
    $stdJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $variantId,
        'plan_id'         => (int) $standardPlan['id'],
        'customer_notes'  => 'Standard matte black skin order',
    ]));
    $stdJobRes = rest_do_request($stdJobReq);
    $assert($stdJobRes->get_status() === 201, 'Customer A creates Standard plan draft job (HTTP 201)');
    $stdUuid = (string) $stdJobRes->get_data()['data']['job_uuid'];
    /** @var SkinJobRepository $jobRepo */
    $jobRepo = $core->container()->get(SkinJobRepository::class);
    $stdJobRecord = $jobRepo->findByUuid($stdUuid, false);
    $createdJobs[] = (int) $stdJobRecord['id'];

    // Attempt artwork upload on Standard plan -> MUST fail HTTP 400
    $tmpPngSmall = sys_get_temp_dir() . '/qa_std_attempt_' . time() . '.png';
    $makePng($tmpPngSmall, 200, 200);
    $tempFiles[] = $tmpPngSmall;

    $stdUploadReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$stdUuid}/artwork");
    $stdUploadReq->set_file_params([
        'file' => [
            'name'     => 'logo.png',
            'type'     => 'image/png',
            'tmp_name' => $tmpPngSmall,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpPngSmall),
        ],
    ]);
    $stdUploadRes = rest_do_request($stdUploadReq);
    $assert($stdUploadRes->get_status() === 400, 'Artwork upload on Standard plan is strictly blocked (HTTP 400)');

    // Standard plan add-to-cart succeeds without artwork
    $stdCartReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$stdUuid}/add-to-cart");
    $stdCartRes = rest_do_request($stdCartReq);
    $assert($stdCartRes->get_status() === 200, 'Standard plan job ready for WooCommerce cart without artwork');

    // Bind Standard job to HPOS WooCommerce Order and simulate payment -> queued_for_production
    /** @var WooCommerceSkinBridge $wcBridge */
    $wcBridge = $core->container()->get(WooCommerceSkinBridge::class);
    $stdOrder = wc_create_order(['customer_id' => $custAId]);
    $stdItemId = $stdOrder->add_product(wc_get_product($wcProductId), 1);
    $stdItem = $stdOrder->get_item($stdItemId);
    $wcBridge->attachJobMetadataToOrderItem($stdItem, $stdJobRecord);
    $stdItem->save();
    $stdOrder->calculate_totals();
    $stdOrder->save();
    $createdOrders[] = $stdOrder->get_id();

    $wcBridge->linkJobToOrder((int) $stdJobRecord['id'], $stdOrder->get_id(), (int) $stdItemId);
    $afterLink = $jobRepo->findById((int) $stdJobRecord['id'], false);
    $assert($afterLink['status'] === 'awaiting_payment', 'Standard job transitions draft -> awaiting_payment on WC order link');

    $wcBridge->onPaymentComplete($stdOrder->get_id());
    $afterPay = $jobRepo->findById((int) $stdJobRecord['id'], false);
    $assert($afterPay['status'] === 'queued_for_production', 'Standard job skips design review and routes to queued_for_production on payment');

    // Cancel WC order -> pre-production job cancels automatically
    $wcBridge->onOrderCancelled($stdOrder->get_id());
    $afterCancel = $jobRepo->findById((int) $stdJobRecord['id'], false);
    $assert($afterCancel['status'] === 'cancelled', 'Standard pre-production job auto-cancels on WC order cancellation');

    // 11. CUSTOM DESIGN PLAN END-TO-END (DPI <180 / 180-299 Ack / >=300 Pass + Isolation + Proof Approval + Shipping)
    wp_set_current_user($custAId);
    $custJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $custJobReq->set_header('Content-Type', 'application/json');
    $custJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $variantId,
        'plan_id'         => (int) $customPlan['id'],
        'customer_notes'  => 'Center my DJ logo cleanly',
    ]));
    $custJobRes = rest_do_request($custJobReq);
    $assert($custJobRes->get_status() === 201, 'Customer A creates Custom Design draft job (HTTP 201)');
    $custUuid = (string) $custJobRes->get_data()['data']['job_uuid'];
    $custJobRecord = $jobRepo->findByUuid($custUuid, false);
    $custJobId = (int) $custJobRecord['id'];
    $createdJobs[] = $custJobId;

    // Add-to-cart before artwork upload must fail (HTTP 400)
    $noArtCartRes = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/add-to-cart"));
    $assert($noArtCartRes->get_status() === 400, 'Custom Design job blocked from cart before artwork upload (HTTP 400)');

    // Disguised PHP upload rejected (HTTP 400)
    $tmpPhp = sys_get_temp_dir() . '/qa_evil_' . time() . '.png';
    file_put_contents($tmpPhp, '<?php system($_GET["c"]); ?>');
    $tempFiles[] = $tmpPhp;
    $phpUpReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artwork");
    $phpUpReq->set_file_params(['file' => ['name' => 'evil.png', 'type' => 'image/png', 'tmp_name' => $tmpPhp, 'error' => 0, 'size' => filesize($tmpPhp)]]);
    $phpUpRes = rest_do_request($phpUpReq);
    $assert($phpUpRes->get_status() === 400, 'Disguised PHP artwork payload rejected (HTTP 400)');

    // 11a. Low DPI (<180 DPI): 1000x1000 px on 254x254mm = 100 DPI -> rejected
    $tmpLow = sys_get_temp_dir() . '/qa_low_dpi_' . time() . '.png';
    $makePng($tmpLow, 1000, 1000);
    $tempFiles[] = $tmpLow;
    $lowReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artwork");
    $lowReq->set_file_params(['file' => ['name' => 'low-dpi.png', 'type' => 'image/png', 'tmp_name' => $tmpLow, 'error' => 0, 'size' => filesize($tmpLow)]]);
    $lowRes = rest_do_request($lowReq);
    $assert($lowRes->get_status() === 201 && $lowRes->get_data()['data']['dpi_report']['dpi_status'] === ArtworkDpiValidator::STATUS_REJECTED, 'Low-DPI (<180) artwork flagged as rejected (100 DPI)');

    $lowCartRes = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/add-to-cart"));
    $assert($lowCartRes->get_status() === 400, 'Low-DPI (<180) artwork blocked from checkout (HTTP 400)');

    // 11b. Warning DPI (180-299 DPI): 2000x2000 px on 254x254mm = 200 DPI -> warning
    $tmpWarn = sys_get_temp_dir() . '/qa_warn_dpi_' . time() . '.png';
    $makePng($tmpWarn, 2000, 2000);
    $tempFiles[] = $tmpWarn;
    $warnReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artwork");
    $warnReq->set_file_params(['file' => ['name' => 'warn-dpi.png', 'type' => 'image/png', 'tmp_name' => $tmpWarn, 'error' => 0, 'size' => filesize($tmpWarn)]]);
    $warnRes = rest_do_request($warnReq);
    $assert($warnRes->get_status() === 201 && $warnRes->get_data()['data']['dpi_report']['dpi_status'] === ArtworkDpiValidator::STATUS_WARNING, 'Mid-DPI (200 DPI) artwork flagged as warning');

    $warnCartBlocked = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/add-to-cart"));
    $assert($warnCartBlocked->get_status() === 400, 'Warning-DPI artwork blocked until explicit customer acknowledgment (HTTP 400)');

    $ackReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/acknowledge-dpi");
    $ackReq->set_header('Content-Type', 'application/json');
    $ackReq->set_body(wp_json_encode(['acknowledged' => true]));
    $ackRes = rest_do_request($ackReq);
    $assert($ackRes->get_status() === 200, 'Customer A records explicit low-DPI warning acknowledgment (HTTP 200)');

    $warnCartAllowed = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/add-to-cart"));
    $assert($warnCartAllowed->get_status() === 200, 'Warning-DPI artwork permitted for checkout after explicit acknowledgment');

    // 11c. High DPI (>=300 DPI): 3000x3000 px on 254x254mm = 300 DPI -> pass immediately
    $tmpHigh = sys_get_temp_dir() . '/qa_high_dpi_' . time() . '.png';
    $makePng($tmpHigh, 3000, 3000);
    $tempFiles[] = $tmpHigh;
    $highReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artwork");
    $highReq->set_file_params(['file' => ['name' => 'print-ready-300dpi.png', 'type' => 'image/png', 'tmp_name' => $tmpHigh, 'error' => 0, 'size' => filesize($tmpHigh)]]);
    $highRes = rest_do_request($highReq);
    $assert($highRes->get_status() === 201 && $highRes->get_data()['data']['dpi_report']['dpi_status'] === ArtworkDpiValidator::STATUS_PASS, '300 DPI artwork passes immediately');
    $highArtworkId = (int) $highRes->get_data()['data']['artwork']['id'];

    // Track stored vault paths for cleanup
    foreach ($jobRepo->listArtworksForJob($custJobId) as $artRow) {
        $storedVaultPaths[] = (string) $artRow['storage_rel_path'];
    }

    // 12. Cross-customer isolation checks (Customer B MUST receive 403 on Customer A's job & artwork)
    wp_set_current_user($custBId);
    $bGet = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$custUuid}"));
    $assert($bGet->get_status() === 403, 'Customer B blocked from viewing Customer A job (HTTP 403)');

    $bDl = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artworks/{$highArtworkId}/download"));
    $assert($bDl->get_status() === 403, 'Customer B blocked from downloading Customer A private artwork (HTTP 403)');

    $bAdmin = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/jobs'));
    $assert($bAdmin->get_status() === 403, 'Customer B blocked from admin production queue (HTTP 403)');

    // Customer A CAN download own private artwork with strict security headers
    wp_set_current_user($custAId);
    $aDl = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artworks/{$highArtworkId}/download"));
    $headers = $aDl->get_headers();
    $assert($aDl->get_status() === 200, 'Customer A can download own artwork via authenticated proxy (HTTP 200)');
    $assert(($headers['X-Content-Type-Options'] ?? '') === 'nosniff', 'Download proxy sets X-Content-Type-Options: nosniff');
    $assert(str_contains((string) ($headers['Cache-Control'] ?? ''), 'no-store'), 'Download proxy sets Cache-Control: private, no-store');

    // 13. WooCommerce HPOS Order + Payment -> submitted_for_review -> Designer Proof -> Customer Approval -> Production -> Shipped
    $custOrder = wc_create_order(['customer_id' => $custAId]);
    $custItemId = $custOrder->add_product(wc_get_product($wcProductId), 1);
    $custItem = $custOrder->get_item($custItemId);
    $wcBridge->attachJobMetadataToOrderItem($custItem, $jobRepo->findById($custJobId, false));
    $custItem->save();
    $custOrder->calculate_totals();
    $custOrder->save();
    $createdOrders[] = $custOrder->get_id();

    $wcBridge->linkJobToOrder($custJobId, $custOrder->get_id(), (int) $custItemId);
    $wcBridge->onPaymentComplete($custOrder->get_id());
    $afterCustPay = $jobRepo->findById($custJobId, false);
    $assert($afterCustPay['status'] === 'submitted_for_review', 'Custom Design job routes to submitted_for_review after WC payment');

    // Shop Manager uploads Designer Proof v1
    wp_set_current_user($mgrId);
    $proofReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/proof");
    $proofReq->set_param('note_en', 'Proof v1 ready for your inspection');
    $proofReq->set_param('note_fa', 'پیش‌نمایش نسخه ۱ آماده بررسی شماست');
    $proofReq->set_file_params(['file' => ['name' => 'proof-v1.png', 'type' => 'image/png', 'tmp_name' => $tmpPngSmall, 'error' => 0, 'size' => filesize($tmpPngSmall)]]);
    $proofRes = rest_do_request($proofReq);
    $assert($proofRes->get_status() === 201 && $proofRes->get_data()['data']['job']['status'] === 'proof_sent', 'shop_manager uploads designer proof -> status proof_sent');

    // Customer A requests revision on Proof v1
    wp_set_current_user($custAId);
    $revReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$custUuid}/request-revision");
    $revReq->set_header('Content-Type', 'application/json');
    $revReq->set_body(wp_json_encode(['comment' => 'Please enlarge the center logo by 10%']));
    $revRes = rest_do_request($revReq);
    $assert($revRes->get_status() === 200 && $revRes->get_data()['data']['status'] === 'revision_requested', 'Customer A requests proof revision -> revision_requested');

    // Customer A uploads revised 300 DPI artwork -> automatically moves back to in_design_review
    $revUpReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artwork");
    $revUpReq->set_file_params(['file' => ['name' => 'print-ready-v2.png', 'type' => 'image/png', 'tmp_name' => $tmpHigh, 'error' => 0, 'size' => filesize($tmpHigh)]]);
    $revUpRes = rest_do_request($revUpReq);
    $assert($revUpRes->get_status() === 201 && $revUpRes->get_data()['data']['job']['status'] === 'in_design_review', 'Revised 300 DPI upload returns job to in_design_review');

    // Shop Manager uploads Proof v2
    wp_set_current_user($mgrId);
    $proof2Req = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/proof");
    $proof2Req->set_file_params(['file' => ['name' => 'proof-v2.png', 'type' => 'image/png', 'tmp_name' => $tmpPngSmall, 'error' => 0, 'size' => filesize($tmpPngSmall)]]);
    $proof2Res = rest_do_request($proof2Req);
    $assert($proof2Res->get_status() === 201 && $proof2Res->get_data()['data']['job']['status'] === 'proof_sent', 'shop_manager uploads Proof v2 -> proof_sent');

    // Track any newly added vault files
    foreach ($jobRepo->listArtworksForJob($custJobId) as $artRow) {
        $storedVaultPaths[] = (string) $artRow['storage_rel_path'];
    }

    // Customer A approves Proof v2 -> approved_for_production (locks approved_proof_id & approved_at)
    wp_set_current_user($custAId);
    $appReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$custUuid}/approve-proof");
    $appReq->set_header('Content-Type', 'application/json');
    $appReq->set_body(wp_json_encode(['comment' => 'Looks perfect, approved for printing!']));
    $appRes = rest_do_request($appReq);
    $appData = $appRes->get_data()['data'];
    $assert($appRes->get_status() === 200 && $appData['status'] === 'approved_for_production' && !empty($appData['approved_proof_id']) && !empty($appData['approved_at']), 'Customer A approves proof -> locked approved_proof_id + approved_at + approved_for_production');

    // Shop Manager progresses through production -> QC -> packed -> shipped (with tracking + internal_notes) -> completed
    wp_set_current_user($mgrId);
    $stages = [
        ['to_status' => 'queued_for_production', 'comment' => 'Queued in print lab'],
        ['to_status' => 'in_production', 'comment' => 'Printing & laminating'],
        ['to_status' => 'quality_check', 'comment' => 'Color & cut QC passed'],
        ['to_status' => 'packed', 'comment' => 'Protective tube packed'],
        ['to_status' => 'shipped', 'tracking_code' => 'IR-POST-998877', 'internal_notes' => 'PRIVATE INTERNAL STAFF NOTE', 'comment' => 'Dispatched via courier'],
        ['to_status' => 'completed', 'comment' => 'Delivered to customer'],
    ];
    foreach ($stages as $stage) {
        $tReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
        $tReq->set_header('Content-Type', 'application/json');
        $tReq->set_body(wp_json_encode($stage));
        $tRes = rest_do_request($tReq);
        $assert($tRes->get_status() === 200 && $tRes->get_data()['data']['status'] === $stage['to_status'], 'Production transition -> ' . $stage['to_status']);
    }

    // Verify WooCommerce HPOS order received shipping note with tracking code
    $wcNotes = wc_get_order_notes(['order_id' => $custOrder->get_id(), 'type' => 'customer']);
    $foundTrackingNote = false;
    foreach ($wcNotes as $note) {
        if (str_contains((string) $note->content, 'IR-POST-998877')) {
            $foundTrackingNote = true;
            break;
        }
    }
    $assert($foundTrackingNote, 'WooCommerce HPOS order received customer shipping note with tracking code IR-POST-998877');

    // Verify Customer A view NEVER leaks internal_notes or storage_rel_path
    wp_set_current_user($custAId);
    $finalCustRes = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$custUuid}"));
    $finalJson = wp_json_encode($finalCustRes->get_data());
    $assert(!str_contains((string) $finalJson, 'PRIVATE INTERNAL STAFF NOTE'), 'Customer job response never leaks internal_notes');
    $assert(!str_contains((string) $finalJson, 'storage_rel_path'), 'Customer job response never leaks storage_rel_path');

    // Verify shortcode renders commercial UI with zero engineering terms
    $shortcodeHtml = do_shortcode('[djgear_skin_configurator]');
    $assert(str_contains($shortcodeHtml, 'djgear-skin-configurator'), '[djgear_skin_configurator] shortcode renders');
    $assert(!str_contains(strtolower($shortcodeHtml), 'dieline') && !str_contains(strtolower($shortcodeHtml), 'engineering'), 'Customer configurator contains zero engineering/dieline terminology');

    echo "\nSUMMARY: {$passed} assertions passed.\n";
} finally {
    // Cleanup ALL QA fixtures deterministically
    wp_set_current_user(0);
    $vault = new CustomerArtworkVault();
    foreach (array_unique($storedVaultPaths) as $relPath) {
        $vault->deleteFile($relPath);
    }
    foreach ($tempFiles as $tmpFile) {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }
    if (function_exists('djgear_core')) {
        $core = djgear_core();
        /** @var SkinJobRepository $jobRepo */
        $jobRepo = $core->container()->get(SkinJobRepository::class);
        foreach ($createdJobs as $jid) {
            $jobRepo->purgeJob($jid);
        }
        /** @var SkinVariantRepository $variantRepo */
        $variantRepo = $core->container()->get(SkinVariantRepository::class);
        foreach ($createdVariants as $vid) {
            try {
                $variantRepo->delete($vid);
            } catch (Throwable) {
            }
        }
        /** @var SkinPlanRepository $planRepo */
        $planRepo = $core->container()->get(SkinPlanRepository::class);
        foreach ($createdPlans as $pid) {
            try {
                $planRepo->delete($pid);
            } catch (Throwable) {
            }
        }
        if ($createdDeviceId !== null) {
            /** @var DeviceRepository $deviceRepo */
            $deviceRepo = $core->container()->get(DeviceRepository::class);
            try {
                $deviceRepo->delete($createdDeviceId);
            } catch (Throwable) {
            }
        }
        /** @var TaxonomyRepository $taxRepo */
        $taxRepo = $core->container()->get(TaxonomyRepository::class);
        if ($createdBrandId !== null) {
            try {
                $taxRepo->deleteBrand($createdBrandId);
            } catch (Throwable) {
            }
        }
        if ($createdCategoryId !== null) {
            try {
                $taxRepo->deleteCategory($createdCategoryId);
            } catch (Throwable) {
            }
        }
    }
    foreach ($createdOrders as $oid) {
        $o = wc_get_order($oid);
        if ($o instanceof WC_Order) {
            $o->delete(true);
        }
    }
    foreach ($createdProducts as $pid) {
        wp_delete_post($pid, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($createdUsers as $uid) {
        wp_delete_user($uid);
    }
}
