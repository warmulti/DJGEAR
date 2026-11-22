<?php

declare(strict_types=1);

use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$hasWc = class_exists('WooCommerce');
?>
<div
    class="djg-overlay"
    id="djg-search-overlay"
    data-djg-overlay="search"
    role="dialog"
    aria-modal="true"
    aria-labelledby="djg-search-overlay-title"
    hidden
>
    <div class="djg-overlay__backdrop" data-djg-overlay-close></div>
    <div class="djg-overlay__panel">
        <div class="djg-overlay__header">
            <h2 class="djg-overlay__title" id="djg-search-overlay-title">
                <?php esc_html_e('Search Equipment & Skins', 'djgear-theme'); ?>
            </h2>
            <button
                type="button"
                class="djg-icon-btn"
                data-djg-overlay-close
                aria-label="<?php esc_attr_e('Close search', 'djgear-theme'); ?>"
            >
                <?php Icons::render('close', 20); ?>
            </button>
        </div>

        <form class="djg-search-form" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
            <label class="djg-sr-only" for="djg-search-input">
                <?php esc_html_e('Search for DJ controllers, mixers, headphones, or skins', 'djgear-theme'); ?>
            </label>
            <div class="djg-search-form__field">
                <span class="djg-search-form__icon"><?php Icons::render('search', 18); ?></span>
                <input
                    id="djg-search-input"
                    class="djg-input djg-search-form__input"
                    type="search"
                    name="s"
                    value="<?php echo esc_attr(get_search_query()); ?>"
                    placeholder="<?php esc_attr_e('Search CDJ, DDJ-FLX4, mixers, skins...', 'djgear-theme'); ?>"
                    autocomplete="off"
                >
                <?php if ($hasWc) : ?>
                    <input type="hidden" name="post_type" value="product">
                <?php endif; ?>
                <button type="submit" class="djg-btn djg-btn--primary">
                    <?php esc_html_e('Search', 'djgear-theme'); ?>
                </button>
            </div>
        </form>
    </div>
</div>
