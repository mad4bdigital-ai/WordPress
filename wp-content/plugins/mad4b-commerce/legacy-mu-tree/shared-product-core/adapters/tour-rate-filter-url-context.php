<?php
/** runtime-authorized: preserve JetSmartFilters package choice in Query Builder 310 on URL reload. */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;
add_filter('jet-engine/query-builder/types/posts-query/args', static function ($args,$query) {
 if(function_exists('shared_product_is_rate_facet_query') && shared_product_is_rate_facet_query($query))return $args;
 if (is_admin() || wp_doing_ajax() || ($query->query_id ?? '') !== 'tour_rates') return $args;
 if (!in_array($_REQUEST['jsf']??'', ['jet-engine:tour_rates','jet-engine:tour_rates_accordion'],true))return $args;
 $tour_id=shared_product_rate_facet_tour_id();
 if (get_post_type($tour_id)!=='tours-and-activities') return $args;
 $value=$_REQUEST['_meta_query_related_package_term_id'] ?? null;
 if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D',(string)$value)) return $args;
 $tier=absint($value);
 if (!term_exists($tier,'package_levels')) return $args;
 if (!isset($args['meta_query']) || !is_array($args['meta_query'])) $args['meta_query']=[];
 foreach($args['meta_query']as$row)if(is_array($row)&&($row['key']??'')==='related_package_term_id'&&(string)($row['value']??'')===(string)$tier)return $args;
 $args['meta_query'][]=['key'=>'related_package_term_id','value'=>(string)$tier,'compare'=>'='];
 return $args;
}, 25, 2);
