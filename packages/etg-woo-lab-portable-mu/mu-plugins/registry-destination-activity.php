<?php
/** Local only: authorized extra_trip Destination discovery through native JetEngine. */
defined('ABSPATH')||exit;


function registry_destination_activity_term_ready(int $term_id):bool{
	$term=get_term($term_id,'destinations');
	if(!$term instanceof WP_Term)return false;
	if(!service_channel_allowed('tours','destination_discovery'))return false;
	$options=get_option('service-activation',[]);
	if(!is_array($options)||($options['discovery_enabled']??'disabled')!=='enabled')return false;
	$surfaces=(array)($options['discovery_surfaces']??[]);
	if(!in_array('destination',$surfaces,true)||!in_array('tour',$surfaces,true))return false;
	if(!function_exists('destinations_activation_allowed')||!destinations_activation_allowed($term_id))return false;
	$chain=array_merge([$term_id],array_map('intval',get_ancestors($term_id,'destinations','taxonomy')));
	$countries=[];$continents=[];
	foreach($chain as $id){
		$t=get_term($id,'destinations');
		if(!$t instanceof WP_Term||get_term_meta($id,'is_public',true)!=='1')return false;
		$type=(string)get_term_meta($id,'destination_type',true);
		if($type==='country')$countries[]=$id;
		elseif($type==='continent')$continents[]=$id;
	}
	$active=get_option('destination-activation',[]);
	if(!is_array($active))return false;
	// Approval checklists are stored against canonical English taxonomy terms.
	// Still validate public flags independently on the displayed WPML chain.
	$canonical=static fn($id)=>(int)(apply_filters('wpml_object_id',$id,'destinations',false,'en')?:$id);
	foreach($countries as $id)if(!in_array($canonical($id),array_map('intval',(array)($active['active_countries']??[])),true))return false;
	foreach($continents as $id)if(!in_array($canonical($id),array_map('intval',(array)($active['active_continents']??[])),true))return false;
	return count($countries)<=1 && count($continents)===1;
}
function registry_destination_activity_term_matches_post(int $post_id,int $term_id):bool{
	$assigned=wp_get_post_terms($post_id,'destinations',['fields'=>'ids']);
	if(is_wp_error($assigned))return false;
	$canonical=static fn($id)=>(int)(apply_filters('wpml_object_id',$id,'destinations',false,'en')?:$id);
	$target=$canonical($term_id);
	foreach($assigned as $id){
		$id=$canonical((int)$id);
		if($id===$target||in_array($target,array_map('intval',get_ancestors($id,'destinations','taxonomy')),true))return true;
	}
	return false;
}
/** An activity is discoverable only with one unambiguous eligible parent in this destination. */
function registry_destination_activity_parent(int $extra_id,int $term_id):int{
	if(!registry_destination_activity_term_ready($term_id)
	 ||!registry_extra_trip_child_active($extra_id)
	 ||!registry_destination_activity_term_matches_post($extra_id,$term_id))return 0;
	$image=(int)get_post_thumbnail_id($extra_id);
	if(!$image||!wp_attachment_is_image($image)||!wp_get_attachment_image_src($image,'medium_large'))return 0;
	$eligible=[];
	foreach(registry_extra_trip_parent_tours($extra_id) as $parent_id){
		$parent_id=(int)$parent_id;
		if(registry_destination_activity_term_matches_post($parent_id,$term_id))$eligible[]=$parent_id;
	}
	return count($eligible)===1?$eligible[0]:0;
}
function registry_destination_activity_ids(int $term_id):array{
	if(!registry_destination_activity_term_ready($term_id))return [];
	global $wpdb;
	$candidates=$wpdb->get_col($wpdb->prepare(
		"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID=m.post_id
		 WHERE m.meta_key=%s AND m.meta_value=%s
		   AND p.post_type=%s AND p.post_status=%s
		 ORDER BY m.post_id ASC LIMIT 200",
		'tour_mode','extra_trip','tours-and-activities','publish'
	));
	$result=[];
	foreach($candidates as $extra)if(registry_destination_activity_parent((int)$extra,$term_id))
		$result[]=(int)$extra;
	return array_slice($result,0,24);
}
function registry_destination_activity_current_term():int{
	global $wp_the_query;
	return $wp_the_query instanceof WP_Query && $wp_the_query->is_tax('destinations')
	 ? (int)$wp_the_query->get_queried_object_id():0;
}
function registry_destination_activity_link(int $extra_id):string{
	$term=registry_destination_activity_current_term();
	$parent=$term?registry_destination_activity_parent($extra_id,$term):0;
	return $parent?registry_extra_trip_view_tour_url($extra_id,$parent):'';
}
add_filter('jet-engine/listing/grid/posts-query-args',static function($args,$renderer,$settings){
	if(($settings['_element_id']??'')!=='registry_destination_activity_grid')return $args;
	$term=registry_destination_activity_current_term();
	$ids=$term?registry_destination_activity_ids($term):[];
	$args['post_type']='tours-and-activities';
	$args['post_status']='publish';
	// Explicit, server-validated IDs; permits EN source fallback when a WPML
	// translation of a preview activity does not exist.
	$args['suppress_filters']=true;
	$args['post__in']=$ids?:[0];
	$args['posts_per_page']=min(6,max(1,count($ids)));
	$args['orderby']='post__in';
	$args['ignore_sticky_posts']=true;
	$args['registry_destination_activity_term']=$term;
	return $args;
},80,3);

add_filter('jet-engine/listings/dynamic-link/custom-url',static function($url,$settings){
	if(($settings['dynamic_link_source']??'')!=='registry_parent_tour')return $url;
	$id=jet_engine()->listings->data->get_current_object_id();
	return registry_destination_activity_link((int)$id)?:false;
},30,2);
add_action('elementor/dynamic_tags/register',static function($manager){
	if(!class_exists('Registry_Extra_Parent_URL_Tag',false)){
		class Registry_Extra_Parent_URL_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
			public function get_name(){return 'registry-extra-parent-url';}
			public function get_title(){return 'Extra activity — verified parent Tour URL';}
			public function get_group(){return 'product-navigation';}
			public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::URL_CATEGORY];}
			public function get_value(array $options=[]){
				$id=jet_engine()->listings->data->get_current_object_id();
				return registry_destination_activity_link((int)$id);
			}
		}
	}
	$manager->register(new Registry_Extra_Parent_URL_Tag());
},30);

/**
 * The authored native Elementor template is installed after the destination
 * Tours section. No eligible activity -> omit the whole template slot,
 * not merely an empty grid. Elementor editors still see/edit the template.
 */
add_filter('elementor/frontend/widget/should_render',static function($should,$element){
	if(!$should||!method_exists($element,'get_id')||$element->get_id()!=='a2950f1')return $should;
	if(is_admin()||(current_user_can('edit_posts')&&isset($_GET['elementor-preview'])))return $should;
	$term=registry_destination_activity_current_term();
	return $term && count(registry_destination_activity_ids($term))>0;
},30,2);
