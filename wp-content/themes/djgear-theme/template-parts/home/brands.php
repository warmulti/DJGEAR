<?php

declare(strict_types=1);

use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Template\HomepageData;

if (!defined('ABSPATH')) {
    exit;
}

$brands = HomepageData::brands(12);
if ($brands === []) {
    // Intentionally stay hidden until a real populated brand taxonomy source exists.
    return;
}
?>
<section class="djg-home-section djg-home-brands" aria-labelledby="djg-home-brands-title">
    <div class="djg-container">
        <div class="djg-section-head">
            <div>
                <p class="djg-eyebrow"><?php esc_html_e('MANUFACTURERS', 'djgear-theme'); ?></p>
                <h2 class="djg-section-title" id="djg-home-brands-title">
                    <?php esc_html_e('Available brands', 'djgear-theme'); ?>
                </h2>
            </div>
        </div>

        <ul class="djg-brand-strip">
            <?php foreach ($brands as $brand) : ?>
                <li class="djg-brand-strip__item">
                    <a class="djg-brand-pill" href="<?php echo esc_url($brand['url']); ?>">
                        <?php if ($brand['logo_url'] !== '') : ?>
                            <img
                                class="djg-brand-pill__logo"
                                src="<?php echo esc_url($brand['logo_url']); ?>"
                                alt="<?php echo esc_attr($brand['name']); ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php endif; ?>
                        <span class="djg-brand-pill__name"><?php echo esc_html($brand['name']); ?></span>
                        <span class="djg-brand-pill__count djg-tabular">
                            <?php echo esc_html(I18n::formatCount($brand['count'])); ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
