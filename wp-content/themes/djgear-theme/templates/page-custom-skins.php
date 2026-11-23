<?php

declare(strict_types=1);

/**
 * Template Name: Custom DJ Skins Configurator
 * Template Post Type: page
 *
 * Dedicated storefront page template wrapping the existing `[djgear_skin_configurator]`
 * shortcode from `djgear-custom-skins` inside the DJGear Premium Dark studio layout.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only device preselection hint
$preselectedDeviceId = isset($_GET['device_id']) && is_numeric($_GET['device_id']) ? absint($_GET['device_id']) : 0;
?>
<div class="djg-container djg-page-shell djg-skins-page-shell">
    <?php get_template_part('template-parts/components/breadcrumbs'); ?>

    <?php
    while (have_posts()) :
        the_post();
        $content = (string) get_the_content();
        $hasEmbeddedShortcode = has_shortcode($content, 'djgear_skin_configurator');
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('djg-skins-landing'); ?>>
            <header class="djg-page-header djg-skins-landing__header">
                <p class="djg-eyebrow"><?php esc_html_e('CUSTOM EQUIPMENT SKINS', 'djgear-theme'); ?></p>
                <h1 class="djg-page-title"><?php the_title(); ?></h1>
                <p class="djg-page-lead">
                    <?php esc_html_e('Select your compatible DJ device, choose a finish and ordering plan, optionally upload custom print artwork, and complete checkout natively through WooCommerce.', 'djgear-theme'); ?>
                </p>
            </header>

            <div class="djg-entry-content">
                <?php
                if ($hasEmbeddedShortcode) {
                    the_content();
                } else {
                    if (trim(wp_strip_all_tags($content)) !== '') {
                        the_content();
                    }
                    if (shortcode_exists('djgear_skin_configurator')) {
                        $shortcode = $preselectedDeviceId > 0
                            ? sprintf('[djgear_skin_configurator device_id="%d"]', $preselectedDeviceId)
                            : '[djgear_skin_configurator]';
                        echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    } else {
                        echo '<div class="djg-empty-state"><p>' . esc_html__('The Custom Skins ordering module is currently unavailable.', 'djgear-theme') . '</p></div>';
                    }
                }
                ?>
            </div>
        </article>
    <?php endwhile; ?>
</div>
<?php
get_footer();
