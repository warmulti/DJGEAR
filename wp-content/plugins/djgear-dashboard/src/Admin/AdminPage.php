<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Admin;

use DJGear\Core\I18n\Translator;
use DJGear\Core\Security\Capabilities;
use DJGear\Dashboard\DashboardModule;

/**
 * Registers the DJGear Dashboard WP Admin page and enqueues compiled Vite assets.
 *
 * Native wp-admin remains completely functional and accessible.
 */
final class AdminPage
{
    public const MENU_SLUG = 'djgear-dashboard';
    public const SCRIPT_HANDLE = 'djgear-dashboard-app';

    private string $hookSuffix = '';

    public function __construct(
        private readonly string $pluginFile,
        private readonly Translator $translator,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        $this->hookSuffix = add_menu_page(
            'DJGear Dashboard',
            'DJGear',
            Capabilities::VIEW_DASHBOARD,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-admin-generic',
            2
        );
    }

    public function enqueueAssets(string $currentHook): void
    {
        if ($this->hookSuffix === '' || $currentHook !== $this->hookSuffix) {
            return;
        }

        $distDir = dirname($this->pluginFile) . '/assets/dist';
        $distUrl = plugin_dir_url($this->pluginFile) . 'assets/dist/';
        $manifestPath = $distDir . '/.vite/manifest.json';

        if (!is_readable($manifestPath)) {
            return;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return;
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return;
        }

        $entry = $manifest['src/main.tsx'] ?? null;
        if (!is_array($entry) || empty($entry['file']) || !is_string($entry['file'])) {
            return;
        }

        if (isset($entry['css']) && is_array($entry['css'])) {
            foreach ($entry['css'] as $idx => $cssFile) {
                if (is_string($cssFile) && $cssFile !== '') {
                    wp_enqueue_style(
                        self::SCRIPT_HANDLE . '-css-' . (string) $idx,
                        $distUrl . ltrim($cssFile, '/'),
                        [],
                        DashboardModule::VERSION
                    );
                }
            }
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $distUrl . ltrim($entry['file'], '/'),
            [],
            DashboardModule::VERSION,
            true
        );

        // Mark script tag as ES module for Vite production bundle
        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if ($handle !== self::SCRIPT_HANDLE) {
                return $tag;
            }

            if (str_contains($tag, 'type=')) {
                return $tag;
            }

            return str_replace('<script ', '<script type="module" ', $tag);
        }, 10, 2);

        $config = $this->buildBootConfig();
        $jsonConfig = wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($jsonConfig)) {
            wp_add_inline_script(
                self::SCRIPT_HANDLE,
                'window.DJGEAR_DASHBOARD_CONFIG = ' . $jsonConfig . ';',
                'before'
            );
        }
    }

    public function render(): void
    {
        if (!current_user_can(Capabilities::VIEW_DASHBOARD)) {
            wp_die(esc_html__('You do not have permission to access the DJGear Dashboard.', 'djgear-dashboard'), 403);
        }

        $manifestPath = dirname($this->pluginFile) . '/assets/dist/.vite/manifest.json';
        if (!is_readable($manifestPath)) {
            echo '<div class="wrap"><h1>DJGear Dashboard</h1>';
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Frontend build assets are missing. Run `npm run build` in wp-content/plugins/djgear-dashboard/frontend.', 'djgear-dashboard');
            echo '</p></div></div>';
            return;
        }

        echo '<div id="djgear-dashboard-root" class="djgear-dashboard-mount"></div>';
    }

    /**
     * Build non-sensitive client boot configuration sourced from WordPress session state.
     *
     * @return array<string, mixed>
     */
    public function buildBootConfig(): array
    {
        $user = wp_get_current_user();
        $userCapabilities = [];

        if ($user->ID > 0) {
            foreach ([
                Capabilities::VIEW_DASHBOARD,
                Capabilities::MANAGE_CORE,
                Capabilities::MANAGE_MODULES,
                Capabilities::VIEW_LOGS,
                Capabilities::MANAGE_INTEGRATIONS,
                'djgear_view_catalog',
                'djgear_manage_catalog',
                'djgear_manage_skin_definitions',
                'djgear_import_export_catalog',
                'djgear_view_skin_orders',
                'djgear_manage_skin_orders',
                'djgear_manage_skin_catalog',
                'djgear_manage_skin_plans',
                'edit_products',
                'edit_shop_orders',
                'list_users',
                'manage_woocommerce',
            ] as $cap) {
                $userCapabilities[$cap] = user_can($user, $cap);
            }
        }

        return [
            'version'     => DashboardModule::VERSION,
            'restUrl'     => esc_url_raw(rest_url()),
            'nonce'       => wp_create_nonce('wp_rest'),
            'adminUrl'    => esc_url_raw(admin_url()),
            'siteTitle'   => (string) get_bloginfo('name'),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'i18n'        => $this->translator->metadata(),
            'currentUser' => [
                'id'           => (int) $user->ID,
                'displayName'  => (string) $user->display_name,
                'email'        => (string) $user->user_email,
                'roles'        => array_values($user->roles),
                'capabilities' => $userCapabilities,
            ],
        ];
    }
}
