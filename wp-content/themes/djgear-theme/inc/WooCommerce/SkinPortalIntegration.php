<?php

declare(strict_types=1);

namespace DJGear\Theme\WooCommerce;

/**
 * Non-intrusive presentation integration for djgear-custom-skins storefront surfaces.
 *
 * Adds semantic body classes when a page hosts `[djgear_skin_configurator]` or the
 * `/my-account/custom-skins/` endpoint so theme CSS can style them cleanly without
 * touching any plugin code or duplicating business logic.
 */
final class SkinPortalIntegration
{
    public function register(): void
    {
        add_filter('body_class', [$this, 'addSkinsBodyClasses']);
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function addSkinsBodyClasses(array $classes): array
    {
        if (self::isCustomSkinsContext()) {
            $classes[] = 'djg-skins-experience';
        }

        global $post;
        if ($post instanceof \WP_Post && (has_shortcode((string) $post->post_content, 'djgear_skin_configurator') || is_page_template('templates/page-custom-skins.php'))) {
            $classes[] = 'djg-has-skin-configurator';
        }

        if (self::isCustomSkinsEndpoint()) {
            $classes[] = 'djg-wc-custom-skins-endpoint';
        }

        return $classes;
    }

    public static function isCustomSkinsEndpoint(): bool
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('custom-skins')) {
            return true;
        }

        if (function_exists('is_account_page') && is_account_page()) {
            global $wp;
            if ($wp instanceof \WP && array_key_exists('custom-skins', $wp->query_vars)) {
                return true;
            }
        }

        return false;
    }

    public static function isCustomSkinsContext(): bool
    {
        if (self::isCustomSkinsEndpoint()) {
            return true;
        }

        if (is_page_template('templates/page-custom-skins.php')) {
            return true;
        }

        global $post;
        return $post instanceof \WP_Post && has_shortcode((string) $post->post_content, 'djgear_skin_configurator');
    }
}
