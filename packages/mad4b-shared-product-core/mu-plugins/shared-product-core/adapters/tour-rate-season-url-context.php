<?php
/** Lab-only Query 310 season filter on full-page reload, preserving rate query and eligibility. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
 if (is_admin() || wp_doing_ajax() || ($query->query_id??'')!=='tour_rates') return $args;
 if (($_GET['jsf']??'')!=='jet-engine:tour_rates') return $args;
 if (get_post_type(get_queried_object_id())!=='tours-and-activities') return $args;
 $raw=$_REQUEST['_tax_query_season']??null;
 if (!is_scalar($raw) || !preg_match('/^[0-9]+$/D',(string)$raw)) return $args;
 $term_id=absint($raw);
 if (!$term_id || !term_exists($term_id,'season')) return $args;
 if (!isset($args['tax_query']) || !is_array($args['tax_query'])) $args['tax_query']=[];
 $args['tax_query'][]=['taxonomy'=>'season','field'=>'term_id','terms'=>[$term_id],'operator'=>'IN'];
 return $args;
},25,2);
