<?php
/**
 * REV110 — Separate Destination editorial visibility from commercial offers.
 * Company source approval is checked at the exact native listing boundary.
 */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='local'
 || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY)return;

function shared_presentation_destination_editorial_allowed_v1(int $termId):bool{
    $term=get_term($termId,'destinations');
    if(!$term instanceof WP_Term)return false;
    $public=(string)get_term_meta($termId,'is_public',true);
    if(in_array($public,['0','false','disabled','no'],true))return false;
    return function_exists('destinations_activation_allowed')
        && destinations_activation_allowed($termId);
}
function shared_presentation_destination_commercial_allowed_v1(int $termId):bool{
    if(!shared_presentation_destination_editorial_allowed_v1($termId))return false;
    return function_exists('shared_presentation_native_destination_sale_authorized_v1')
        && shared_presentation_native_destination_sale_authorized_v1((string)$termId);
}
function shared_presentation_destination_requested_term_v1():int{
    if(wp_doing_ajax()){
        if(function_exists('company_ajax_destination_term')){
            $term=company_ajax_destination_term();
            return $term instanceof WP_Term?(int)$term->term_id:0;
        }
        return 0;
    }
    if(is_tax('destinations')){
        $term=get_queried_object();
        return $term instanceof WP_Term?(int)$term->term_id:0;
    }
    return 0;
}
// The archive router includes taxonomy-destinations.php at priority -10000;
// protected destinations must be denied BEFORE its include/exit.
add_action('template_redirect',static function():void{
    if(is_admin()||wp_doing_ajax()||!is_tax('destinations'))return;
    if(current_user_can('edit_posts') && (is_preview()||isset($_GET['elementor-preview'])))return;
    $termId=shared_presentation_destination_requested_term_v1();
    if($termId && shared_presentation_destination_editorial_allowed_v1($termId))return;
    global $wp_query;
    if($wp_query instanceof WP_Query)$wp_query->set_404();
    status_header(404);
    nocache_headers();
    $template=get_404_template();
    if(is_readable($template))include $template;
    else wp_die('Destination unavailable','Not Found',['response'=>404]);
    exit;
},-11000);
// Query Builder: exact linked Tours listing on Destination term pages.
// Never rewrite shared Tour Archive Query #5, other post types, or admin queries.
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
    if(!is_array($args)|| !is_object($query)
        || (string)($query->query_id??'')!=='single_destination_term_archive_related_tours'
        || (int)($query->id??0)!==273
        || (!wp_doing_ajax() && (is_admin() || !is_tax('destinations'))))return $args;
    $termId=shared_presentation_destination_requested_term_v1();
    if(!$termId || !shared_presentation_destination_commercial_allowed_v1($termId))
        $args['post__in']=[0];
    return $args;
},2500,2);
// Native discovery Activity Listing Grid is not a Query Builder entry.
// Apply the same commercial company source at its exact element_id only.
add_filter('jet-engine/listing/grid/posts-query-args',static function($args,$renderer,$settings){
    if(!is_array($args)||!is_array($settings)
        ||($settings['_element_id']??'')!=='registry_destination_activity_grid')return $args;
    $termId=shared_presentation_destination_requested_term_v1();
    if(!$termId || !shared_presentation_destination_commercial_allowed_v1($termId))
        $args['post__in']=[0];
    return $args;
},2500,3);

// A destination may host reused homepage Featured Tours listings. Their
// original source is legitimate on Home but not on an editorial-only country.
// For direct term GETs, deny every JetEngine posts query actually targeting
// Tour products; leave other content and all other routes untouched.
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
    if(!is_array($args)||!is_object($query)||is_admin()
       ||wp_doing_ajax()||!is_tax('destinations'))return $args;
    $termId=shared_presentation_destination_requested_term_v1();
    if($termId && shared_presentation_destination_commercial_allowed_v1($termId))return $args;
    if(($query->query_type??'')!=='posts')return $args;
    $declared=(array)($query->query['post_type']??[]);
    if(!in_array('tours-and-activities',$declared,true))return $args;
    $args['post__in']=[0];
    return $args;
},2600,2);
