<?php
/**
 * Live WordPress + WooCommerce integration verification suite for djgear-core.
 *
 * Execute inside the WordPress container via:
 *   wp eval-file /var/www/html/wp-content/plugins/djgear-core/tests/Integration/wp-integration-test.php
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use DJGear\Core\I18n\Direction;
use DJGear\Core\I18n\Translator;
use DJGear\Core\Lifecycle\Activator;
use DJGear\Core\Lifecycle\MigrationRunner;
use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Plugin;
use DJGear\Core\Rest\RestKernel;
use DJGear\Core\Security\Capabilities;

$passed = 0;
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    $passed++;
    echo "[PASS] {$label}\n";
};

global $wpdb;

// 1. Core singleton & container sanity
$core = djgear_core();
$assert($core instanceof Plugin, 'djgear_core() returns Plugin kernel instance');
$assert($core->container()->has(Translator::class), 'Container registers Translator');
$assert($core->container()->has(AuditLoggerInterface::class), 'Container registers AuditLoggerInterface');
$assert($core->container()->has(RestKernel::class), 'Container registers RestKernel');

// 2. Migration & DB table check (non-root user)
$auditTable = $wpdb->prefix . AuditLogger::TABLE_SUFFIX;
$tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $auditTable));
$assert($tableExists === $auditTable, "Audit table {$auditTable} exists");

$appliedMigrations = get_option(MigrationRunner::OPTION_KEY, []);
$assert(
    is_array($appliedMigrations) && in_array('0001_create_audit_log_table', $appliedMigrations, true),
    'Migration 0001_create_audit_log_table recorded in wp_options'
);
$assert(get_option(Activator::VERSION_OPTION) === Plugin::VERSION, 'Plugin version stored in wp_options');

// 3. Capabilities installed on administrator and shop_manager
$adminRole = get_role('administrator');
$shopRole = get_role('shop_manager');
$assert($adminRole !== null && $adminRole->has_cap(Capabilities::MANAGE_CORE), 'Administrator has djgear_manage_core');
$assert($adminRole !== null && $adminRole->has_cap(Capabilities::MANAGE_MODULES), 'Administrator has djgear_manage_modules');
$assert($shopRole !== null && $shopRole->has_cap(Capabilities::VIEW_DASHBOARD), 'Shop Manager has djgear_view_dashboard');
$assert($shopRole !== null && !$shopRole->has_cap(Capabilities::MANAGE_CORE), 'Shop Manager does NOT have djgear_manage_core');

// 4. WooCommerce active, HPOS compatibility declared, no pending DB updates
$assert(class_exists('WooCommerce'), 'WooCommerce is active');
$assert(defined('WC_VERSION') && WC_VERSION === '11.3.1', 'WooCommerce version is 11.3.1');
$assert(class_exists('WC_Install') && !WC_Install::needs_db_update(), 'WooCommerce has no pending DB updates');

if (class_exists(FeaturesUtil::class) && method_exists(FeaturesUtil::class, 'get_compatible_features_for_plugin')) {
    $compat = FeaturesUtil::get_compatible_features_for_plugin('djgear-core/djgear-core.php');
    $compatibleList = is_array($compat['compatible'] ?? null) ? $compat['compatible'] : [];
    $assert(
        in_array('custom_order_tables', $compatibleList, true),
        'WooCommerce HPOS (custom_order_tables) compatibility declared for djgear-core'
    );
}

// 5. Audit logging write, secret redaction, and read
/** @var AuditLoggerInterface $auditLogger */
$auditLogger = $core->container()->get(AuditLoggerInterface::class);
$markerAction = 'integration.test.' . gmdate('YmdHis');
$insertedId = $auditLogger->record(new AuditEvent(
    action: $markerAction,
    resourceType: 'test',
    resourceId: 'phase1',
    context: ['note' => 'ok', 'api_key' => 'never-store-plain'],
));
$assert($insertedId > 0, "AuditLogger inserted event ID={$insertedId}");

$queried = $auditLogger->query(1, 5, $markerAction);
$assert($queried['total'] === 1 && count($queried['items']) === 1, 'AuditLogger queries recorded event by action');
$assert(($queried['items'][0]->context['api_key'] ?? null) === '[REDACTED]', 'AuditLogger redacts sensitive keys before DB insert');

