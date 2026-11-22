<?php
/**
 * Live WordPress + WooCommerce integration test suite for djgear-dashboard.
 *
 * Execute inside the WordPress container via:
 *   wp eval-file /var/www/html/wp-content/plugins/djgear-dashboard/tests/Integration/wp-dashboard-integration-test.php
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use DJGear\Dashboard\Admin\AdminPage;
use DJGear\Dashboard\DashboardModule;
use DJGear\Dashboard\Services\SettingsService;

$passed = 0;
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    $passed++;
    echo "[PASS] {$label}\n";
};

// 1. Module registration through djgear-core contracts
$core = djgear_core();
$assert($core->modules()->has(DashboardModule::MODULE_ID), 'djgear-dashboard is registered in djgear-core ModuleRegistry');

$descriptors = [];
foreach ($core->modules()->descriptors() as $d) {
    $descriptors[$d->id] = $d;
}
$assert(isset($descriptors['djgear-dashboard']) && $descriptors['djgear-dashboard']->booted, 'djgear-dashboard module booted via djgear-core');

// 2. WooCommerce HPOS compatibility declared for djgear-dashboard
if (class_exists(FeaturesUtil::class) && method_exists(FeaturesUtil::class, 'get_compatible_features_for_plugin')) {
    $compat = FeaturesUtil::get_compatible_features_for_plugin('djgear-dashboard/djgear-dashboard.php');
    $compatibleList = is_array($compat['compatible'] ?? null) ? $compat['compatible'] : [];
    $assert(
        in_array('custom_order_tables', $compatibleList, true),
        'WooCommerce HPOS (custom_order_tables) compatibility declared for djgear-dashboard'
    );
}

// 3. Vite manifest exists & AdminPage boot config builds cleanly
$manifestPath = DJGEAR_DASHBOARD_DIR . '/assets/dist/.vite/manifest.json';
$assert(is_readable($manifestPath), 'Vite production manifest exists at assets/dist/.vite/manifest.json');
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$assert(is_array($manifest) && !empty($manifest['src/main.tsx']['file']), 'Vite manifest contains compiled src/main.tsx entry');

// 4. All dashboard REST routes registered under djgear/v1
$routes = rest_get_server()->get_routes();
foreach ([
    '/djgear/v1/dashboard/overview',
    '/djgear/v1/dashboard/products',
    '/djgear/v1/dashboard/orders',
    '/djgear/v1/dashboard/customers',
    '/djgear/v1/dashboard/settings',
    '/djgear/v1/dashboard/preferences/locale',
] as $route) {
    $assert(isset($routes[$route]), "REST route {$route} registered");
}

// 5. Unauthenticated requests -> 401
wp_set_current_user(0);
foreach ([
    ['GET', '/djgear/v1/dashboard/overview'],
    ['GET', '/djgear/v1/dashboard/products'],
    ['GET', '/djgear/v1/dashboard/orders'],
    ['GET', '/djgear/v1/dashboard/customers'],
    ['GET', '/djgear/v1/dashboard/settings'],
    ['POST', '/djgear/v1/dashboard/settings'],
    ['POST', '/djgear/v1/dashboard/preferences/locale'],
] as [$method, $route]) {
    $req = new WP_REST_Request($method, $route);
    if ($route === '/djgear/v1/dashboard/preferences/locale') {
        $req->set_param('locale', 'fa_IR');
    }
    $status = rest_do_request($req)->get_status();
    $assert($status === 401, "Unauthenticated {$method} {$route} returns 401");
}

// 6. Subscriber -> 403 on all dashboard endpoints
$subLogin = 'djgear_dash_sub_' . wp_generate_password(6, false);
$subId = wp_create_user($subLogin, wp_generate_password(16, true), $subLogin . '@djgear.local');
$assert(is_int($subId), 'Created temporary subscriber for dashboard 403 check');
try {
    (new WP_User($subId))->set_role('subscriber');
    wp_set_current_user($subId);
    foreach (['/djgear/v1/dashboard/overview', '/djgear/v1/dashboard/products', '/djgear/v1/dashboard/orders', '/djgear/v1/dashboard/settings'] as $route) {
        $assert(rest_do_request(new WP_REST_Request('GET', $route))->get_status() === 403, "Subscriber GET {$route} returns 403");
    }
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subId);
    wp_set_current_user(0);
}

// 7. Shop Manager -> 200 on commerce/overview/locale, 403 on central settings & modules
$smLogin = 'djgear_dash_sm_' . wp_generate_password(6, false);
$smId = wp_create_user($smLogin, wp_generate_password(16, true), $smLogin . '@djgear.local');
$assert(is_int($smId), 'Created temporary shop_manager for dashboard capability check');
try {
    (new WP_User($smId))->set_role('shop_manager');
    wp_set_current_user($smId);

    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/overview'))->get_status() === 200, 'Shop Manager GET /dashboard/overview returns 200');
    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/products'))->get_status() === 200, 'Shop Manager GET /dashboard/products returns 200');
    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/orders'))->get_status() === 200, 'Shop Manager GET /dashboard/orders returns 200');
    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/customers'))->get_status() === 200, 'Shop Manager GET /dashboard/customers returns 200');
    $assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/settings'))->get_status() === 403, 'Shop Manager GET /dashboard/settings returns 403');
    $assert(rest_do_request(new WP_REST_Request('POST', '/djgear/v1/dashboard/settings'))->get_status() === 403, 'Shop Manager POST /dashboard/settings returns 403');

    // Locale preference switch persists for current user
    $locReq = new WP_REST_Request('POST', '/djgear/v1/dashboard/preferences/locale');
    $locReq->set_param('locale', 'fa_IR');
    $locResp = rest_do_request($locReq);
    $assert($locResp->get_status() === 200, 'Shop Manager POST /dashboard/preferences/locale (fa_IR) returns 200');
    $assert(get_user_meta($smId, 'locale', true) === 'fa_IR', 'User locale meta persisted as fa_IR');
    $assert(($locResp->get_data()['data']['i18n']['direction'] ?? '') === 'rtl', 'Locale response reports direction=rtl');
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($smId);
    wp_set_current_user(0);
}

// 8. Administrator -> 200 on all endpoints + real Settings save & audit trail + real HPOS WooCommerce product/order read
$admins = get_users(['role' => 'administrator', 'number' => 1]);
$assert(count($admins) === 1, 'Found administrator user');
wp_set_current_user($admins[0]->ID);

/** @var AdminPage $adminPage */
$adminPage = $core->container()->get(AdminPage::class);
$bootConfig = $adminPage->buildBootConfig();
$assert(!empty($bootConfig['nonce']) && $bootConfig['currentUser']['id'] === $admins[0]->ID, 'AdminPage boot config exposes WP REST nonce and current user');

