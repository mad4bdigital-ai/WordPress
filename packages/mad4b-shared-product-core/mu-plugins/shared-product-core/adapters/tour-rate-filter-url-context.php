<?php
/** Lab-only: preserve JetSmartFilters package choice in Query Builder 310 on URL reload. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_filter('jet-engine/query-builder/types/posts-query/args', static function ($args,$query) {
 if (is_admin() || wp_doing_ajax() || ($query->query_id ?? '') !== 'tour_rates') return $args;
 if (($_GET['jsf'] ?? '') !== 'jet-engine:tour_rates') return $args;
 $tour_id=get_queried_object_id();
 if (get_post_type($tour_id)!=='tours-and-activities') return $args;
 $value=$_REQUEST['_meta_query_related_package_term_id'] ?? null;
 if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D',(string)$value)) return $args;
 $tier=absint($value);
 if (!term_exists($tier,'package_levels')) return $args;
 if (!isset($args['meta_query']) || !is_array($args['meta_query'])) $args['meta_query']=[];
 $args['meta_query'][]=['key'=>'related_package_term_id','value'=>(string)$tier,'compare'=>'='];
 return $args;
}, 25, 2);
