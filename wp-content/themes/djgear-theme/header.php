<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="djg-skip-link" href="#main-content">
    <?php esc_html_e('Skip to main content', 'djgear-theme'); ?>
</a>
<div class="djg-site" id="page">
    <?php get_template_part('template-parts/header/site-header'); ?>
    <main class="djg-main" id="main-content" tabindex="-1">
