<?php
/** Deprecated compatibility only: retain previous public function names until old forms are migrated. */
if (!defined('ABSPATH')) exit;
if (!function_exists('tk_tour_date_filter_post_type')) {
 function tk_tour_date_filter_post_type() { return shared_product_program_post_type(); }
}
if (!function_exists('tk_tour_date_filter_taxonomy')) {
 function tk_tour_date_filter_taxonomy() { return shared_product_program_weekday_taxonomy(); }
}
if (!function_exists('tk_tour_date_filter_reference_lang')) {
 function tk_tour_date_filter_reference_lang() { return shared_product_program_reference_language(); }
}
if (!function_exists('tk_tour_date_filter_map_term_slug_to_reference')) {
 function tk_tour_date_filter_map_term_slug_to_reference($term,$taxonomy) { return shared_product_program_weekday_term_slug($term,$taxonomy); }
}
if (!function_exists('tk_tour_date_filter_allowed_days')) {
 function tk_tour_date_filter_allowed_days($post_id) { return shared_product_program_weekdays($post_id); }
}
if (!function_exists('tk_tour_date_filter_enqueue_flatpickr_locales')) {
 function tk_tour_date_filter_enqueue_flatpickr_locales() { return shared_product_program_enqueue_calendar_locales(); }
}
if (!function_exists('tk_tour_date_filter_enqueue_assets')) {
 function tk_tour_date_filter_enqueue_assets() { return shared_product_program_enqueue_calendar_assets(); }
}
