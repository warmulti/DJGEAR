<?php
/**
 * Live WordPress + WooCommerce integration test suite for djgear-device-catalog.
 *
 * Execute inside the WordPress container via:
 *   wp eval-file /var/www/html/wp-content/plugins/djgear-device-catalog/tests/Integration/wp-catalog-integration-test.php
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use DJGear\DeviceCatalog\Cache\CatalogCacheService;
use DJGear\DeviceCatalog\DeviceCatalogModule;
use DJGear\DeviceCatalog\Lifecycle\CatalogMigrationRunner;
use DJGear\DeviceCatalog\Lifecycle\Migrations\CreateCatalogTables;
use DJGear\DeviceCatalog\Repositories\CatalogDetailsRepository;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;
use DJGear\DeviceCatalog\Repositories\SkinDefinitionRepository;
use DJGear\DeviceCatalog\Repositories\TaxonomyRepository;

$passed = 0;
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException("[FAIL] {$label}");
    }
    $passed++;
    echo "[PASS] {$label}\n";
};

global $wpdb;

// 1. Module registration & WooCommerce HPOS declaration
$core = djgear_core();
$assert($core->modules()->has(DeviceCatalogModule::MODULE_ID), 'djgear-device-catalog registered in djgear-core');

if (class_exists(FeaturesUtil::class) && method_exists(FeaturesUtil::class, 'get_compatible_features_for_plugin')) {
    $compat = FeaturesUtil::get_compatible_features_for_plugin('djgear-device-catalog/djgear-device-catalog.php');
    $list = is_array($compat['compatible'] ?? null) ? $compat['compatible'] : [];
    $assert(in_array('custom_order_tables', $list, true), 'WooCommerce HPOS compatibility declared for djgear-device-catalog');
}

// 2. All 5 normalized MySQL tables exist + migration recorded
$p = $wpdb->prefix;
foreach ([
    CreateCatalogTables::TABLE_INDEX,
    CreateCatalogTables::TABLE_SPECS,
    CreateCatalogTables::TABLE_MEDIA,
    CreateCatalogTables::TABLE_COMPATIBILITY,
    CreateCatalogTables::TABLE_SKINS,
] as $suffix) {
    $tbl = $p . $suffix;
    $assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl)) === $tbl, "Table {$tbl} exists");
}
$migs = get_option(CatalogMigrationRunner::OPTION_KEY, []);
$assert(is_array($migs) && in_array('0001_create_catalog_tables', $migs, true), 'Migration 0001_create_catalog_tables recorded');

// 3. Unauthenticated checks: 401 on admin routes, 200 on public routes
wp_set_current_user(0);
foreach (['/djgear/v1/catalog/devices', '/djgear/v1/catalog/brands', '/djgear/v1/catalog/skins', '/djgear/v1/catalog/export'] as $r) {
    $assert(rest_do_request(new WP_REST_Request('GET', $r))->get_status() === 401, "Unauthenticated GET {$r} returns 401");
}
foreach (['/djgear/v1/catalog/public/devices', '/djgear/v1/catalog/public/brands', '/djgear/v1/catalog/public/categories', '/djgear/v1/catalog/public/filters'] as $r) {
    $assert(rest_do_request(new WP_REST_Request('GET', $r))->get_status() === 200, "Unauthenticated GET {$r} returns 200");
}

// 4. Subscriber -> 403 on all admin catalog endpoints
$subLogin = 'djgear_cat_sub_' . wp_generate_password(6, false);
$subId = wp_create_user($subLogin, wp_generate_password(16, true), $subLogin . '@djgear.local');
$assert(is_int($subId), 'Created temporary subscriber for catalog 403 check');
try {
    (new WP_User($subId))->set_role('subscriber');
    wp_set_current_user($subId);
    foreach (['/djgear/v1/catalog/devices', '/djgear/v1/catalog/brands', '/djgear/v1/catalog/skins', '/djgear/v1/catalog/export'] as $r) {
        $assert(rest_do_request(new WP_REST_Request('GET', $r))->get_status() === 403, "Subscriber GET {$r} returns 403");
    }
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subId);
    wp_set_current_user(0);
}

// Pre-clean any leftover QA records from an interrupted previous run
$qaSkins = $core->container()->get(SkinDefinitionRepository::class);
$qaDevs = $core->container()->get(DeviceRepository::class);
$qaTax = $core->container()->get(TaxonomyRepository::class);
foreach ($qaSkins->listAll() as $oldSkin) {
    if (str_starts_with((string) $oldSkin['template_code'], 'SKIN-QA-')) {
        $qaSkins->delete((int) $oldSkin['id']);
    }
}
foreach ($qaDevs->search(['per_page' => 50])['items'] as $oldDev) {
    if (($oldDev['brand']['slug'] ?? '') === 'pioneer-dj-qa') {
        $qaDevs->delete((int) $oldDev['id']);
    }
}
$oldBt = get_term_by('slug', 'pioneer-dj-qa', 'djgear_brand');
if ($oldBt instanceof WP_Term) {
    $qaTax->deleteBrand((int) $oldBt->term_id, 0);
}
$oldCt = get_term_by('slug', 'dj-controllers-qa', 'djgear_device_category');
if ($oldCt instanceof WP_Term) {
    $qaTax->deleteCategory((int) $oldCt->term_id, 0);
}
foreach (wc_get_products(['sku' => 'QA-DS-FLX4']) as $oldWc) {
    wp_delete_post($oldWc->get_id(), true);
}

// 5. Shop Manager -> allowed catalog CRUD, DENIED skin definitions (403) & import/export (403)
$smLogin = 'djgear_cat_sm_' . wp_generate_password(6, false);
$smId = wp_create_user($smLogin, wp_generate_password(16, true), $smLogin . '@djgear.local');
$assert(is_int($smId), 'Created temporary shop_manager for catalog role-split check');

$createdBrandId = 0;
$createdCatId = 0;
$flx4Id = 0;
$ddj400Id = 0;
$draftId = 0;
$skinId = 0;
$wcProductId = 0;

$jsonPost = static function (string $method, string $route, array $body): WP_REST_Response {
    $req = new WP_REST_Request($method, $route);
    $req->set_header('Content-Type', 'application/json');
    $req->set_body((string) wp_json_encode($body));

    return rest_do_request($req);
};

try {
    (new WP_User($smId))->set_role('shop_manager');
    wp_set_current_user($smId);

    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/devices'))->get_status() === 200, 'Shop Manager GET /catalog/devices returns 200');
    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/skins'))->get_status() === 403, 'Shop Manager GET /catalog/skins returns 403');
    $assert($jsonPost('POST', '/djgear/v1/catalog/skins', [])->get_status() === 403, 'Shop Manager POST /catalog/skins returns 403');
    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/export'))->get_status() === 403, 'Shop Manager GET /catalog/export returns 403');
    $assert($jsonPost('POST', '/djgear/v1/catalog/import/validate', [])->get_status() === 403, 'Shop Manager POST /catalog/import/validate returns 403');

    // Shop Manager creates brand, category, and device via CRUD API
    $brandResp = $jsonPost('POST', '/djgear/v1/catalog/brands', [
        'name_en'      => 'Pioneer DJ QA',
        'name_fa'      => 'پایونیر دی‌جی تست',
        'slug'         => 'pioneer-dj-qa',
        'country_code' => 'JP',
    ]);
    $assert($brandResp->get_status() === 201, 'Shop Manager POST /catalog/brands creates brand (201)');
    $createdBrandId = (int) ($brandResp->get_data()['data']['brand']['id'] ?? 0);

    $catResp = $jsonPost('POST', '/djgear/v1/catalog/categories', [
        'name_en' => 'DJ Controllers QA',
        'name_fa' => 'کنترلرهای دی‌جی تست',
        'slug'    => 'dj-controllers-qa',
    ]);
    $assert($catResp->get_status() === 201, 'Shop Manager POST /catalog/categories creates category (201)');
    $createdCatId = (int) ($catResp->get_data()['data']['category']['id'] ?? 0);

    $devResp = $jsonPost('POST', '/djgear/v1/catalog/devices', [
        'brand_term_id'    => $createdBrandId,
        'category_term_id' => $createdCatId,
        'model_code'       => 'DDJ-FLX4',
        'mpn'              => 'QA-FLX4-001',
        'name_en'          => 'Pioneer DJ DDJ-FLX4',
        'name_fa'          => 'کنترلر پایونیر DDJ-FLX4',
        'release_year'     => 2022,
        'lifecycle_status' => 'active',
    ]);
    $assert($devResp->get_status() === 201, 'Shop Manager POST /catalog/devices creates DDJ-FLX4 (201)');
    $flx4Id = (int) ($devResp->get_data()['data']['device']['id'] ?? 0);

    // Duplicate prevention: near-duplicate model code -> 409 Conflict
    $dupResp = $jsonPost('POST', '/djgear/v1/catalog/devices', [
        'brand_term_id'    => $createdBrandId,
        'category_term_id' => $createdCatId,
        'model_code'       => 'ddj flx 4',
        'name_en'          => 'Duplicate FLX4',
        'name_fa'          => 'تکراری',
        'lifecycle_status' => 'active',
    ]);
    $assert($dupResp->get_status() === 409, 'Near-duplicate device rejected with 409 Conflict');

    // Create second device (DDJ-400) + a draft device
    $dev2 = $jsonPost('POST', '/djgear/v1/catalog/devices', [
        'brand_term_id'    => $createdBrandId,
        'category_term_id' => $createdCatId,
        'model_code'       => 'DDJ-400',
        'name_en'          => 'Pioneer DJ DDJ-400',
        'name_fa'          => 'کنترلر پایونیر DDJ-400',
        'lifecycle_status' => 'active',
    ]);
    $ddj400Id = (int) ($dev2->get_data()['data']['device']['id'] ?? 0);
    $assert($ddj400Id > 0, 'Created second device DDJ-400 for multi-device skin test');

    $devDraft = $jsonPost('POST', '/djgear/v1/catalog/devices', [
        'brand_term_id'    => $createdBrandId,
        'category_term_id' => $createdCatId,
        'model_code'       => 'DDJ-SECRET-DRAFT',
        'name_en'          => 'Unpublished Draft Unit',
        'name_fa'          => 'پیش‌نویس مخفی',
        'lifecycle_status' => 'draft',
    ]);
    $draftId = (int) ($devDraft->get_data()['data']['device']['id'] ?? 0);
    $assert($draftId > 0, 'Created draft device for public visibility check');

    // Add specs to FLX4
    $specResp = $jsonPost('PUT', "/djgear/v1/catalog/devices/{$flx4Id}/specs", [
        'specs' => [
            [
                'spec_group'    => 'controls',
                'spec_key'      => 'channels',
                'label_en'      => 'Channels',
                'label_fa'      => 'تعداد کانال',
                'value_en'      => '2',
                'value_fa'      => '۲',
                'value_type'    => 'number',
                'unit'          => 'ch',
                'is_filterable' => true,
            ],
        ],
    ]);
    $assert($specResp->get_status() === 200, 'Specs saved cleanly via PUT /devices/{id}/specs');

    // 6. Switch to administrator for Skin Definitions (multi-device), WooCommerce linking, and Import/Export
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    wp_set_current_user($admins[0]->ID);

    // Create a real WP attachment to test private dieline protection end-to-end
    $dielineAttId = wp_insert_post([
        'post_type'      => 'attachment',
        'post_title'     => 'QA Private Dieline SVG',
        'post_mime_type' => 'image/svg+xml',
        'post_status'    => 'inherit',
    ]);
    $assert(is_int($dielineAttId) && $dielineAttId > 0, "Created temporary dieline attachment #{$dielineAttId}");

    $skinResp = $jsonPost('POST', '/djgear/v1/catalog/skins', [
        'primary_device_id'     => $flx4Id,
        'compatible_device_ids' => [$flx4Id, $ddj400Id],
        'template_code'         => 'SKIN-QA-FLX4-400-V1',
        'template_version'      => '1.0.0',
        'status'                => 'active',
        'width_mm'              => 482.0,
        'height_mm'             => 272.8,
        'bleed_mm'              => 3.0,
        'min_dpi'               => 300,
        'zones'                 => [
            ['id' => 'top-plate', 'label_en' => 'Top Plate', 'label_fa' => 'صفحه رویی', 'required' => true, 'printable' => true],
        ],
        'supported_finishes'    => ['matte', 'gloss', 'carbon'],
        'dieline_attachment_id' => $dielineAttId,
        'internal_notes'        => 'TOP-SECRET-DIELINE-OFFSET',
        'notes_en'              => 'Precision vinyl cut',
        'notes_fa'              => 'برش دقیق وینیل',
    ]);
    $assert($skinResp->get_status() === 201, 'Administrator POST /catalog/skins creates multi-device skin definition (201)');
    $skinId = (int) ($skinResp->get_data()['data']['skin']['id'] ?? 0);

    // Verify private dieline security across Media URL, WP REST wp/v2/media, and gated /dieline endpoint
    $assert(wp_get_attachment_url($dielineAttId) === false, 'Private dieline attachment returns false (no direct URL) from wp_get_attachment_url()');
    $assert(rest_do_request(new WP_REST_Request('GET', "/djgear/v1/catalog/skins/{$skinId}/dieline"))->get_status() === 200, 'Administrator GET /catalog/skins/{id}/dieline returns 200');
    wp_set_current_user($smId);
    $assert(rest_do_request(new WP_REST_Request('GET', "/djgear/v1/catalog/skins/{$skinId}/dieline"))->get_status() === 403, 'Shop Manager GET /catalog/skins/{id}/dieline returns 403');
    wp_set_current_user(0);
    $assert(rest_do_request(new WP_REST_Request('GET', "/djgear/v1/catalog/skins/{$skinId}/dieline"))->get_status() === 401, 'Unauthenticated GET /catalog/skins/{id}/dieline returns 401');
    $mediaGuardStatus = rest_do_request(new WP_REST_Request('GET', "/wp/v2/media/{$dielineAttId}"))->get_status();
    $assert(in_array($mediaGuardStatus, [401, 403], true), "Unauthenticated GET /wp/v2/media/{$dielineAttId} blocked with HTTP {$mediaGuardStatus}");
    wp_set_current_user($admins[0]->ID);

    // Verify rollback protection blocks accidental rollback
    $rollbackBlocked = false;
    try {
        (new CatalogMigrationRunner($wpdb, [new CreateCatalogTables()]))->rollbackLast('WRONG_TOKEN');
    } catch (RuntimeException) {
        $rollbackBlocked = true;
    }
    $assert($rollbackBlocked, 'CatalogMigrationRunner::rollbackLast() blocks unauthorized destructive rollback');

    // Verify 2 MiB import payload size limit returns HTTP 413
    $oversizedReq = new WP_REST_Request('POST', '/djgear/v1/catalog/import/validate');
    $oversizedReq->set_header('Content-Type', 'application/json');
    $oversizedReq->set_body('{"devices":[' . str_repeat(' ', 2100000) . ']}');
    $assert(rest_do_request($oversizedReq)->get_status() === 413, 'Oversized (>2 MiB) import payload rejected with HTTP 413');

    /** @var DeviceRepository $devRepo */
    $devRepo = $core->container()->get(DeviceRepository::class);
    $assert(($devRepo->findById($flx4Id)['skin_ready'] ?? false) === true, 'FLX4 skin_ready flipped to true');
    $assert(($devRepo->findById($ddj400Id)['skin_ready'] ?? false) === true, 'DDJ-400 (secondary compatible device) skin_ready flipped to true');

    // Link real WooCommerce product (HPOS-safe) and verify reverse lookup + auto-purge on delete
    $wcProduct = new WC_Product_Simple();
    $wcProduct->set_name('QA Decksaver FLX4');
    $wcProduct->set_sku('QA-DS-FLX4');
    $wcProduct->set_regular_price('1500000');
    $wcProduct->set_status('publish');
    $wcProductId = $wcProduct->save();
    $assert($wcProductId > 0, "Created temporary WooCommerce product #{$wcProductId}");

    $compatResp = $jsonPost('POST', "/djgear/v1/catalog/devices/{$flx4Id}/compatibility", [
        'target_type'    => 'wc_product',
        'target_id'      => $wcProductId,
        'relation_type'  => 'decksaver',
        'fit_confidence' => 'verified',
    ]);
    $assert($compatResp->get_status() === 201, 'Linked WooCommerce product to FLX4 (201)');

    // 7. Public API Security, Allowlist, Cache Invalidation, and Rate-Limiting tests (Unauthenticated!)
    wp_set_current_user(0);

    $pubList = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/public/devices'))->get_data();
    $pubCodes = array_map(static fn (array $d): string => (string) $d['model_code'], $pubList['data']['items'] ?? []);
    $assert(in_array('DDJ-FLX4', $pubCodes, true), 'Public /devices exposes active DDJ-FLX4');
    $assert(!in_array('DDJ-SECRET-DRAFT', $pubCodes, true), 'Public /devices NEVER exposes draft device');

    $flx4Slug = (string) ($devRepo->findById($flx4Id)['slug'] ?? '');
    $pubDetailResp = rest_do_request(new WP_REST_Request('GET', "/djgear/v1/catalog/public/devices/{$flx4Slug}"));
    $pubDetail = $pubDetailResp->get_data()['data']['device'] ?? [];
    $assert($pubDetailResp->get_status() === 200, 'Public /devices/{slug} returns 200 for active device');
    $assert(!array_key_exists('internal_notes', $pubDetail) && !array_key_exists('edit_url', $pubDetail), 'Public device strips private keys');
    $pubSkin = $pubDetail['skins'][0] ?? [];
    $assert(($pubSkin['template_code'] ?? '') === 'SKIN-QA-FLX4-400-V1', 'Public device includes active skin definition');
    $assert(
        !array_key_exists('dieline_attachment_id', $pubSkin)
        && !array_key_exists('dieline_url', $pubSkin)
        && !array_key_exists('internal_notes', $pubSkin),
        'Public skin payload strictly strips dieline_attachment_id, dieline_url, and internal_notes'
    );

    $pubWcResp = rest_do_request(new WP_REST_Request('GET', "/djgear/v1/catalog/public/wc-product/{$wcProductId}/devices"));
    $pubWcItems = $pubWcResp->get_data()['data']['items'] ?? [];
    $assert($pubWcResp->get_status() === 200 && count($pubWcItems) === 1 && $pubWcItems[0]['model_code'] === 'DDJ-FLX4', 'Public WC reverse-lookup returns FLX4');

    // Verify cache invalidation on mutation
    /** @var CatalogCacheService $cacheSvc */
    $cacheSvc = $core->container()->get(CatalogCacheService::class);
    $verBefore = $cacheSvc->currentVersion();
    wp_set_current_user($admins[0]->ID);
    $jsonPost('PUT', "/djgear/v1/catalog/devices/{$flx4Id}", ['summary_en' => 'Updated after cache check']);
    $verAfter = $cacheSvc->currentVersion();
    $assert($verAfter > $verBefore, "Public Redis cache version bumped on update ({$verBefore} -> {$verAfter})");

    // Verify Import dry-run & Export JSON/CSV as administrator
    $valResp = $jsonPost('POST', '/djgear/v1/catalog/import/validate', [
        'conflict_policy' => 'skip',
        'devices'         => [
            [
                'brand_slug'    => 'pioneer-dj-qa',
                'category_slug' => 'dj-controllers-qa',
                'model_code'    => 'DDJ-FLX4',
                'name_en'       => 'Pioneer DJ DDJ-FLX4',
            ],
        ],
    ]);
    $assert($valResp->get_status() === 200 && ($valResp->get_data()['data']['summary']['skip'] ?? 0) === 1, 'Import dry-run detects existing canonical_key and plans skip');

    $expCsvReq = new WP_REST_Request('GET', '/djgear/v1/catalog/export');
    $expCsvReq->set_param('format', 'csv');
    $expCsv = rest_do_request($expCsvReq);
    $assert($expCsv->get_status() === 200 && str_contains((string) ($expCsv->get_data()['data']['content'] ?? ''), 'pioneer-dj-qa::ddjflx4'), 'Admin CSV export returns canonical device row');

    // Verify Rate Limiter triggers HTTP 429 cleanly
    wp_set_current_user(0);
    $limitFilter = static fn (): int => 2;
    add_filter('djgear_catalog_public_rate_limit', $limitFilter);
    wp_cache_flush_group('djgear_catalog_ratelimit');
    $r1 = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/public/brands'))->get_status();
    $r2 = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/public/brands'))->get_status();
    $r3 = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/catalog/public/brands'))->get_status();
    remove_filter('djgear_catalog_public_rate_limit', $limitFilter);
    wp_cache_flush_group('djgear_catalog_ratelimit');
    $assert($r1 === 200 && $r2 === 200 && $r3 === 429, "Public rate limiter enforced HTTP 429 on 3rd request (got {$r1}, {$r2}, {$r3})");

    // Verify deleting WooCommerce product automatically purges stale compatibility edge
    wp_delete_post($wcProductId, true);
    $wcProductId = 0;
    /** @var CatalogDetailsRepository $detailsRepo */
    $detailsRepo = $core->container()->get(CatalogDetailsRepository::class);
    $remainingCompat = $detailsRepo->listCompatibility($flx4Id, true);
    $assert(count($remainingCompat) === 0, 'Deleting WooCommerce product purged stale compatibility link');
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_set_current_user(0);

    /** @var SkinDefinitionRepository $skinRepo */
    $skinRepo = $core->container()->get(SkinDefinitionRepository::class);
    /** @var DeviceRepository $devRepoClean */
    $devRepoClean = $core->container()->get(DeviceRepository::class);
    /** @var TaxonomyRepository $taxRepo */
    $taxRepo = $core->container()->get(TaxonomyRepository::class);

    if ($skinId > 0) {
        try { $skinRepo->delete($skinId); } catch (\Throwable) {}
    }
    foreach ([$flx4Id, $ddj400Id, $draftId] as $did) {
        if ($did > 0) {
            try { $devRepoClean->delete($did); } catch (\Throwable) {}
        }
    }
    if ($wcProductId > 0) {
        wp_delete_post($wcProductId, true);
    }
    if (isset($dielineAttId) && is_int($dielineAttId) && $dielineAttId > 0) {
        wp_delete_post($dielineAttId, true);
    }
    if ($createdBrandId > 0) {
        try { $taxRepo->deleteBrand($createdBrandId, 0); } catch (\Throwable) {}
    }
    if ($createdCatId > 0) {
        try { $taxRepo->deleteCategory($createdCatId, 0); } catch (\Throwable) {}
    }
    wp_delete_user($smId);
}

echo "\nALL {$passed} LIVE DJGEAR-DEVICE-CATALOG INTEGRATION ASSERTIONS PASSED.\n";
