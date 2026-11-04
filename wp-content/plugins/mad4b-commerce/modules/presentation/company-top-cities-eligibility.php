<?php
/** Source-first public destination eligibility for JetEngine Query 288. */
if (!defined('ABSPATH')) exit;
add_filter('jet-engine/query-builder/query/items', static function($items,$query) {
    if (!is_object($query) || (int)($query->id ?? 0)!==288 || ($query->query_type ?? '')!=='terms' || !is_array($items)) return $items;
    static $has_published = [];
    $eligible = [];
    foreach ($items as $term) {
        if (!($term instanceof WP_Term) || $term->taxonomy!=='destinations') continue;
        $id=(int)$term->term_id;
        if (!filter_var(get_term_meta($id,'is_public',true),FILTER_VALIDATE_BOOLEAN)) continue;
        if (!filter_var(get_term_meta($id,'is_featured',true),FILTER_VALIDATE_BOOLEAN)) continue;
        if (!get_term_meta($id,'featured_image',true) && !get_term_meta($id,'thumbnail_id',true)) continue;
        if (!array_key_exists($id,$has_published)) {
            $probe = new WP_Query([
                'post_type'=>'tours-and-activities', 'post_status'=>'publish',
                'posts_per_page'=>1, 'fields'=>'ids', 'no_found_rows'=>true,
                'ignore_sticky_posts'=>true, 'update_post_meta_cache'=>false,
                'update_post_term_cache'=>false, 'cache_results'=>true,
                'tax_query'=>[['taxonomy'=>'destinations','field'=>'term_id','terms'=>[$id],'include_children'=>true]],
            ]);
            $has_published[$id]=!empty($probe->posts);
        }
        if ($has_published[$id]) $eligible[]=$term;
    }
    return $eligible;
}, 40, 2);
