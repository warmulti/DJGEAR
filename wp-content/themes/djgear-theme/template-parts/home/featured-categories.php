<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Template\HomepageData;
use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$categories = HomepageData::featuredCategories(6);
if ($categories === []) {
    return;
}

$shopUrl = HomepageData::shopUrl();
?>
<section class="djg-home-section djg-home-categories" aria-labelledby="djg-home-categories-title">
    <div class="djg-container">
        <div class="djg-section-head">
            <div>
                <p class="djg-eyebrow"><?php esc_html_e('EQUIPMENT CATEGORIES', 'djgear-theme'); ?></p>
                <h2 class="djg-section-title" id="djg-home-categories-title">
                    <?php esc_html_e('Shop by category', 'djgear-theme'); ?>
                </h2>
            </div>
            <a class="djg-section-link" href="<?php echo esc_url($shopUrl); ?>">
                <span><?php esc_html_e('View all categories', 'djgear-theme'); ?></span>
                <?php Icons::render('chevron-right', 16, 'djg-icon-flip'); ?>
            </a>
        </div>

        <div class="djg-category-grid">
            <?php foreach ($categories as $category) : ?>
                <a class="djg-category-card" href="<?php echo esc_url($category['url']); ?>">
                    <div class="djg-category-card__media">
                        <?php if ($category['image_url'] !== '') : ?>
                            <img
                                src="<?php echo esc_url($category['image_url']); ?>"
                                alt="<?php echo esc_attr($category['image_alt']); ?>"
                                width="640"
                                height="480"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php else : ?>
                            <span class="djg-category-card__placeholder" aria-hidden="true">
                                <?php Icons::render('sliders', 32); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="djg-category-card__body">
                        <div class="djg-category-card__top">
                            <h3 class="djg-category-card__title"><?php echo esc_html($category['name']); ?></h3>
                            <span class="djg-category-card__count djg-tabular">
                                <?php
                                printf(
                                    /* translators: %s: localized product count */
                                    esc_html(_n('%s item', '%s items', $category['count'], 'djgear-theme')),
                                    esc_html(I18n::formatCount($category['count']))
                                );
                                ?>
                            </span>
                        </div>
                        <?php if ($category['description'] !== '') : ?>
                            <p class="djg-category-card__desc"><?php echo esc_html(wp_trim_words($category['description'], 16)); ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
