<?php
/**
 * Optional extra_trip eligibility and discovery boundary — Local Lab only.
 * Authority: parent's existing tour_extras_status + tour_optional_extras.
 * Future destination cards must navigate to an eligible PARENT tour, never to
 * the independent extra_trip CPT permalink.
 */
defined('ABSPATH')||exit;
if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;

function registry_extra_trip_parent_active(int $id):bool {
	$post=get_post($id);
	return $post instanceof WP_Post
		&& $post->post_type==='tours-and-activities'
		&& $post->post_status==='publish'
		&& $post->post_password===''
		&& (!in_array((string)get_post_meta($id,'tour_public_status',true),['0','false'],true)
       ||(function_exists('lab_qa_public_tour_view_only_allowed')
          &&lab_qa_public_tour_view_only_allowed($id)))
		&& (string)get_post_meta($id,'tour_mode',true)!=='extra_trip'
		&& (string)get_post_meta($id,'tour_extras_status',true)==='1';
}
function registry_extra_trip_child_active(int $id):bool {
	$post=get_post($id);
	return $post instanceof WP_Post
		&& $post->post_type==='tours-and-activities'
		&& $post->post_status==='publish'
		&& $post->post_password===''
		&& !in_array((string)get_post_meta($id,'tour_public_status',true),['0','false'],true)
		&& (string)get_post_meta($id,'tour_mode',true)==='extra_trip';
}
/** Strictly parse existing JetEngine checkbox/multiple-relational meta. */
function registry_extra_trip_parent_allowed(int $parent_id):array {
	if(!registry_extra_trip_parent_active($parent_id))return [];
	$raw=get_post_meta($parent_id,'tour_optional_extras',true);
	if(is_string($raw))$raw=maybe_unserialize($raw);
	if(is_string($raw))$raw=array_filter(array_map('trim',explode(',',$raw)));
	if(!is_array($raw)||count($raw)>100)return [];
	$result=[];
	foreach($raw as $value){
		if(!is_scalar($value)||!ctype_digit((string)$value))return [];
		$id=absint($value);
		if(!$id||$id===$parent_id)return [];
		if(registry_extra_trip_child_active($id))$result[$id]=$id;
	}
	return array_values($result);
}
/**
 * Read-only reverse relation for a future destination activities listing.
 * Multiple parents are returned independently; never pick an arbitrary owner.
 */
function registry_extra_trip_parent_tours(int $extra_id):array {
	if(!registry_extra_trip_child_active($extra_id))return [];
	// Some existing Local Lab archive hooks alter get_posts() results,
	// even for explicit meta queries. Fetch IDs from the real activation
	// meta authority; validate the entire parent and relation below.
	global $wpdb;
	$parents=$wpdb->get_col($wpdb->prepare(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		  WHERE meta_key=%s AND meta_value=%s",
		'tour_extras_status','1'
	));
	$eligible=[];
	foreach($parents as $parent){
		$parent=absint($parent);
		if(in_array($extra_id,registry_extra_trip_parent_allowed($parent),true))$eligible[]=$parent;
	}
	return $eligible;
}
/** Only a verified (extra,parent) pair can furnish the future View Tour link. */
function registry_extra_trip_view_tour_url(int $extra_id,int $parent_id):string{
	if(!in_array($extra_id,registry_extra_trip_parent_allowed($parent_id),true))return '';
	$link=get_permalink($parent_id);
	return is_string($link)?$link:'';
}
/** Query Builder #21 is not a public activity search query. */
add_filter('jet-engine/query-builder/types/posts-query/args',
	static function($args,$query){
		if(!is_array($args)||($query->query_id??'')!=='extra_trip'||(int)($query->id??0)!==21)return $args;
		if(is_admin()&&!wp_doing_ajax())return $args;
		global $wp_the_query;
		$parent=($wp_the_query instanceof WP_Query && $wp_the_query->is_singular('tours-and-activities'))
			?absint($wp_the_query->get_queried_object_id()):0;
		$allowed=$parent?registry_extra_trip_parent_allowed($parent):[];
		$args['post__in']=$allowed?:[0];
		$args['post_type']='tours-and-activities';
		$args['post_status']='publish';
		$args['has_password']=false;
		if($allowed)$args['registry_authorized_extra_trip_parent']=$parent;
		return $args;
	},900,2);

