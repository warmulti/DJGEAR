<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="djg-container djg-page-shell">
    <?php get_template_part('template-parts/components/breadcrumbs'); ?>

    <header class="djg-page-header">
        <h1 class="djg-page-title"><?php the_archive_title(); ?></h1>
        <?php the_archive_description('<div class="djg-page-lead">', '</div>'); ?>
    </header>

    <?php if (have_posts()) : ?>
        <div class="djg-post-grid">
            <?php
            while (have_posts()) :
                the_post();
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('djg-card djg-post-card'); ?>>
                    <?php if (has_post_thumbnail()) : ?>
                        <a class="djg-post-card__media" href="<?php the_permalink(); ?>">
                            <?php the_post_thumbnail('djg-card-4x3', ['loading' => 'lazy']); ?>
                        </a>
                    <?php endif; ?>
                    <div class="djg-post-card__body">
                        <h2 class="djg-post-card__title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h2>
                        <div class="djg-post-card__excerpt">
                            <?php the_excerpt(); ?>
                        </div>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
        <?php get_template_part('template-parts/components/pagination'); ?>
    <?php else : ?>
        <section class="djg-empty-state">
            <p><?php esc_html_e('No items found in this archive.', 'djgear-theme'); ?></p>
        </section>
    <?php endif; ?>
</div>
<?php
get_footer();
