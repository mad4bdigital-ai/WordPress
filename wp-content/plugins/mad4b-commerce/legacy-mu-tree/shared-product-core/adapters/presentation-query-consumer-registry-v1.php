<?php
/**
 * REV103 read-only discovery bridge for existing native JetEngine queries.
 * It never owns Query Builder, Elementor, Fluent Forms or commercial data.
 * Slots are explicit to prevent Tour Rate visibility logic leaking into blog.
 */
defined('ABSPATH') || exit;

add_filter('shared_presentation_registry_v2', static function(array $providers): array {
    $slots = [
        'tours.rates.options' => [
            'slot' => 'tour.single.rates',
            'provider' => 'jet-engine/tour_rates',
            'query_id' => 310,
            'listing_id' => 46193,
            'entity_type' => 'tour-rates',
            'parent_type' => 'tours-and-activities',
            'eligibility_source' => 'shared_product_eligible_tour_rate_ids',
            'behavior' => 'filtered_rate_listing',
            'runtime_mutation' => false,
        ],
        'tours.rates.accordion' => [
            'slot' => 'tour.single.rates.accordion',
            'provider' => 'jet-engine/tour_rates_accordion',
            'query_id' => 310,
            'listing_id' => 0,
            'entity_type' => 'tour-rates',
            'parent_type' => 'tours-and-activities',
            'eligibility_source' => 'shared_product_eligible_tour_rate_ids',
            'behavior' => 'filtered_rate_accordion',
            'runtime_mutation' => false,
        ],
        'tours.rates.availability' => [
            'slot' => 'tour.single.rate_visibility',
            'provider' => 'jet-engine/tour_rate_single_tour',
            'query_id' => 313,
            'listing_id' => 0,
            'entity_type' => 'tour-rates',
            'parent_type' => 'tours-and-activities',
            'eligibility_source' => 'shared_product_eligible_tour_rate_ids',
            'behavior' => 'unfiltered_eligible_rate_existence',
            'runtime_mutation' => false,
        ],
        'tours.home.recommended' => [
            'slot' => 'tour.home.editorial_recommendations',
            'provider' => 'jet-engine/featured_home_tours_query',
            'query_id' => 193,
            'listing_id' => 14962,
            'entity_type' => 'tours-and-activities',
            'owner' => 'jetengine_elementor',
            'behavior' => 'featured_editorial_fallback_not_personalized',
            'replaces_missing_query_id' => 207,
            'runtime_mutation' => false,
        ],
        'home.recommended.tours' => [
            'slot' => 'home.recommended.tour_cards',
            'provider' => 'jet-engine/featured_home_tours_query',
            'query_id' => 193,
            'listing_id' => 14962,
            'entity_type' => 'tours-and-activities',
            'owner' => 'jetengine_query_builder',
            'selection' => 'editorial_featured_not_behavioral_personalization',
            'price_source' => 'shared_lab_tour_price_resolve',
            'runtime_mutation' => false,
        ],
        'blog.category.facet' => [
            'slot' => 'blog.archive.category_filter',
            'provider' => 'jet-engine/posts_query_archive',
            'query_id' => 76,
            'listing_id' => 16215,
            'entity_type' => 'post',
            'taxonomy' => 'category',
            'filter_ids' => ['en' => 16374, 'ar' => 42320],
            'owner' => 'jet_smart_filters',
            'runtime_mutation' => false,
        ],
        'blog.archive' => [
            'slot' => 'blog.archive.results',
            'provider' => 'jet-engine/posts_query_archive',
            'query_id' => 76,
            'listing_id' => 16215,
            'entity_type' => 'post',
            'owner' => 'wordpress_jetengine',
            'behavior' => 'blog_archive',
            'source_alias_status' => 'signed_query_76_bound__ajax_behavior_pending',
            'runtime_mutation' => false,
        ],
        'blog.featured' => [
            'slot' => 'blog.home.featured',
            'provider' => 'jet-engine/featured_posts_query',
            'query_id' => 74,
            'listing_id' => 16271,
            'entity_type' => 'post',
            'owner' => 'wordpress_jetengine',
            'behavior' => 'featured_post_listing',
            'runtime_mutation' => false,
        ],
        'blog.single' => [
            'slot' => 'blog.post.article',
            'provider' => 'wordpress/singular/post',
            'query_id' => 0,
            'listing_id' => 0,
            'template_id' => 30712,
            'entity_type' => 'post',
            'owner' => 'elementor_theme_builder',
            'behavior' => 'singular_post_document',
            'runtime_mutation' => false,
        ],
    ];
    // Deliberately fail closed on name collisions; do not silently override a
    // registry descriptor created by another tenant/module.
    foreach ($slots as $key => $value) {
        if (!array_key_exists($key, $providers)) $providers[$key] = $value;
    }
    return $providers;
}, 20);
