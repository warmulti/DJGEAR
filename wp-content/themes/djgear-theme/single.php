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
        <article id="post-<?php the_ID(); ?>" <?php post_class('djg-single-article'); ?>>
            <header class="djg-page-header">
                <h1 class="djg-page-title"><?php the_title(); ?></h1>
                <p class="djg-post-meta">
                    <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>">
                        <?php echo esc_html(get_the_date()); ?>
                    </time>
                </p>
            </header>
            <?php if (has_post_thumbnail()) : ?>
                <figure class="djg-single-media">
                    <?php the_post_thumbnail('large'); ?>
                </figure>
            <?php endif; ?>
            <div class="djg-entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</div>
<?php
get_footer();
