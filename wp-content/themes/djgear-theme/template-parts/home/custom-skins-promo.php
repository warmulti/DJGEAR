<?php

declare(strict_types=1);

use DJGear\Theme\Template\HomepageData;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$skinsUrl = HomepageData::customSkinsUrl();
if ($skinsUrl === '') {
    return;
}
?>
<section class="djg-home-section djg-home-skins" aria-labelledby="djg-home-skins-title">
    <div class="djg-container">
        <div class="djg-skins-banner">
            <div class="djg-skins-banner__copy">
                <p class="djg-eyebrow"><?php esc_html_e('CUSTOM EQUIPMENT SKINS', 'djgear-theme'); ?></p>
                <h2 class="djg-section-title" id="djg-home-skins-title">
                    <?php esc_html_e('Tailor the finish of your compatible DJ gear.', 'djgear-theme'); ?>
                </h2>
                <p class="djg-skins-banner__lead">
                    <?php esc_html_e('Choose your DJ device model, select a skin finish and color, and pick either a ready-made Standard plan or a Custom Design plan with artwork upload and proof approval before production.', 'djgear-theme'); ?>
                </p>

                <div class="djg-skins-banner__actions">
                    <a class="djg-btn djg-btn--primary djg-btn--lg" href="<?php echo esc_url($skinsUrl); ?>">
                        <?php Icons::render('layers', 18); ?>
                        <span><?php esc_html_e('Open Custom Skins Portal', 'djgear-theme'); ?></span>
                    </a>
                </div>
            </div>

            <ol class="djg-skins-steps" aria-label="<?php esc_attr_e('Custom Skin ordering steps', 'djgear-theme'); ?>">
                <li class="djg-skins-step">
                    <span class="djg-skins-step__num djg-ltr-isolate">01</span>
                    <div>
                        <h3 class="djg-skins-step__title"><?php esc_html_e('Select your DJ device', 'djgear-theme'); ?></h3>
                        <p class="djg-skins-step__text"><?php esc_html_e('Pick from active, compatible models in the DJGear device catalog.', 'djgear-theme'); ?></p>
                    </div>
                </li>
                <li class="djg-skins-step">
                    <span class="djg-skins-step__num djg-ltr-isolate">02</span>
                    <div>
                        <h3 class="djg-skins-step__title"><?php esc_html_e('Pick skin model, color & plan', 'djgear-theme'); ?></h3>
                        <p class="djg-skins-step__text"><?php esc_html_e('Order a Standard finish immediately or choose Custom Design to upload your own artwork.', 'djgear-theme'); ?></p>
                    </div>
                </li>
                <li class="djg-skins-step">
                    <span class="djg-skins-step__num djg-ltr-isolate">03</span>
                    <div>
                        <h3 class="djg-skins-step__title"><?php esc_html_e('Checkout & track production', 'djgear-theme'); ?></h3>
                        <p class="djg-skins-step__text"><?php esc_html_e('Complete payment via WooCommerce and follow proof approval and production milestones from My Account.', 'djgear-theme'); ?></p>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</section>