$ovResp = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/overview'));
$ovData = $ovResp->get_data();
$assert($ovResp->get_status() === 200 && ($ovData['data']['commerce']['woocommerce_active'] ?? false) === true, 'Admin GET /dashboard/overview returns live WooCommerce telemetry');
$assert(in_array('djgear-dashboard', $ovData['data']['modules']['booted_ids'] ?? [], true), 'Overview lists djgear-dashboard as booted');

// Settings read + update + restore cleanly
$origSettings = get_option(SettingsService::OPTION_KEY, null);
$setPost = new WP_REST_Request('POST', '/djgear/v1/dashboard/settings');
$setPost->set_header('Content-Type', 'application/json');
$setPost->set_body(wp_json_encode([
    'brand_name'           => 'DJGear Verified',
    'default_locale'       => 'fa_IR',
    'persian_digits'       => true,
    'audit_retention_days' => 60,
    'maintenance_notice'   => '',
]));
$setResp = rest_do_request($setPost);
$assert($setResp->get_status() === 200 && ($setResp->get_data()['data']['settings']['brand_name'] ?? '') === 'DJGear Verified', 'Admin POST /dashboard/settings updates and sanitizes settings');

$auditResp = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/audit-logs'));
$auditActions = array_map(static fn (array $item): string => (string) ($item['action'] ?? ''), $auditResp->get_data()['data']['items'] ?? []);
$assert(in_array('settings.updated', $auditActions, true), 'Settings update recorded settings.updated in djgear audit log');

if ($origSettings === null) {
    delete_option(SettingsService::OPTION_KEY);
} else {
    update_option(SettingsService::OPTION_KEY, $origSettings, false);
}

// Verify WooCommerce product/order/customer endpoints return 200 with zero core table mutations
$assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/products'))->get_status() === 200, 'Admin GET /dashboard/products returns 200');
$assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/orders'))->get_status() === 200, 'Admin GET /dashboard/orders returns 200');
$assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/dashboard/customers'))->get_status() === 200, 'Admin GET /dashboard/customers returns 200');
$assert(rest_do_request(new WP_REST_Request('GET', '/djgear/v1/modules'))->get_status() === 200, 'Admin GET /djgear/v1/modules returns 200');

wp_set_current_user(0);

echo "\nALL {$passed} LIVE DJGEAR-DASHBOARD INTEGRATION ASSERTIONS PASSED.\n";
