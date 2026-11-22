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
        <h1 class="djg-page-title">
            <?php
            printf(
                /* translators: %s: search query */
                esc_html__('Search results for "%s"', 'djgear-theme'),
                esc_html(get_search_query())
            );
            ?>
        </h1>
    </header>

    <?php if (have_posts()) : ?>
        <div class="djg-post-grid">
            <?php
            while (have_posts()) :
                the_post();
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('djg-card djg-post-card'); ?>>
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
            <p><?php esc_html_e('No matching products or pages were found. Try a different model code or keyword.', 'djgear-theme'); ?></p>
        </section>
    <?php endif; ?>
</div>
<?php
get_footer();
