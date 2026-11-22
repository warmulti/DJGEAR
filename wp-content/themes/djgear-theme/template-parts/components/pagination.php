<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

the_posts_pagination([
    'mid_size'           => 2,
    'prev_text'          => esc_html__('Previous', 'djgear-theme'),
    'next_text'          => esc_html__('Next', 'djgear-theme'),
    'screen_reader_text' => esc_html__('Posts navigation', 'djgear-theme'),
    'class'              => 'djg-pagination',
]);
