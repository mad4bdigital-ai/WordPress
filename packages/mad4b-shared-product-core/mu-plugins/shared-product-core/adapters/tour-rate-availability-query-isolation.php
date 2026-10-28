<?php
/** Lab-only: Query 313 determines whether tour rates exist; it must not be filtered by the active package UI. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_filter('jet-engine/query-builder/filters/is-filters-request',static function($is_filter_request,$query){
 if (($query->query_id ?? '') !== 'tour_rate_single_tour') return $is_filter_request;
 if (!function_exists('jet_smart_filters')) return $is_filter_request;
 $request=jet_smart_filters()->data->get_request();
 $provider=$request['jsf'] ?? $request['provider'] ?? '';
 if ($provider!=='jet-engine:tour_rates' && $provider!=='jet-engine/tour_rates') return $is_filter_request;
 return false;
},30,2);
