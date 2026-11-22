<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="djg-container djg-page-shell">
    <?php get_template_part('template-parts/components/breadcrumbs'); ?>

    <?php
    while (have_posts()) :
        the_post();
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('djg-page-article'); ?>>
            <header class="djg-page-header">
                <h1 class="djg-page-title"><?php the_title(); ?></h1>
            </header>
            <div class="djg-entry-content">
                <?php
                the_content();
                wp_link_pages([
                    'before' => '<nav class="djg-page-links">' . esc_html__('Pages:', 'djgear-theme'),
                    'after'  => '</nav>',
                ]);
                ?>
            </div>
        </article>
    <?php endwhile; ?>
</div>
<?php
get_footer();
