<?php
/**
 * Live WordPress 7.1 + WooCommerce 11.3 HPOS Integration & Security Hardening Test Suite
 * for djgear-custom-skins (Phase 4).
 *
 * Run via:
 *   wp eval-file wp-content/plugins/djgear-custom-skins/tests/Integration/wp-custom-skins-integration-test.php
 */

use DJGear\Core\Rest\RestKernel;
use DJGear\CustomSkins\CustomSkinsModule;
use DJGear\CustomSkins\Domain\SkinValidationException;
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
$createdJobUuids = [];

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

    // 4. Verify default seeded plans
    /** @var SkinPlanRepository $planRepo */
    $planRepo = $core->container()->get(SkinPlanRepository::class);
    $standardPlan = $planRepo->findBySlug('standard');
    $customPlan = $planRepo->findBySlug('custom-design');
    $assert($standardPlan !== null && $standardPlan['allows_artwork_upload'] === false, 'Default Standard plan seeded with allows_artwork_upload=false');
    $assert($customPlan !== null && $customPlan['allows_artwork_upload'] === true && $customPlan['requires_proof_approval'] === true, 'Default Custom Design plan seeded with allows_artwork_upload=true and proof approval');

    // 5. Verify capabilities matrix
    $adminRole = get_role('administrator');
    $mgrRole = get_role('shop_manager');
    $assert($adminRole !== null && $adminRole->has_cap(SkinCapabilities::MANAGE_SKIN_PLANS), 'administrator has djgear_manage_skin_plans');
    $assert($mgrRole !== null && $mgrRole->has_cap(SkinCapabilities::MANAGE_SKIN_CATALOG), 'shop_manager has djgear_manage_skin_catalog');
    $assert($mgrRole !== null && $mgrRole->has_cap(SkinCapabilities::MANAGE_SKIN_ORDERS), 'shop_manager has djgear_manage_skin_orders');
    $assert($mgrRole !== null && !$mgrRole->has_cap(SkinCapabilities::MANAGE_SKIN_PLANS), 'shop_manager does NOT have djgear_manage_skin_plans');

    // 6. Create QA users
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

    // 7. Create QA Catalog Device + Regular Skin Product + Sale Skin Product + Ordinary Product
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

    // On-sale skin product (regular 1,500,000 -> sale 1,200,000) to verify sale price respected + idempotency
    $saleProduct = new WC_Product_Simple();
    $saleProduct->set_name('QA Sale DJ Skin Base Product');
    $saleProduct->set_status('publish');
    $saleProduct->set_regular_price('1500000');
    $saleProduct->set_sale_price('1200000');
    $saleProduct->set_price('1200000');
    $saleProduct->set_sku('QA-SKIN-SALE-' . time());
    $wcSaleProductId = (int) $saleProduct->save();
    $createdProducts[] = $wcSaleProductId;

    // Ordinary non-skin WooCommerce product for mixed-order tests
    $ordinaryProduct = new WC_Product_Simple();
    $ordinaryProduct->set_name('QA DJ USB-C Chroma Cable');
    $ordinaryProduct->set_status('publish');
    $ordinaryProduct->set_regular_price('450000');
    $ordinaryProduct->set_price('450000');
    $ordinaryProduct->set_sku('QA-ORDINARY-' . time());
    $wcOrdinaryProductId = (int) $ordinaryProduct->save();
    $createdProducts[] = $wcOrdinaryProductId;

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

    // 8. Admin & Shop Manager RBAC via REST (including tightened GET /skins/admin/plans = 403 for shop_manager)
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
    $variantId = (int) $res->get_data()['data']['id'];
    $createdVariants[] = $variantId;

    // Second variant linked to on-sale product
    $reqSale = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/variants');
    $reqSale->set_header('Content-Type', 'application/json');
    $reqSale->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'wc_product_id'   => $wcSaleProductId,
        'model_code'      => 'club-pro',
        'model_name_fa'   => 'کلاب پرو',
        'model_name_en'   => 'Club Pro',
        'color_code'      => 'crimson-red',
        'color_name_fa'   => 'قرمز تیره',
        'color_name_en'   => 'Crimson Red',
        'color_hex'       => '#B91C1C',
        'finish'          => 'matte',
        'print_width_mm'  => 254.0,
        'print_height_mm' => 254.0,
        'status'          => 'active',
    ]));
    $resSale = rest_do_request($reqSale);
    $assert($resSale->get_status() === 201, 'shop_manager created sale-priced skin variant');
    $saleVariantId = (int) $resSale->get_data()['data']['id'];
    $createdVariants[] = $saleVariantId;

    // Hardening Section 6: shop_manager blocked on BOTH GET and POST /skins/admin/plans (1:1 with dashboard)
    $mgrGetPlans = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/plans'));
    $assert($mgrGetPlans->get_status() === 403, 'shop_manager blocked from GET /skins/admin/plans (HTTP 403 — consistent with dashboard)');

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

    // Administrator creates a Custom-Design plan with `max_revisions = 0` to verify ZERO revisions enforcement!
    wp_set_current_user($adminId);
    $adminGetPlans = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/plans'));
    $assert($adminGetPlans->get_status() === 200, 'administrator allowed GET /skins/admin/plans (HTTP 200)');

    $zeroRevReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/admin/plans');
    $zeroRevReq->set_header('Content-Type', 'application/json');
    $zeroRevReq->set_body(wp_json_encode([
        'slug'                    => 'qa-zero-rev-plan-' . time(),
        'name_fa'                 => 'پلن بدون اصلاح تست',
        'name_en'                 => 'QA Zero-Revision Custom Plan',
        'plan_type'               => 'custom_design',
        'allows_artwork_upload'   => true,
        'requires_artwork'        => true,
        'requires_proof_approval' => true,
        'max_revisions'           => 0,
        'price_surcharge'         => 150000,
    ]));
    $zeroRevRes = rest_do_request($zeroRevReq);
    $assert($zeroRevRes->get_status() === 201 && (int) $zeroRevRes->get_data()['data']['max_revisions'] === 0, 'administrator created Custom-Design plan with max_revisions=0');
    $zeroRevPlanId = (int) $zeroRevRes->get_data()['data']['id'];
    $createdPlans[] = $zeroRevPlanId;

    // 9. Public /skins/options & Anonymous 401 checks
    wp_set_current_user(0);
    $pubReq = new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/options');
    $pubReq->set_param('device_id', $createdDeviceId);
    $pubRes = rest_do_request($pubReq);
    $assert($pubRes->get_status() === 200, 'Public GET /djgear/v1/skins/options returns HTTP 200');
    $pubData = $pubRes->get_data()['data'];
    $assert(count($pubData['variants']) === 2 && (float) $pubData['variants'][0]['base_price'] === 1500000.0, 'Public options returns active variants with live WooCommerce base prices');
    $assert(count($pubData['plans']) >= 2, 'Public options returns active ordering plans');

    $anonJobRes = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs'));
    $assert($anonJobRes->get_status() === 401, 'Anonymous POST /djgear/v1/skins/jobs blocked with HTTP 401');

    $anonMyRes = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . '/skins/my-jobs'));
    $assert($anonMyRes->get_status() === 401, 'Anonymous GET /djgear/v1/skins/my-jobs blocked with HTTP 401');

    /** @var SkinJobRepository $jobRepo */
    $jobRepo = $core->container()->get(SkinJobRepository::class);
    /** @var WooCommerceSkinBridge $wcBridge */
    $wcBridge = $core->container()->get(WooCommerceSkinBridge::class);
    $vault = new CustomerArtworkVault();

    // 10. HARDENING SECTION 1: Idempotent Surcharge Pricing, Sale Price, Qty=1 Lock, Duplicate Cart Dedup, Post-Cart Swap Block
    wp_set_current_user($custAId);
    $saleCustomJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $saleCustomJobReq->set_header('Content-Type', 'application/json');
    $saleCustomJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $saleVariantId, // Sale price 1,200,000 + custom surcharge 250,000 = 1,450,000
        'plan_id'         => (int) $customPlan['id'],
    ]));
    $saleCustomRes = rest_do_request($saleCustomJobReq);
    $saleCustomUuid = (string) $saleCustomRes->get_data()['data']['job_uuid'];
    $saleCustomJob = $jobRepo->findByUuid($saleCustomUuid, false);
    $createdJobs[] = (int) $saleCustomJob['id'];

    $tmpHigh = sys_get_temp_dir() . '/qa_high_dpi_' . time() . '.png';
    $makePng($tmpHigh, 3000, 3000);
    $tempFiles[] = $tmpHigh;

    $tmpLow = sys_get_temp_dir() . '/qa_low_dpi_' . time() . '.png';
    $makePng($tmpLow, 1000, 1000);
    $tempFiles[] = $tmpLow;

    $tmpWarn = sys_get_temp_dir() . '/qa_warn_dpi_' . time() . '.png';
    $makePng($tmpWarn, 2000, 2000);
    $tempFiles[] = $tmpWarn;

    $tmpPngSmall = sys_get_temp_dir() . '/qa_small_' . time() . '.png';
    $makePng($tmpPngSmall, 200, 200);
    $tempFiles[] = $tmpPngSmall;

    // Upload 300 DPI PNG to saleCustomJob so it can be added to cart
    $saleUpReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$saleCustomUuid}/artwork");
    $saleUpReq->set_file_params(['file' => ['name' => 'sale-300dpi.png', 'type' => 'image/png', 'tmp_name' => $tmpHigh, 'error' => 0, 'size' => filesize($tmpHigh)]]);
    $saleUpRes = rest_do_request($saleUpReq);
    $assert($saleUpRes->get_status() === 201, 'Uploaded 300 DPI PNG for cart pricing & checkout boundary tests');

    // Ensure WC Cart initialized cleanly
    if (function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    WC()->cart->empty_cart();

    // Call add-to-cart TWICE -> must deduplicate to same cart_item_key and qty = 1
    $cartAdd1 = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$saleCustomUuid}/add-to-cart"));
    $cartAdd2 = rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$saleCustomUuid}/add-to-cart"));
    $key1 = (string) $cartAdd1->get_data()['data']['cart_item_key'];
    $key2 = (string) $cartAdd2->get_data()['data']['cart_item_key'];
    $assert($key1 !== '' && $key1 === $key2, 'Duplicate add-to-cart for same job_uuid deduplicates to identical cart_item_key');
    $assert((int) WC()->cart->get_cart_item($key1)['quantity'] === 1, 'Skin job cart item quantity stays locked at 1');

    // Repeated calculate_totals() (5x) must NEVER compound surcharge and MUST respect sale_price (1,200,000 + 250,000 = 1,450,000)
    for ($i = 0; $i < 5; $i++) {
        WC()->cart->calculate_totals();
    }
    $cartItemObj = WC()->cart->get_cart_item($key1);
    $computedUnitPrice = (float) $cartItemObj['data']->get_price();
    $assert($computedUnitPrice === 1450000.0, 'Repeated calculate_totals() respects sale price (1,200,000) + surcharge (250,000) = 1,450,000 with ZERO compounding');

    // Quantity manipulation to qty=2 must be blocked by validateCartQuantityUpdate() AND clamped by clampCartItemQuantity()
    $qtyValid = $wcBridge->validateCartQuantityUpdate(true, $key1, $cartItemObj, 2);
    $assert($qtyValid === false, 'Cart quantity update to 2 rejected by validateCartQuantityUpdate()');
    WC()->cart->set_quantity($key1, 2, false);
    $wcBridge->clampCartItemQuantity($key1, 2, 1, WC()->cart);
    $assert((int) WC()->cart->get_cart_item($key1)['quantity'] === 1, 'Programmatic quantity > 1 clamped back to 1');

    // Post-cart artwork swap attack: replace 300 DPI with 100 DPI rejected file while item is in cart -> checkout validation MUST reject!
    $swapReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$saleCustomUuid}/artwork");
    $swapReq->set_file_params(['file' => ['name' => 'swap-low-100dpi.png', 'type' => 'image/png', 'tmp_name' => $tmpLow, 'error' => 0, 'size' => filesize($tmpLow)]]);
    rest_do_request($swapReq);
    wc_clear_notices();
    $wcBridge->validateCartSkinJobsForCheckout();
    $assert(wc_notice_count('error') > 0, 'Post-cart swap to <180 DPI artwork is caught and blocked at final checkout boundary');
    wc_clear_notices();
    WC()->cart->empty_cart();

    foreach ($jobRepo->listArtworksForJob((int) $saleCustomJob['id']) as $artRow) {
        $storedVaultPaths[] = (string) $artRow['storage_rel_path'];
    }

    // 11. HARDENING SECTION 2: PDF Inspection (Never Silent Pass), Multi-Page TIFF, Stale Ack & Digest Binding
    $pdfJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $pdfJobReq->set_header('Content-Type', 'application/json');
    $pdfJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $variantId,
        'plan_id'         => (int) $customPlan['id'],
    ]));
    $pdfJobUuid = (string) rest_do_request($pdfJobReq)->get_data()['data']['job_uuid'];
    $pdfJobId = (int) $jobRepo->findByUuid($pdfJobUuid, false)['id'];
    $createdJobs[] = $pdfJobId;

    // 11a. Low-res raster wrapped inside PDF -> REJECTED (cannot bypass DPI with PDF!)
    $tmpLowPdf = sys_get_temp_dir() . '/qa_lowres_' . time() . '.pdf';
    file_put_contents($tmpLowPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 1 >> endobj\n2 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n3 0 obj << /Type /XObject /Subtype /Image /Width 900 /Height 900 >> endobj\n%%EOF");
    $tempFiles[] = $tmpLowPdf;
    $lowPdfReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/artwork");
    $lowPdfReq->set_file_params(['file' => ['name' => 'sneaky-lowres.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpLowPdf, 'error' => 0, 'size' => filesize($tmpLowPdf)]]);
    $lowPdfRes = rest_do_request($lowPdfReq);
    $assert($lowPdfRes->get_status() === 201 && $lowPdfRes->get_data()['data']['dpi_report']['dpi_status'] === ArtworkDpiValidator::STATUS_REJECTED, 'Low-res raster embedded in PDF rejected (90 DPI)');
    $assert(rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/add-to-cart"))->get_status() === 400, 'Low-res PDF blocked from checkout (HTTP 400)');

    // 11b. Pure vector PDF -> manual_review_required (NEVER silent pass!), requires revision+SHA256 ack
    $tmpVecPdf = sys_get_temp_dir() . '/qa_vector_' . time() . '.pdf';
    file_put_contents($tmpVecPdf, "%PDF-1.4\n1 0 obj << /Type /Pages /Count 1 >> endobj\n2 0 obj << /Type /Page /MediaBox [0 0 720 720] >> endobj\n%%EOF");
    $tempFiles[] = $tmpVecPdf;
    $vecPdfReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/artwork");
    $vecPdfReq->set_file_params(['file' => ['name' => 'vector-logo.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpVecPdf, 'error' => 0, 'size' => filesize($tmpVecPdf)]]);
    $vecPdfRes = rest_do_request($vecPdfReq);
    $vecArtwork = $vecPdfRes->get_data()['data']['artwork'];
    $assert($vecPdfRes->get_data()['data']['dpi_report']['dpi_status'] === ArtworkDpiValidator::STATUS_MANUAL_REVIEW_REQUIRED, 'Unmeasured vector PDF marked manual_review_required (never silent pass)');
    $assert(rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/add-to-cart"))->get_status() === 400, 'Manual-review PDF blocked from checkout before explicit acknowledgment');

    // Wrong SHA-256 on ack -> blocked 400; exact revision + SHA-256 -> 200
    $badShaAck = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/acknowledge-dpi");
    $badShaAck->set_header('Content-Type', 'application/json');
    $badShaAck->set_body(wp_json_encode(['acknowledged' => true, 'artwork_id' => (int) $vecArtwork['id'], 'sha256_hash' => str_repeat('0', 64)]));
    $assert(rest_do_request($badShaAck)->get_status() === 400, 'Acknowledgment with mismatched SHA-256 digest rejected (HTTP 400)');

    $goodPdfAck = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/acknowledge-dpi");
    $goodPdfAck->set_header('Content-Type', 'application/json');
    $goodPdfAck->set_body(wp_json_encode(['acknowledged' => true, 'artwork_id' => (int) $vecArtwork['id'], 'sha256_hash' => (string) $vecArtwork['sha256_hash']]));
    $assert(rest_do_request($goodPdfAck)->get_status() === 200, 'Manual-review PDF acknowledged with exact revision ID + SHA-256 (HTTP 200)');
    $assert(rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/add-to-cart"))->get_status() === 200, 'Acknowledged manual-review PDF allowed to proceed on proof-reviewed plan');

    // 11c. Replacing artwork MUST invalidate prior acknowledgment AND stale artwork_id must be rejected!
    $replaceWarnReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/artwork");
    $replaceWarnReq->set_file_params(['file' => ['name' => 'warn-v2.png', 'type' => 'image/png', 'tmp_name' => $tmpWarn, 'error' => 0, 'size' => filesize($tmpWarn)]]);
    $replaceWarnRes = rest_do_request($replaceWarnReq);
    $warnV2 = $replaceWarnRes->get_data()['data']['artwork'];
    $assert(rest_do_request(new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/add-to-cart"))->get_status() === 400, 'Replacing artwork invalidates prior acknowledgment and re-blocks checkout');

    $staleAck = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/acknowledge-dpi");
    $staleAck->set_header('Content-Type', 'application/json');
    $staleAck->set_body(wp_json_encode(['acknowledged' => true, 'artwork_id' => (int) $vecArtwork['id']]));
    $assert(rest_do_request($staleAck)->get_status() === 400, 'Stale artwork_id acknowledgment strictly rejected (HTTP 400)');

    // 11d. Multi-page TIFF upload rejected HTTP 400
    $tmpMultiTiff = sys_get_temp_dir() . '/qa_multipage_' . time() . '.tif';
    file_put_contents($tmpMultiTiff, 'II' . pack('vV', 42, 8) . pack('vV', 0, 14) . pack('vV', 0, 0));
    $tempFiles[] = $tmpMultiTiff;
    $tiffReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$pdfJobUuid}/artwork");
    $tiffReq->set_file_params(['file' => ['name' => 'multi.tif', 'type' => 'image/tiff', 'tmp_name' => $tmpMultiTiff, 'error' => 0, 'size' => filesize($tmpMultiTiff)]]);
    $assert(rest_do_request($tiffReq)->get_status() === 400, 'Multi-page TIFF upload rejected (HTTP 400)');

    foreach ($jobRepo->listArtworksForJob($pdfJobId) as $artRow) {
        $storedVaultPaths[] = (string) $artRow['storage_rel_path'];
    }

    // 12. HARDENING SECTIONS 1, 4 & 5: MIXED MULTI-SKIN HPOS ORDER (2 Skin Jobs + 1 Ordinary Product)
    //     + Zero-ID Guard + Duplicate Webhook Idempotency + Shipping Without WC Auto-Complete
    wp_set_current_user($custAId);
    $stdJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $stdJobReq->set_header('Content-Type', 'application/json');
    $stdJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $variantId,
        'plan_id'         => (int) $standardPlan['id'],
    ]));
    $stdUuid = (string) rest_do_request($stdJobReq)->get_data()['data']['job_uuid'];
    $stdJobRecord = $jobRepo->findByUuid($stdUuid, false);
    $stdJobId = (int) $stdJobRecord['id'];
    $createdJobs[] = $stdJobId;

    // Standard plan strictly blocks artwork upload
    $stdUpReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$stdUuid}/artwork");
    $stdUpReq->set_file_params(['file' => ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $tmpPngSmall, 'error' => 0, 'size' => filesize($tmpPngSmall)]]);
    $assert(rest_do_request($stdUpReq)->get_status() === 400, 'Artwork upload on Standard plan is strictly blocked (HTTP 400)');

    // Second job in same order: Custom Design with 300 DPI PNG
    $custJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $custJobReq->set_header('Content-Type', 'application/json');
    $custJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $variantId,
        'plan_id'         => (int) $customPlan['id'],
        'customer_notes'  => 'Center my DJ logo cleanly',
    ]));
    $custUuid = (string) rest_do_request($custJobReq)->get_data()['data']['job_uuid'];
    $custJobRecord = $jobRepo->findByUuid($custUuid, false);
    $custJobId = (int) $custJobRecord['id'];
    $createdJobs[] = $custJobId;

    $highUpReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artwork");
    $highUpReq->set_file_params(['file' => ['name' => 'print-ready-300dpi.png', 'type' => 'image/png', 'tmp_name' => $tmpHigh, 'error' => 0, 'size' => filesize($tmpHigh)]]);
    $highUpRes = rest_do_request($highUpReq);
    $assert($highUpRes->get_status() === 201 && $highUpRes->get_data()['data']['dpi_report']['dpi_status'] === ArtworkDpiValidator::STATUS_PASS, '300 DPI artwork passes immediately');
    $highArtworkId = (int) $highUpRes->get_data()['data']['artwork']['id'];

    // Hardening Section 3: Complete REST response inspection for ZERO filesystem path leakage!
    $aDl = rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artworks/{$highArtworkId}/download"));
    $dlJson = (string) wp_json_encode($aDl->get_data());
    $headers = $aDl->get_headers();
    $assert($aDl->get_status() === 200, 'Customer A can download own artwork via authenticated proxy (HTTP 200)');
    $assert(!str_contains($dlJson, '_stream_path') && !str_contains($dlJson, '/var/www/html') && !str_contains($dlJson, 'djgear-private') && !str_contains($dlJson, 'storage_rel_path'), 'Download REST payload leaks ZERO server filesystem paths or private metadata');
    $assert(($headers['X-Content-Type-Options'] ?? '') === 'nosniff', 'Download proxy sets X-Content-Type-Options: nosniff');
    $assert(str_contains((string) ($headers['Cache-Control'] ?? ''), 'no-store'), 'Download proxy sets Cache-Control: private, no-store');

    // Cross-customer isolation
    wp_set_current_user($custBId);
    $assert(rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$custUuid}"))->get_status() === 403, 'Customer B blocked from viewing Customer A job (HTTP 403)');
    $assert(rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/jobs/{$custUuid}/artworks/{$highArtworkId}/download"))->get_status() === 403, 'Customer B blocked from downloading Customer A artwork (HTTP 403)');

    // Zero-ID binding guard test
    $zeroIdBlocked = false;
    try {
        $wcBridge->linkJobToOrder($stdJobId, 0, 0);
    } catch (SkinValidationException $e) {
        $zeroIdBlocked = ($e->statusCode === 400);
    }
    $assert($zeroIdBlocked, 'linkJobToOrder strictly rejects zero order_id / order_item_id');

    // Build ONE mixed HPOS WooCommerce Order containing: Skin Job #1 (Standard) + Skin Job #2 (Custom Design) + Ordinary Product (qty 2)
    $mixedOrder = wc_create_order(['customer_id' => $custAId]);
    $itemStdId = $mixedOrder->add_product(wc_get_product($wcProductId), 1);
    $itemStd = $mixedOrder->get_item($itemStdId);
    $wcBridge->attachJobMetadataToOrderItem($itemStd, $stdJobRecord);
    $itemStd->save();

    $itemCustId = $mixedOrder->add_product(wc_get_product($wcProductId), 1);
    $itemCust = $mixedOrder->get_item($itemCustId);
    $wcBridge->attachJobMetadataToOrderItem($itemCust, $custJobRecord);
    $itemCust->save();

    $mixedOrder->add_product(wc_get_product($wcOrdinaryProductId), 2);
    $mixedOrder->set_status('pending');
    $mixedOrder->calculate_totals();
    $mixedOrder->save();
    $createdOrders[] = $mixedOrder->get_id();

    // Post-save binding via bindPersistedOrderJobs (identical to classic checkout_order_created & Blocks checkout_order_processed)
    $wcBridge->bindPersistedOrderJobs($mixedOrder);
    $stdBound = $jobRepo->findById($stdJobId, false);
    $custBound = $jobRepo->findById($custJobId, false);
    $assert(
        (int) $stdBound['wc_order_id'] === $mixedOrder->get_id()
        && (int) $custBound['wc_order_id'] === $mixedOrder->get_id()
        && (int) $stdBound['wc_order_item_id'] > 0
        && (int) $custBound['wc_order_item_id'] > 0
        && (int) $stdBound['wc_order_item_id'] !== (int) $custBound['wc_order_item_id'],
        'Mixed order with 2 Skin Jobs + 1 ordinary product binds both jobs to distinct persisted order_item_ids (>0) without collision'
    );

    // Replay bindPersistedOrderJobs -> idempotent (no duplicate history)
    $stdHistBefore = count($jobRepo->listHistoryForJob($stdJobId));
    $wcBridge->bindPersistedOrderJobs($mixedOrder);
    $assert(count($jobRepo->listHistoryForJob($stdJobId)) === $stdHistBefore, 'Replaying bindPersistedOrderJobs is idempotent with zero duplicate history');

    // Attempting to re-bind an already-bound job to another order ID must throw 409 Conflict
    $rebindBlocked = false;
    try {
        $wcBridge->linkJobToOrder($stdJobId, $mixedOrder->get_id() + 99999, 999999);
    } catch (SkinValidationException $e) {
        $rebindBlocked = ($e->statusCode === 409);
    }
    $assert($rebindBlocked, 'Re-binding already-bound Skin Job to another order is blocked (HTTP 409)');

    // Move WC Order to processing + fire BOTH payment hooks + replay -> ZERO duplicate transitions/history!
    $mixedOrder->set_status('processing');
    $mixedOrder->save();
    $wcBridge->onPaymentComplete($mixedOrder->get_id());
    $wcBridge->onOrderProcessing($mixedOrder->get_id());
    $wcBridge->onPaymentComplete($mixedOrder->get_id());

    $stdAfterPay = $jobRepo->findById($stdJobId, true);
    $custAfterPay = $jobRepo->findById($custJobId, true);
    $assert($stdAfterPay['status'] === 'queued_for_production', 'Standard job in mixed order routes directly to queued_for_production');
    $assert($custAfterPay['status'] === 'submitted_for_review', 'Custom Design job in mixed order routes to submitted_for_review');
    $stdPayHistoryMatches = array_filter($stdAfterPay['status_history'], static fn ($h) => $h['to_status'] === 'queued_for_production');
    $custPayHistoryMatches = array_filter($custAfterPay['status_history'], static fn ($h) => $h['to_status'] === 'submitted_for_review');
    $assert(count($stdPayHistoryMatches) === 1 && count($custPayHistoryMatches) === 1, 'Duplicate payment webhooks create exactly ONE transition and ZERO duplicate history rows');

    // 13. HARDENING SECTION 4: Forbidden Bypass Tests + Proof SHA-256 Tamper Test + max_revisions=0 + Concurrency CAS
    wp_set_current_user($mgrId);

    // 13a. Staff tries `submitted_for_review -> on_hold -> queued_for_production` WITHOUT customer approval -> MUST FAIL 400!
    $holdReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
    $holdReq->set_header('Content-Type', 'application/json');
    $holdReq->set_body(wp_json_encode(['to_status' => 'on_hold', 'comment' => 'Testing hold gate']));
    $assert(rest_do_request($holdReq)->get_status() === 200, 'Moved Custom Design job to on_hold for bypass test');

    $bypassQueueReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
    $bypassQueueReq->set_header('Content-Type', 'application/json');
    $bypassQueueReq->set_body(wp_json_encode(['to_status' => 'queued_for_production']));
    $assert(rest_do_request($bypassQueueReq)->get_status() === 400, 'Custom Design job on_hold BLOCKED from queued_for_production without approved proof (HTTP 400)');

    $bypassProdReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
    $bypassProdReq->set_header('Content-Type', 'application/json');
    $bypassProdReq->set_body(wp_json_encode(['to_status' => 'in_production']));
    $assert(rest_do_request($bypassProdReq)->get_status() === 400, 'Custom Design job on_hold BLOCKED from in_production without approved proof (HTTP 400)');

    // Resume back to submitted_for_review and upload Designer Proof v1
    $resumeReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
    $resumeReq->set_header('Content-Type', 'application/json');
    $resumeReq->set_body(wp_json_encode(['to_status' => 'submitted_for_review']));
    $assert(rest_do_request($resumeReq)->get_status() === 200, 'Resumed Custom Design job to submitted_for_review');

    $proofReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/proof");
    $proofReq->set_file_params(['file' => ['name' => 'proof-v1.png', 'type' => 'image/png', 'tmp_name' => $tmpPngSmall, 'error' => 0, 'size' => filesize($tmpPngSmall)]]);
    $proofRes = rest_do_request($proofReq);
    $assert($proofRes->get_status() === 201 && $proofRes->get_data()['data']['job']['status'] === 'proof_sent', 'shop_manager uploaded Proof v1 -> proof_sent');

    // 13b. Staff/Admin tries to impersonate customer approval (`to_status = approved_for_production`) -> MUST FAIL 403!
    $impersonateReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
    $impersonateReq->set_header('Content-Type', 'application/json');
    $impersonateReq->set_body(wp_json_encode(['to_status' => 'approved_for_production']));
    $assert(rest_do_request($impersonateReq)->get_status() === 403, 'shop_manager BLOCKED from impersonating customer proof approval (HTTP 403)');

    wp_set_current_user($adminId);
    $assert(rest_do_request($impersonateReq)->get_status() === 403, 'administrator ALSO BLOCKED from impersonating customer proof approval (HTTP 403)');

    // Customer A approves Proof v1 -> locked + repeated approval blocked with zero duplicate history
    wp_set_current_user($custAId);
    $appReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$custUuid}/approve-proof");
    $appReq->set_header('Content-Type', 'application/json');
    $appReq->set_body(wp_json_encode(['comment' => 'Approved for print']));
    $appRes = rest_do_request($appReq);
    $assert($appRes->get_status() === 200 && $appRes->get_data()['data']['status'] === 'approved_for_production', 'Customer A approved Proof v1 -> approved_for_production');

    $histCountAfterApprove = count($jobRepo->listHistoryForJob($custJobId));
    $dupAppRes = rest_do_request($appReq);
    $assert($dupAppRes->get_status() === 400 && count($jobRepo->listHistoryForJob($custJobId)) === $histCountAfterApprove, 'Repeated proof approval blocked cleanly with zero duplicate history rows');

    // 13c. HARDENING SECTION 2.7 + FINAL AUDIT-LOG PERSISTENCE FIX:
    //      Verify modified AND missing approved proof files block production (HTTP 409),
    //      roll back the transaction cleanly, AND persist `skins.proof.integrity_failed`
    //      outside the rolled-back InnoDB transaction with retry deduplication.
    $createdJobUuids[] = $custUuid;
    $auditTable = $wpdb->prefix . 'djgear_audit_logs';
    $fetchIntegrityAuditRows = static function (string $jobUuid) use ($wpdb, $auditTable): array {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, action, resource_type, resource_id, actor_id, context_json
                 FROM {$auditTable}
                 WHERE action = %s AND resource_id = %s
                 ORDER BY id ASC",
                'skins.proof.integrity_failed',
                $jobUuid
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    };

    $assert(count($fetchIntegrityAuditRows($custUuid)) === 0, 'Zero proof integrity failure audit rows exist prior to tampering');

    $approvedProofId = (int) $jobRepo->findById($custJobId, false)['approved_proof_id'];
    $approvedProofRow = $jobRepo->findArtworkById($approvedProofId);
    $approvedDiskPath = $vault->resolveSafePath((string) $approvedProofRow['storage_rel_path']);
    $originalProofBytes = (string) file_get_contents($approvedDiskPath);
    file_put_contents($approvedDiskPath, $originalProofBytes . 'TAMPER');

    wp_set_current_user($mgrId);
    $tamperQueueReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$custJobId}/transition");
    $tamperQueueReq->set_header('Content-Type', 'application/json');
    $tamperQueueReq->set_body(wp_json_encode(['to_status' => 'queued_for_production']));
    $tamperQueueRes = rest_do_request($tamperQueueReq);
    $assert($tamperQueueRes->get_status() === 409, 'Modified approved proof file on disk BLOCKS transition to production (HTTP 409 SHA-256 mismatch)');
    $assert(
        $jobRepo->findById($custJobId, false)['status'] === 'approved_for_production'
        && count($jobRepo->listHistoryForJob($custJobId)) === $histCountAfterApprove,
        'Tampered proof failure rolls back status transition completely (job remains approved_for_production)'
    );

    $tamperAuditRows = $fetchIntegrityAuditRows($custUuid);
    $tamperCtx = count($tamperAuditRows) === 1 ? json_decode((string) $tamperAuditRows[0]['context_json'], true) : null;
    $assert(
        count($tamperAuditRows) === 1
        && is_array($tamperCtx)
        && ($tamperCtx['reason'] ?? '') === 'sha256_mismatch'
        && (int) ($tamperCtx['proof_id'] ?? 0) === $approvedProofId
        && !empty($tamperCtx['expected_hash'])
        && !empty($tamperCtx['actual_hash'])
        && $tamperCtx['expected_hash'] !== $tamperCtx['actual_hash'],
        'Modified approved proof persists skins.proof.integrity_failed (sha256_mismatch) in djgear_audit_logs after transaction rollback'
    );

    // Immediate retry must still return HTTP 409 without duplicating the audit log row
    $tamperRetryRes = rest_do_request($tamperQueueReq);
    $assert(
        $tamperRetryRes->get_status() === 409 && count($fetchIntegrityAuditRows($custUuid)) === 1,
        'Repeated tampered proof retry returns HTTP 409 and deduplicates skins.proof.integrity_failed audit log'
    );

    // Missing approved proof file scenario: move file away, assert 409 + rollback + persisted audit log + dedup
    $missingBackupPath = $approvedDiskPath . '.qa_missing_bak';
    $assert(rename($approvedDiskPath, $missingBackupPath), 'Temporarily moved approved proof file to simulate missing file');
    try {
        $missingRes = rest_do_request($tamperQueueReq);
        $assert($missingRes->get_status() === 409, 'Missing approved proof file on disk BLOCKS transition to production (HTTP 409)');
        $assert(
            $jobRepo->findById($custJobId, false)['status'] === 'approved_for_production'
            && count($jobRepo->listHistoryForJob($custJobId)) === $histCountAfterApprove,
            'Missing proof failure rolls back status transition completely (job remains approved_for_production)'
        );

        $missingAuditRows = $fetchIntegrityAuditRows($custUuid);
        $missingCtx = count($missingAuditRows) === 2 ? json_decode((string) $missingAuditRows[1]['context_json'], true) : null;
        $assert(
            count($missingAuditRows) === 2
            && is_array($missingCtx)
            && ($missingCtx['reason'] ?? '') === 'missing_or_inaccessible_file'
            && (int) ($missingCtx['proof_id'] ?? 0) === $approvedProofId,
            'Missing approved proof persists skins.proof.integrity_failed (missing_or_inaccessible_file) in djgear_audit_logs after transaction rollback'
        );

        $missingRetryRes = rest_do_request($tamperQueueReq);
        $assert(
            $missingRetryRes->get_status() === 409 && count($fetchIntegrityAuditRows($custUuid)) === 2,
            'Repeated missing proof retry returns HTTP 409 and deduplicates skins.proof.integrity_failed audit log'
        );
    } finally {
        if (is_file($missingBackupPath)) {
            rename($missingBackupPath, $approvedDiskPath);
        }
    }

    // Restore original proof bytes -> production entry succeeds with ZERO additional integrity failure audit events!
    file_put_contents($approvedDiskPath, $originalProofBytes);
    $assert(rest_do_request($tamperQueueReq)->get_status() === 200, 'Restored intact approved proof passes live SHA-256 re-verification -> queued_for_production');
    $assert(
        count($fetchIntegrityAuditRows($custUuid)) === 2,
        'Successful proof integrity verification generates ZERO additional skins.proof.integrity_failed audit rows'
    );

    // 13d. Concurrency CAS check: stale expectedFromStatus throws 409 Conflict
    $casConflict = false;
    try {
        $jobRepo->transitionStatus($custJobId, 'in_production', $mgrId, 'staff', true, 'CAS test', [], 'approved_for_production');
    } catch (SkinValidationException $e) {
        $casConflict = ($e->statusCode === 409);
    }
    $assert($casConflict, 'Concurrent/stale status transition rejected via atomic CAS check (HTTP 409)');

    // 13e. `max_revisions = 0` plan MUST strictly allow ZERO proof revisions
    wp_set_current_user($custAId);
    $zJobReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . '/skins/jobs');
    $zJobReq->set_header('Content-Type', 'application/json');
    $zJobReq->set_body(wp_json_encode([
        'device_id'       => $createdDeviceId,
        'skin_variant_id' => $variantId,
        'plan_id'         => $zeroRevPlanId,
    ]));
    $zUuid = (string) rest_do_request($zJobReq)->get_data()['data']['job_uuid'];
    $zJobId = (int) $jobRepo->findByUuid($zUuid, false)['id'];
    $createdJobs[] = $zJobId;

    $zOrder = wc_create_order(['customer_id' => $custAId]);
    $zItemId = $zOrder->add_product(wc_get_product($wcProductId), 1);
    $zOrder->save();
    $createdOrders[] = $zOrder->get_id();
    $wcBridge->linkJobToOrder($zJobId, $zOrder->get_id(), (int) $zItemId, $custAId);
    $wcBridge->onPaymentComplete($zOrder->get_id());

    wp_set_current_user($mgrId);
    $zProofReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$zJobId}/proof");
    $zProofReq->set_file_params(['file' => ['name' => 'z-proof.png', 'type' => 'image/png', 'tmp_name' => $tmpPngSmall, 'error' => 0, 'size' => filesize($tmpPngSmall)]]);
    $assert(rest_do_request($zProofReq)->get_status() === 201, 'Uploaded proof on max_revisions=0 job');

    wp_set_current_user($custAId);
    $zRevReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$zUuid}/request-revision");
    $zRevReq->set_header('Content-Type', 'application/json');
    $zRevReq->set_body(wp_json_encode(['comment' => 'Try to request a revision on a 0-revision plan']));
    $zRevRes = rest_do_request($zRevReq);
    $assert($zRevRes->get_status() === 400, 'max_revisions=0 plan strictly blocks even the first revision request (HTTP 400)');

    foreach ($jobRepo->listArtworksForJob($zJobId) as $artRow) {
        $storedVaultPaths[] = (string) $artRow['storage_rel_path'];
    }
    foreach ($jobRepo->listArtworksForJob($custJobId) as $artRow) {
        $storedVaultPaths[] = (string) $artRow['storage_rel_path'];
    }

    // 14. HARDENING SECTION 5: Ship & Complete Job #1 in Mixed Order while Job #2 stays in production
    //     Verify WC Order remains `processing` (NO auto-complete!) and receives tracking note.
    wp_set_current_user($mgrId);
    foreach ([
        ['to_status' => 'in_production'],
        ['to_status' => 'quality_check'],
        ['to_status' => 'packed'],
        ['to_status' => 'shipped', 'tracking_code' => 'IR-POST-MIX-7788', 'internal_notes' => 'PRIVATE INTERNAL STAFF NOTE'],
        ['to_status' => 'completed'],
    ] as $stage) {
        $tReq = new WP_REST_Request('POST', '/' . RestKernel::NAMESPACE_V1 . "/skins/admin/jobs/{$stdJobId}/transition");
        $tReq->set_header('Content-Type', 'application/json');
        $tReq->set_body(wp_json_encode($stage));
        $assert(rest_do_request($tReq)->get_status() === 200, 'Mixed-order Job #1 transition -> ' . $stage['to_status']);
    }

    $reloadedMixedOrder = wc_get_order($mixedOrder->get_id());
    $assert($reloadedMixedOrder instanceof WC_Order && $reloadedMixedOrder->get_status() === 'processing', 'Completing Job #1 in mixed order does NOT auto-complete WooCommerce order (remains processing)');
    $assert($jobRepo->findById($custJobId, false)['status'] === 'queued_for_production', 'Sibling Job #2 in mixed order remains independently unaffected in queued_for_production');

    $wcNotes = wc_get_order_notes(['order_id' => $mixedOrder->get_id(), 'type' => 'customer']);
    $foundTrackingNote = false;
    foreach ($wcNotes as $note) {
        if (str_contains((string) $note->content, 'IR-POST-MIX-7788')) {
            $foundTrackingNote = true;
            break;
        }
    }
    $assert($foundTrackingNote, 'WooCommerce HPOS mixed order received customer shipping note with tracking code IR-POST-MIX-7788');

    // Verify customer job payload NEVER leaks internal_notes or storage paths
    wp_set_current_user($custAId);
    $finalCustJson = (string) wp_json_encode(rest_do_request(new WP_REST_Request('GET', '/' . RestKernel::NAMESPACE_V1 . "/skins/my-jobs/{$stdUuid}"))->get_data());
    $assert(!str_contains($finalCustJson, 'PRIVATE INTERNAL STAFF NOTE') && !str_contains($finalCustJson, 'storage_rel_path'), 'Customer job response never leaks internal_notes or storage_rel_path');

    // Verify shortcode contains zero engineering terms
    $shortcodeHtml = do_shortcode('[djgear_skin_configurator]');
    $assert(str_contains($shortcodeHtml, 'djgear-skin-configurator') && !str_contains(strtolower($shortcodeHtml), 'dieline') && !str_contains(strtolower($shortcodeHtml), 'engineering'), 'Customer configurator renders with zero engineering/dieline terminology');

    echo "\nSUMMARY: {$passed} assertions passed.\n";
} finally {
    wp_set_current_user(0);
    if (function_exists('WC') && WC()->cart !== null) {
        WC()->cart->empty_cart();
    }
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
        $auditCleanupTable = $wpdb->prefix . 'djgear_audit_logs';
        foreach (array_unique($createdJobUuids) as $cleanupUuid) {
            $wpdb->delete($auditCleanupTable, ['resource_id' => $cleanupUuid], ['%s']);
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
