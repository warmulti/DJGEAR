<?php

declare(strict_types=1);

namespace DJGear\Theme\Template;

/**
 * Accessible breadcrumb renderer using native WooCommerce breadcrumbs when active,
 * falling back to clean WordPress hierarchy markup on non-WooCommerce pages.
 */
final class Breadcrumbs
{
    public static function render(): void
    {
        if (is_front_page()) {
            return;
        }

        if (function_exists('woocommerce_breadcrumb') && function_exists('is_woocommerce') && is_woocommerce()) {
            woocommerce_breadcrumb();
            return;
        }

        $items = [
            [
                'label' => __('Home', 'djgear-theme'),
                'url'   => home_url('/'),
            ],
        ];

        if (is_singular()) {
            $items[] = [
                'label' => get_the_title(),
                'url'   => '',
            ];
        } elseif (is_search()) {
            $items[] = [
                'label' => sprintf(
                    /* translators: %s: search query */
                    __('Search: %s', 'djgear-theme'),
                    get_search_query()
                ),
                'url' => '',
            ];
        } elseif (is_archive()) {
            $items[] = [
                'label' => wp_strip_all_tags(get_the_archive_title()),
                'url'   => '',
            ];
        } elseif (is_404()) {
            $items[] = [
                'label' => __('Page Not Found', 'djgear-theme'),
                'url'   => '',
            ];
        }

        echo '<nav class="djg-breadcrumbs" aria-label="' . esc_attr__('Breadcrumb', 'djgear-theme') . '">';
        echo '<ol class="djg-breadcrumbs__list">';

        $total = count($items);
        foreach ($items as $index => $item) {
            $isLast = ($index === $total - 1);
            echo '<li class="djg-breadcrumbs__item">';
            if (!$isLast && $item['url'] !== '') {
                echo '<a class="djg-breadcrumbs__link" href="' . esc_url($item['url']) . '">' . esc_html($item['label']) . '</a>';
                echo '<span class="djg-breadcrumbs__sep" aria-hidden="true">/</span>';
            } else {
                echo '<span class="djg-breadcrumbs__current" aria-current="page">' . esc_html($item['label']) . '</span>';
            }
            echo '</li>';
        }

        echo '</ol></nav>';
    }
}