/** Extra trips are never independently eligible for public search/listing. */
add_filter('posts_where',static function(string $where,WP_Query $query):string{
	if(is_admin()&&!wp_doing_ajax())return $where;
	$type=$query->get('post_type');
	$has_tours=$type==='tours-and-activities'||$type==='any'
		||(is_array($type)&&in_array('tours-and-activities',$type,true))
		||$query->is_post_type_archive('tours-and-activities')
		||$query->is_singular('tours-and-activities');
	if(!$has_tours)return $where;
	global $wp_the_query;
	$trusted_parent=($wp_the_query instanceof WP_Query && $wp_the_query->is_singular('tours-and-activities'))
		?absint($wp_the_query->get_queried_object_id()):0;
	// Native Elementor Tour templates may use several JetEngine listing
	// providers, not just Query Builder #21. Every provider inherits the
	// same verified PARENT allowlist without widening public archives.
	$allowed=$trusted_parent?registry_extra_trip_parent_allowed($trusted_parent):[];
	// Narrow read-only exception for the *verified* native destination
	// discovery listing. Never turn extra_trip into a public standalone Tour.
	$requested_term=absint($query->get('registry_destination_activity_term'));
	if(!$trusted_parent && $requested_term && function_exists('registry_destination_activity_current_term')
	 && $requested_term===registry_destination_activity_current_term()
	 && function_exists('registry_destination_activity_ids')){
		$authorized=registry_destination_activity_ids($requested_term);
		$post_ids=array_map('intval',(array)$query->get('post__in'));
		$allowed=array_values(array_intersect($authorized,$post_ids));
	}
	global $wpdb;
	$allowed_sql=$allowed?' OR '.$wpdb->posts.'.ID IN ('.implode(',',array_map('intval',$allowed)).')':'';
	return $where." AND ( {$wpdb->posts}.post_type <> 'tours-and-activities'
		OR NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} extra_visibility
			WHERE extra_visibility.post_id={$wpdb->posts}.ID
			 AND extra_visibility.meta_key='tour_mode'
			 AND extra_visibility.meta_value='extra_trip'
		)$allowed_sql )";
},100,2);

/** No independent visitor-facing extra_trip page (even with a known URL). */
add_action('template_redirect',static function():void {
	if(is_admin()||is_preview()||current_user_can('edit_posts')||!is_singular('tours-and-activities'))return;
	$id=absint(get_queried_object_id());
	if((string)get_post_meta($id,'tour_mode',true)!=='extra_trip')return;
	global $wp_query;
	if(!$wp_query instanceof WP_Query)return;
	$wp_query->set_404();
	status_header(404);
	nocache_headers();
},2);


/**
 * WordPress REST collection queries already honor the tours visibility rule.
 * A direct single-item REST GET uses get_post() instead, so deny a standalone
 * public representation here. Other REST namespaces (bookings, quotes) are
 * intentionally not changed.
 */
add_filter('rest_request_before_callbacks',static function($result,$handler,$request){
	if(!($request instanceof WP_REST_Request)||$request->get_method()!=='GET')return $result;
	if(!preg_match('~^/wp/v2/tours-and-activities/([0-9]+)$~D',$request->get_route(),$matches))return $result;
	$id=absint($matches[1]);
	if((string)get_post_meta($id,'tour_mode',true)!=='extra_trip')return $result;
	if(current_user_can('edit_post',$id))return $result;
	return new WP_Error('rest_post_invalid_id','Invalid post ID.',['status'=>404]);
},10,3);
