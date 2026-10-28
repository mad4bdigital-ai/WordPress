<?php
/**
 * Scoped native JetEngine Dynamic Field presentation adapter.
 * Never writes stored price/rate metadata or changes the source record.
 * A query_var named start_price is not necessarily a price widget.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

add_filter('jet-engine/listings/dynamic-field/custom-value', static function($value,$settings,$renderer=null){
    if ($value!==null || !is_array($settings) || ($settings['dynamic_field_source']??'')!=='meta') return $value;
    $field=(string)($settings['dynamic_field_post_meta']??'');
    if (!in_array($field,['start_price','tour_start_price'],true)) return $value;
    if (!function_exists('pricing_post_currency_display') || !function_exists('jet_engine')) return $value;
    $listing=jet_engine()->listings->data??null;
    if (!$listing || !method_exists($listing,'get_current_object_id')) return $value;
    $id=absint($listing->get_current_object_id());
    if (!$id || get_post_type($id)!=='tours-and-activities' || get_post_status($id)!=='publish') return $value;
    return pricing_post_currency_display($id);
},30,3);
