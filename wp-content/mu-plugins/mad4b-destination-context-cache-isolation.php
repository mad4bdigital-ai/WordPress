<?php
/**
 * Plugin Name: MAD4B Destination Context — Elementor Cache Isolation
 * Description: Prevent Elementor from reusing one destination's HTML for another destination or WPML language.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Elementor's _elementor_element_cache is stored per template ID, not per
 * destination taxonomy term or WPML language. Destination cards and
 * relationship sections are rendered by dynamic JetEngine/Elementor tags and
 * must never reuse the HTML of a different term.
 *
 * Restrict cache bypass to real public destination archive requests. Other
 * page types retain the site's existing Elementor element-cache policy.
 * This changes no templates, term metadata, options, visibility criteria,
 * company brand data or publishing status.
 */
add_filter('pre_option_elementor_element_cache_ttl',static function($value){
    if (is_admin() || !is_tax('destinations')) return $value;
    return 'disable';
},86,1);
