<?php

declare(strict_types=1);

use DJGear\Theme\Template\CommerceAccountData;

if (!defined('ABSPATH')) {
    exit;
}

get_header();
$isCommerceAccount = CommerceAccountData::isCommerceAccountPage();
?>
<div class="djg-container djg-page-shell<?php echo $isCommerceAccount ? ' djg-commerce-shell' : ''; ?>">
    <?php get_template_part('template-parts/components/breadcrumbs'); ?>

    <?php
    while (have_posts()) :
        the_post();
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('djg-page-article'); ?>>
            <?php if ($isCommerceAccount) : ?>
                <?php get_template_part('template-parts/commerce/page-header'); ?>
            <?php else : ?>
                <header class="djg-page-header">
                    <h1 class="djg-page-title"><?php the_title(); ?></h1>
                </header>
            <?php endif; ?>
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
