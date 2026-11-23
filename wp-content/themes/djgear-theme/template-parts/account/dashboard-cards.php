<?php

declare(strict_types=1);

use DJGear\Theme\Template\Icons;

if (!defined('ABSPATH')) {
    exit;
}

/** @var list<array{title: string, description: string, url: string, icon: string, badge: string}> $cards */
$cards = is_array($args['cards'] ?? null) ? $args['cards'] : [];
/** @var list<array{number: string, date: string, status_slug: string, status_label: string, total_html: string, view_url: string}> $recentOrders */
$recentOrders = is_array($args['recent_orders'] ?? null) ? $args['recent_orders'] : [];

if ($cards === []) {
    return;
}
?>
<div class="djg-account-overview">
    <div class="djg-account-cards">
        <?php foreach ($cards as $card) : ?>
            <a class="djg-account-card" href="<?php echo esc_url($card['url']); ?>">
                <span class="djg-account-card__icon" aria-hidden="true">
                    <?php Icons::render($card['icon'], 20); ?>
                </span>
                <div class="djg-account-card__body">
                    <div class="djg-account-card__head">
                        <h2 class="djg-account-card__title"><?php echo esc_html($card['title']); ?></h2>
                        <?php if ($card['badge'] !== '') : ?>
                            <span class="djg-account-card__badge djg-tabular"><?php echo esc_html($card['badge']); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="djg-account-card__desc"><?php echo esc_html($card['description']); ?></p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($recentOrders !== []) : ?>
        <section class="djg-account-recent" aria-labelledby="djg-account-recent-title">
            <div class="djg-account-recent__head">
                <h2 class="djg-account-recent__title" id="djg-account-recent-title">
                    <?php esc_html_e('Recent orders', 'djgear-theme'); ?>
                </h2>
                <?php if (function_exists('wc_get_account_endpoint_url')) : ?>
                    <a class="djg-section-link" href="<?php echo esc_url((string) wc_get_account_endpoint_url('orders')); ?>">
                        <span><?php esc_html_e('View all orders', 'djgear-theme'); ?></span>
                        <?php Icons::render('chevron-right', 16, 'djg-icon-flip'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="djg-account-recent__list">
                <?php foreach ($recentOrders as $order) : ?>
                    <div class="djg-account-order-row">
                        <div class="djg-account-order-row__primary">
                            <span class="djg-account-order-row__num djg-ltr-isolate">#<?php echo esc_html($order['number']); ?></span>
                            <span class="djg-account-order-row__date"><?php echo esc_html($order['date']); ?></span>
                        </div>
                        <span class="djg-order-status djg-order-status--<?php echo esc_attr(sanitize_html_class($order['status_slug'])); ?>">
                            <?php echo esc_html($order['status_label']); ?>
                        </span>
                        <span class="djg-account-order-row__total djg-tabular">
                            <?php echo wp_kses_post($order['total_html']); ?>
                        </span>
                        <a class="djg-btn djg-btn--secondary djg-account-order-row__btn" href="<?php echo esc_url($order['view_url']); ?>">
                            <?php esc_html_e('View', 'djgear-theme'); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
