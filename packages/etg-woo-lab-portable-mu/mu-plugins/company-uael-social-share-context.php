<?php
/**
 * Lab compatibility adapter for UAEL SocialShare and archive contexts.
 * The widget's get_count_html() reads global $post->ID without checking it;
 * Elementor archive and Jet Dropbar templates legitimately have no post.
 * Override that one method through Elementor's native widget registry.
 * Do not alter vendor files, share URLs, widget settings or site content.
 */
defined('ABSPATH') || exit;


add_action('elementor/widgets/register', static function($manager) {
    $parent = '\\UltimateElementor\\Modules\\SocialShare\\Widgets\\SocialShare';
    if (!class_exists($parent) || !($manager instanceof \Elementor\Widgets_Manager)) return;
    $registered=$manager->get_widget_types('uael-social-share');
    if (!($registered instanceof $parent)) return;
    if (!class_exists('MAD4B_Local_UAEL_SocialShare_Context_v1', false)) {
        class MAD4B_Local_UAEL_SocialShare_Context_v1 extends \UltimateElementor\Modules\SocialShare\Widgets\SocialShare {
            public function get_count_html($settings,$total_result) {
                global $post;
                // Preserve the original rendering for any real source post.
                if ($post instanceof \WP_Post) {
                    parent::get_count_html($settings,$total_result);
                    return;
                }
                // A singular page can be rendered with the global reset to null
                // by a nested template. Resolve only a genuine queried post.
                $queried=get_queried_object();
                if ($queried instanceof \WP_Post) {
                    $previous=$post;
                    $post=$queried;
                    try {parent::get_count_html($settings,$total_result);}
                    finally {$post=$previous;}
                    return;
                }
                // Archives have no legitimate post ID for per-post fake-count
                // storage. Keep existing markup and authentic aggregate count,
                // but never write a fabricated post meta value.
                echo '<span class="uael-total-share-btn__titlex">';
                if (($settings['show_count']??'no')==='yes') {
                    echo esc_html((string)(int)$total_result);
                }
                echo '</span>';
            }
        }
    }
    $manager->register(new \MAD4B_Local_UAEL_SocialShare_Context_v1());
}, 999);
