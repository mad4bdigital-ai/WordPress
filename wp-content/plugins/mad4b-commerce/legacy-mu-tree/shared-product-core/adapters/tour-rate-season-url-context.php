<?php
/** runtime-authorized Query 310 season filter on full-page reload, preserving rate query and eligibility. */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
 if(function_exists('shared_product_is_rate_facet_query') && shared_product_is_rate_facet_query($query))return $args;
 if (is_admin() || wp_doing_ajax() || ($query->query_id??'')!=='tour_rates') return $args;
 if (!in_array($_REQUEST['jsf']??'', ['jet-engine:tour_rates','jet-engine:tour_rates_accordion'],true))return $args;
 if (!shared_product_rate_facet_tour_id()) return $args;
 $raw=$_REQUEST['_tax_query_season']??null;
 if (!is_scalar($raw) || !preg_match('/^[0-9]+$/D',(string)$raw)) return $args;
 $term_id=absint($raw);
 if (!$term_id || !term_exists($term_id,'season')) return $args;
 if (!isset($args['tax_query']) || !is_array($args['tax_query'])) $args['tax_query']=[];
 foreach($args['tax_query']as$row)if(is_array($row)&&($row['taxonomy']??'')==='season'&&in_array($term_id,array_map('intval',(array)($row['terms']??[])),true))return $args;
 $args['tax_query'][]=['taxonomy'=>'season','field'=>'term_id','terms'=>[$term_id],'operator'=>'IN'];
 return $args;
},25,2);
