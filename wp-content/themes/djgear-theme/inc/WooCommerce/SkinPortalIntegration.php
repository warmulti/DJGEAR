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
        global $post;

        if ($post instanceof \WP_Post && has_shortcode((string) $post->post_content, 'djgear_skin_configurator')) {
            $classes[] = 'djg-has-skin-configurator';
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('custom-skins')) {
            $classes[] = 'djg-wc-custom-skins-endpoint';
        }

        return $classes;
    }
}