// Clean up synthetic test audit row
$wpdb->delete($auditTable, ['id' => $insertedId], ['%d']);

// 6. REST API permission tests (401 unauthenticated, 403 subscriber, 200 administrator)
$server = rest_get_server();
$routes = $server->get_routes();
$assert(isset($routes['/djgear/v1/health']), 'REST route /djgear/v1/health is registered');
$assert(isset($routes['/djgear/v1/modules']), 'REST route /djgear/v1/modules is registered');
$assert(isset($routes['/djgear/v1/audit-logs']), 'REST route /djgear/v1/audit-logs is registered');

// 6a. Unauthenticated -> 401
wp_set_current_user(0);
foreach (['/djgear/v1/health', '/djgear/v1/modules', '/djgear/v1/audit-logs'] as $route) {
    $resp = rest_do_request(new WP_REST_Request('GET', $route));
    $assert($resp->get_status() === 401, "Unauthenticated GET {$route} returns 401");
}

// 6b. Authenticated subscriber without DJGear caps -> 403
$subLogin = 'djgear_qa_sub_' . wp_generate_password(6, false);
$subUserId = wp_create_user($subLogin, wp_generate_password(16, true), $subLogin . '@djgear.local');
$assert(!is_wp_error($subUserId) && is_int($subUserId), 'Created temporary subscriber user for 403 check');
try {
    $subUser = new WP_User($subUserId);
    $subUser->set_role('subscriber');
    wp_set_current_user($subUserId);

    foreach (['/djgear/v1/health', '/djgear/v1/modules', '/djgear/v1/audit-logs'] as $route) {
        $resp = rest_do_request(new WP_REST_Request('GET', $route));
        $assert($resp->get_status() === 403, "Subscriber GET {$route} returns 403");
    }
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subUserId);
    wp_set_current_user(0);
}

// 6c. Authenticated administrator -> 200
$admins = get_users(['role' => 'administrator', 'number' => 1]);
$assert(count($admins) === 1, 'Found local administrator user');
wp_set_current_user($admins[0]->ID);

$healthResp = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/health'));
$healthData = $healthResp->get_data();
$assert($healthResp->get_status() === 200, 'Administrator GET /djgear/v1/health returns 200');
$assert(($healthData['success'] ?? false) === true, 'Health response envelope has success=true');
$assert(($healthData['data']['woocommerce']['version'] ?? null) === '11.3.1', 'Health reports WooCommerce 11.3.1');
$assert(($healthData['data']['woocommerce']['hpos_compatible'] ?? false) === true, 'Health reports HPOS compatible');

$modulesResp = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/modules'));
$assert($modulesResp->get_status() === 200, 'Administrator GET /djgear/v1/modules returns 200');

$logsResp = rest_do_request(new WP_REST_Request('GET', '/djgear/v1/audit-logs'));
$logsData = $logsResp->get_data();
$assert($logsResp->get_status() === 200, 'Administrator GET /djgear/v1/audit-logs returns 200');
$assert(($logsData['meta']['total'] ?? 0) >= 1, 'Audit logs endpoint returns activation history');

wp_set_current_user(0);

// 7. Persian (fa_IR / RTL) and English (en_US / LTR) translation loading tests
$translator = $core->translator();
$switched = switch_to_locale('fa_IR');
$assert($switched, 'Switched WordPress runtime locale to fa_IR');
unload_textdomain(Translator::TEXT_DOMAIN);
$loadedFa = $translator->loadTextDomain();
$assert($loadedFa, 'Loaded djgear-core Persian (fa_IR) translation catalog');
$assert($translator->direction() === Direction::RTL && $translator->isRtl(), 'Translator resolves RTL under fa_IR');
$translatedFa = $translator->translate('DJGear Core');
$assert($translatedFa === 'هسته دی‌جی‌گیر', "Persian translation resolved: {$translatedFa}");
restore_previous_locale();
unload_textdomain(Translator::TEXT_DOMAIN);

$assert($translator->direction('en_US') === Direction::LTR && !$translator->isRtl('en_US'), 'Translator resolves LTR under en_US');
$translatedEn = $translator->translate('DJGear Core');
$assert($translatedEn === 'DJGear Core', "English fallback resolved: {$translatedEn}");

echo "\nALL {$passed} LIVE WORDPRESS/WOOCOMMERCE INTEGRATION ASSERTIONS PASSED.\n";
