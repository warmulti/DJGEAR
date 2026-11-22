<?php

declare(strict_types=1);

namespace DJGear\Theme\Setup;

/**
 * Registers core WordPress & WooCommerce theme supports and image sizes.
 */
final class ThemeSupport
{
    public function register(): void
    {
        add_action('after_setup_theme', [$this, 'setup']);
        add_filter('excerpt_length', static fn (): int => 24);
        add_filter('excerpt_more', static fn (): string => '&hellip;');
    }

    public function setup(): void
    {
        add_theme_support('title-tag');
        add_theme_support('automatic-feed-links');
        add_theme_support('post-thumbnails');
        add_theme_support('align-wide');
        add_theme_support('responsive-embeds');
        add_theme_support('customize-selective-refresh-widgets');

        add_theme_support('html5', [
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'style',
            'script',
            'navigation-widgets',
        ]);

        add_theme_support('custom-logo', [
            'height'               => 48,
            'width'                => 180,
            'flex-height'          => true,
            'flex-width'           => true,
            'unlink-homepage-logo' => false,
        ]);

        // Native WooCommerce 11.x theme support & product gallery features
        add_theme_support('woocommerce', [
            'thumbnail_image_width' => 480,
            'single_image_width'    => 900,
            'product_grid'          => [
                'default_rows'    => 4,
                'min_rows'        => 1,
                'max_rows'        => 8,
                'default_columns' => 4,
                'min_columns'     => 1,
                'max_columns'     => 4,
            ],
        ]);
        add_theme_support('wc-product-gallery-zoom');
        add_theme_support('wc-product-gallery-lightbox');
        add_theme_support('wc-product-gallery-slider');

        // Studio aspect-ratio image crops for crisp cards & editorial imagery
        add_image_size('djg-card-4x3', 640, 480, true);
        add_image_size('djg-hero', 1600, 900, true);
    }
}
