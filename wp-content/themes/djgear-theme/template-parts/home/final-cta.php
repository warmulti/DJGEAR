<?php

declare(strict_types=1);

use DJGear\Theme\Template\HomepageData;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$shopUrl = HomepageData::shopUrl();
$skinsUrl = HomepageData::customSkinsUrl();
?>
<section class="djg-home-section djg-home-cta" aria-labelledby="djg-home-cta-title">
    <div class="djg-container">
        <div class="djg-final-cta">
            <div class="djg-final-cta__copy">
                <p class="djg-eyebrow"><?php esc_html_e('READY FOR THE BOOTH', 'djgear-theme'); ?></p>
                <h2 class="djg-final-cta__title" id="djg-home-cta-title">
                    <?php esc_html_e('Browse the DJGear equipment catalog.', 'djgear-theme'); ?>
                </h2>
                <p class="djg-final-cta__lead">
                    <?php esc_html_e('Explore available DJ gear in our store or sign in to configure a custom skin for your setup.', 'djgear-theme'); ?>
                </p>
            </div>
            <div class="djg-final-cta__actions">
                <a class="djg-btn djg-btn--primary djg-btn--lg" href="<?php echo esc_url($shopUrl); ?>">
                    <span><?php esc_html_e('Explore Equipment', 'djgear-theme'); ?></span>
                    <?php Icons::render('arrow-right', 18, 'djg-icon-flip'); ?>
                </a>
                <?php if ($skinsUrl !== '') : ?>
                    <a class="djg-btn djg-btn--secondary djg-btn--lg" href="<?php echo esc_url($skinsUrl); ?>">
                        <?php esc_html_e('Explore Custom Skins', 'djgear-theme'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
