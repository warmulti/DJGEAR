<?php

declare(strict_types=1);

use DJGear\Theme\Template\HomepageData;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$shopUrl = HomepageData::shopUrl();
$skinsUrl = HomepageData::customSkinsUrl();
$heroImg = HomepageData::heroImage();
?>
<section class="djg-hero" aria-labelledby="djg-hero-title">
    <div class="djg-container djg-hero__grid">
        <div class="djg-hero__content">
            <p class="djg-eyebrow"><?php esc_html_e('DJGEAR · PRO AUDIO & CUSTOM SKINS', 'djgear-theme'); ?></p>
            <h1 class="djg-hero__title" id="djg-hero-title">
                <?php esc_html_e('Precision DJ hardware & bespoke equipment skins.', 'djgear-theme'); ?>
            </h1>
            <p class="djg-hero__lead">
                <?php esc_html_e('Curated controllers, media players, mixers, and studio essentials alongside a dedicated custom skin ordering experience tailored to compatible DJ models.', 'djgear-theme'); ?>
            </p>

            <div class="djg-hero__actions">
                <a class="djg-btn djg-btn--primary djg-btn--lg" href="<?php echo esc_url($shopUrl); ?>">
                    <span><?php esc_html_e('Explore Equipment', 'djgear-theme'); ?></span>
                    <?php Icons::render('arrow-right', 18, 'djg-icon-flip'); ?>
                </a>
                <?php if ($skinsUrl !== '') : ?>
                    <a class="djg-btn djg-btn--secondary djg-btn--lg" href="<?php echo esc_url($skinsUrl); ?>">
                        <?php Icons::render('layers', 18); ?>
                        <span><?php esc_html_e('Explore Custom Skins', 'djgear-theme'); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="djg-hero__media">
            <div class="djg-hero__frame">
                <img
                    class="djg-hero__image"
                    src="<?php echo esc_url($heroImg['url']); ?>"
                    alt="<?php echo esc_attr($heroImg['alt']); ?>"
                    width="<?php echo (int) $heroImg['width']; ?>"
                    height="<?php echo (int) $heroImg['height']; ?>"
                    fetchpriority="high"
                    decoding="async"
                >
            </div>
        </div>
    </div>
</section>
