<?php
/**
 * Wayfatours Presentation Runtime: do not present Tour Duration choices as
 * reusable Cruise Programs. This is a widget visibility/filter-option guard,
 * not a Price/Booking authorizer. The canonical Commerce rate-facet engine
 * remains the authority for real customer program choice when activated.
 */
if (!defined('ABSPATH')) { return; }
if (!(defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true)) { return; }
if (defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true) { return; }

// This read-only mode predicate also keeps the existing Cruise itinerary
// contract correctly classified under the separate Presentation Runtime.
// Do not load the Commerce module or infer Programs from Duration taxonomy.
if (!function_exists('shared_product_program_mode_supported')) {
    function shared_product_program_mode_supported($mode): bool {
        return is_scalar($mode) && (string)$mode === 'cruise_tour';
    }
}

function wayfa_presentation_program_tour_20261107(): int {
    // Avoid treating nested Elementor Listing posts or raw request IDs as Tour authority.
    if (function_exists('shared_presentation_primary_tour_post_v1')) {
        $post=shared_presentation_primary_tour_post_v1();
        if ($post instanceof WP_Post && $post->post_type === 'tours-and-activities' && $post->post_status === 'publish') {
            return (int)$post->ID;
        }
    }
    global $wp_the_query;
    if (!($wp_the_query instanceof WP_Query) || !$wp_the_query->is_singular('tours-and-activities')) { return 0; }
    $post=$wp_the_query->get_queried_object();
    return ($post instanceof WP_Post && $post->post_type === 'tours-and-activities' && $post->post_status === 'publish') ? (int)$post->ID : 0;
}

function wayfa_presentation_program_eligible_20261107(int $tour): bool {
    if (!$tour || (string)get_post_meta($tour,'tour_mode',true)!=='cruise_tour') { return false; }
    // A Tour-post itinerary is not a separate selectable Program. Two or more
    // valid reusable programs AND real eligible per-program rates are required.
    // Without the native verified Rate eligibility engine, fail closed rather
    // than approximating it from historic duration terms or prices.
    if (!function_exists('shared_product_tour_itinerary_contract_v1') ||
        !function_exists('shared_product_eligible_tour_rate_ids') ||
        !function_exists('shared_product_tour_facets')) { return false; }
    $contract=shared_product_tour_itinerary_contract_v1($tour);
    if (empty($contract['customer_program_choice'])) { return false; }
    $facets=shared_product_tour_facets($tour);
    return !empty($facets['show_program']) && !empty($facets['programs']);
}

add_filter('elementor/frontend/widget/should_render', static function($should,$widget) {
    if (!$should || !is_object($widget) || !method_exists($widget,'get_name') ||
        $widget->get_name() !== 'jet-smart-filters-select' || !method_exists($widget,'get_settings')) { return $should; }
    $value=$widget->get_settings('filter_id');
    $filterId=absint(is_array($value)?reset($value):$value);
    if (!$filterId || (string)get_post_meta($filterId,'_query_var',true) !== 'related_program_term_id') { return $should; }
    $queryId=(string)$widget->get_settings('query_id');
    if (!in_array($queryId,['tour_rates','tour_rates_accordion'],true)) { return $should; }
    $tour=wayfa_presentation_program_tour_20261107();
    if (!$tour) { return $should; }
    return wayfa_presentation_program_eligible_20261107($tour);
}, 109, 2);

add_filter('jet-smart-filters/filters/filter-options', static function($options,$filterId) {
    $id=absint($filterId);
    if (!$id || (string)get_post_meta($id,'_query_var',true) !== 'related_program_term_id') { return $options; }
    $tour=wayfa_presentation_program_tour_20261107();
    if (!$tour || wayfa_presentation_program_eligible_20261107($tour)) { return $options; }
    return [''=>'Select Program'];
}, 220, 2);
