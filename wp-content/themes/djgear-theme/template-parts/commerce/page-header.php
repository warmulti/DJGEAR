<?php

declare(strict_types=1);

use DJGear\Theme\Template\CommerceAccountData;

if (!defined('ABSPATH')) {
    exit;
}

$ctx = CommerceAccountData::pageHeaderContext();
$steps = [
    1 => [
        'label' => __('Cart', 'djgear-theme'),
        'url'   => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
    ],
    2 => [
        'label' => __('Checkout', 'djgear-theme'),
        'url'   => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
    ],
    3 => [
        'label' => __('Confirmation', 'djgear-theme'),
        'url'   => '',
    ],
];
?>
<header class="djg-commerce-header">
    <div class="djg-commerce-header__copy">
        <p class="djg-eyebrow"><?php echo esc_html($ctx['eyebrow']); ?></p>
        <h1 class="djg-page-title"><?php echo esc_html($ctx['title']); ?></h1>
        <?php if ($ctx['subtitle'] !== '') : ?>
            <p class="djg-commerce-header__subtitle"><?php echo esc_html($ctx['subtitle']); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($ctx['show_stepper']) : ?>
        <nav class="djg-checkout-stepper" aria-label="<?php esc_attr_e('Checkout progress', 'djgear-theme'); ?>">
            <ol class="djg-checkout-stepper__list">
                <?php foreach ($steps as $num => $step) : ?>
                    <?php
                    $isCurrent = ($num === $ctx['active_step']);
                    $isDone = ($num < $ctx['active_step']);
                    $stateClass = $isCurrent ? 'is-current' : ($isDone ? 'is-done' : 'is-upcoming');
                    ?>
                    <li class="djg-checkout-stepper__item <?php echo esc_attr($stateClass); ?>" <?php echo $isCurrent ? 'aria-current="step"' : ''; ?>>
                        <span class="djg-checkout-stepper__num djg-ltr-isolate"><?php echo esc_html('0' . (string) $num); ?></span>
                        <?php if ($isDone && $step['url'] !== '') : ?>
                            <a class="djg-checkout-stepper__label" href="<?php echo esc_url($step['url']); ?>">
                                <?php echo esc_html($step['label']); ?>
                            </a>
                        <?php else : ?>
                            <span class="djg-checkout-stepper__label"><?php echo esc_html($step['label']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>
</header>
