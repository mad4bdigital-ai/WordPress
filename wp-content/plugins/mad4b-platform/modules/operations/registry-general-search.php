<?php
/**
 * Registry-driven public discovery, Local Lab only.
 * Search is for services and editorial posts; RATE CPTs are not search cards.
 */
defined('ABSPATH') || exit;
if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;

function registry_search_public_types():array {
	$types=['post'];  // The general search archive also discovers editorial posts.
	if(!function_exists('product_navigation_registry')||!function_exists('service_channel_allowed'))return $types;
	$option=get_option('service-activation',[]);
	if(!is_array($option)||($option['discovery_enabled']??'disabled')!=='enabled')return $types;
	$surfaces=(array)($option['discovery_surfaces']??[]);
	$surfaces_for=['tours'=>'tour','properties'=>'property','transportations'=>'transportation','visas'=>'visa'];
	foreach(product_navigation_registry() as $owner=>$rule) {
		$type=$rule['post_type']??'';
		if(!is_string($type)||!post_type_exists($type)||!isset($surfaces_for[$owner]))continue;
		if(!in_array($surfaces_for[$owner],$surfaces,true))continue;
		if(!service_channel_allowed($owner,'standalone'))continue;
		if(preg_match('/(^|[-_])rates?($|[-_])/i',$type) || in_array($type,['units','product','page'],true))continue;
		$types[]=$type;
	}
	return array_values(array_unique($types));
}
function registry_search_limit():int{
	$settings=get_option('service-activation',[]);
	$limit=is_array($settings)?absint($settings['discovery_default_limit']??6):6;
	return max(1,min(24,$limit?:6));
}

add_filter('register_post_type_args',static function(array $args,string $type):array{
	if($type!=='tour-rates')return $args;
	// Explicit internal WP_Query('post_type' => 'tour-rates') still works.
	// Standalone public rate pages and default search must not expose prices.
	$args['exclude_from_search']=true;
	$args['has_archive']=false;
	// Direct pages handled by template_redirect.
	return $args;
},100,2);

add_action('pre_get_posts',static function(WP_Query $query):void{
	if(is_admin()||!$query->is_main_query()||!$query->is_search())return;
	if(wp_doing_ajax()||(defined('REST_REQUEST')&&REST_REQUEST))return;
	$types=registry_search_public_types();
	$limit=registry_search_limit();
	// Optional service selection for a future native Elementor/JetSmartFilters UI.
	// Reject unavailable standalone services rather than widening the query.
	$requested=isset($_GET['discovery_service'])&&is_string($_GET['discovery_service'])
		?sanitize_key(wp_unslash($_GET['discovery_service'])):'';
	if($requested!==''){
		$owner=product_navigation_registry()[$requested]['post_type']??null;
		if($requested==='articles')$owner='post';
		if(is_string($owner) && in_array($owner,$types,true))$types=[$owner];
		else $query->set('post__in',[0]);
	}
	$query->set('post_type',$types);
	$query->set('post_status','publish');
	$query->set('has_password',false);
	$query->set('posts_per_page',$limit);
	$query->set('posts_per_archive_page',$limit); // Critical: overrides posts_per_page on search.
	$query->set('ignore_sticky_posts',true);
	$query->set('registry_general_search',true);
	// Keep the sort adaptive to the existing unified discovery policy.
	$service=get_option('service-activation',[]);
	$sort=is_array($service)?($service['discovery_sort']??''):'';
	if($sort==='id_asc'){$query->set('orderby','ID');$query->set('order','ASC');}
	elseif($sort==='id_desc'){$query->set('orderby','ID');$query->set('order','DESC');}
},999);

add_filter('posts_where',static function(string $where,WP_Query $query):string{
	if(!$query->get('registry_general_search'))return $where;
	global $wpdb;
	// Tour public status belongs to the Tour, not to its pricing rows.
	// Exclude explicit 0/false; missing status remains legacy-compatible.
	$where.=" AND ( {$wpdb->posts}.post_type <> 'tours-and-activities'
	  OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} registry_hide
	    WHERE registry_hide.post_id={$wpdb->posts}.ID
	    AND registry_hide.meta_key='tour_public_status'
	    AND registry_hide.meta_value IN ('0','false')) )";
	return $where;
},20,2);

/** Keep JetEngine archive collections on the same owner-discovery policy. */
add_filter('jet-engine/listing/grid/posts-query-args',static function($args,$render,$settings){
	global $wp_the_query;
	if(!$wp_the_query instanceof WP_Query || !$wp_the_query->is_search() ||
	  empty($settings['is_archive_template']))return $args;
	$args['post_type']=registry_search_public_types();
	$args['post_status']='publish';
	$args['has_password']=false;
	$args['posts_per_page']=registry_search_limit();
	$args['posts_per_archive_page']=registry_search_limit();
	return $args;
},100,3);

/** Never serve an independent Rate CPT page to public visitors. */
add_action('template_redirect',static function():void{
	if(is_admin()||wp_doing_ajax()||is_preview()||current_user_can('edit_posts'))return;
	if(!is_singular('tour-rates')&&!is_post_type_archive('tour-rates'))return;
	global $wp_query;
	if(!$wp_query instanceof WP_Query)return;
	$wp_query->set_404();
	status_header(404);
	nocache_headers();
},1);


// Certain Elementor archive adapters reset the query limit after pre_get_posts.
// Enforce a single native SQL page boundary and keep WordPress pagination in sync.
add_filter('post_limits',static function(string $sql_limits,WP_Query $query):string{
	if(!$query->get('registry_general_search'))return $sql_limits;
	$limit=registry_search_limit();
	$page=max(1,absint($query->get('paged')?:1));
	$query->set('posts_per_page',$limit);
	return 'LIMIT '.(($page-1)*$limit).', '.$limit;
},PHP_INT_MAX,2);

/**
 * Shared Presentation Context v1 owns source arbitration. Keep one native
 * Elementor #30861 grid and switch its authorized data kind by from scope.
 */
add_action('pre_get_posts',static function(WP_Query $query):void{
 if(is_admin()||!$query->is_main_query()||!$query->is_search()||wp_doing_ajax())return;
 if(!class_exists('Shared_Product_Discovery_Search_Presentation_v1'))return;
 $context=Shared_Product_Discovery_Search_Presentation_v1::resolve();
 if($context['source_kind']==='taxonomy_terms'||$context['source_kind']==='none'){
   $query->set('post_type','post');
   $query->set('post__in',[0]);
 }elseif($context['source_kind']==='wp_posts'&&$context['scope']!=='all'){
   $query->set('post_type',$context['post_types']?:['post']);
   if(!$context['post_types'])$query->set('post__in',[0]);
 }
},1001);

/** Native Elementor Grid/List views bound to the Search Presentation Context. */
add_filter('elementor/frontend/builder_content_data',static function($data,$document_id){
 if((int)$document_id!==30861||!is_array($data)||
    !class_exists('Shared_Product_Discovery_Search_Presentation_v1')||
    !Shared_Product_Discovery_Search_Presentation_v1::active())return $data;
 $ctx=Shared_Product_Discovery_Search_Presentation_v1::resolve();
 $terms=$ctx['source_kind']==='taxonomy_terms';
 $empty=$ctx['source_kind']==='none';
 $grid=(string)($ctx['listing_id']?:45927);
 $list=(string)($ctx['list_listing_id']??$grid);
 $found=['grid'=>0,'switcher'=>0];
 $walk=function($nodes)use(&$walk,$ctx,$terms,$empty,$grid,$list,&$found){
   foreach($nodes as &$node){
     $s=&$node['settings'];
     if(($node['widgetType']??'')==='jet-listing-grid'&&($node['id']??'')==='6771764c'){
       $s['lisitng_id']=$grid;
       $s['posts_num']=$ctx['limit'];
       $s['columns']='2';$s['columns_mobile']='1';
       $s['inject_alternative_items']='';
       $s['injection_items']=[];
       // The native authoring post Listing stays connected for Posts.
       if($terms||$empty){
         $s['_element_id']='registry_search_destination_grid';
         $s['is_archive_template']='';
         $s['terms_hide_empty']='false';
         $s['terms_orderby']='name';$s['terms_order']='ASC';
         $s['terms_parent']='';
         $s['terms_include']=$terms?implode(',',$ctx['term_ids']):'0';
         $s['custom_query_id']='';
         $s['not_found_message']=$terms?'No matching destinations were found.':'Unsupported search scope.';
       }
       unset($s['jedv_conditions'],$s['display_condition_list']);
       $found['grid']++;
     }
     if(($node['widgetType']??'')==='jet-engine-layout-switcher'&&($node['id']??'')==='25e78eaf'){
       foreach($s['layouts'] as &$layout){
         if(($layout['slug']??'')==='grid'){
           $layout['lisitng_id']=$grid;
           $layout['is_default_layout']='yes';
           $layout['columns']='2';$layout['columns_mobile']='1';
         }
         if(($layout['slug']??'')==='list'){
           $layout['lisitng_id']=$list;
           $layout['is_default_layout']='';
           $layout['columns']='1';$layout['columns_mobile']='1';
         }
       }
       unset($layout);
       $s['widget_id']='6771764c';
       unset($s['jedv_conditions'],$s['display_condition_list']);
       $found['switcher']++;
     }
     if(($node['widgetType']??'')==='jet-smart-filters-pagination'&&($node['id']??'')==='28712b6a' &&($terms||$empty)){
       $s['query_id']='registry_search_destination_grid';
       $s['content_provider']='jet-engine';
     }
     if(!empty($node['elements']))$node['elements']=$walk($node['elements']);
     unset($s);
   }
   unset($node);
   return $nodes;
 };
 $out=$walk($data);
 return $found===['grid'=>1,'switcher'=>1]?$out:$data;
},170,2);

/** Only the discovered Terms grid receives scoped native term constraints. */
add_filter('jet-engine/listing/grid/terms-query-args',static function($args,$render,$settings){
 if(($settings['_element_id']??'')!=='registry_search_destination_grid'||
    !class_exists('Shared_Product_Discovery_Search_Presentation_v1'))return $args;
 $context=Shared_Product_Discovery_Search_Presentation_v1::resolve();
 $ids=$context['source_kind']==='taxonomy_terms'?$context['term_ids']:[];
 $limit=$context['limit']??6;
 $page=max(1,min(100,absint(get_query_var('paged')?:($_GET['paged']??1))));
 $args['taxonomy']='destinations';
 $args['include']=$ids?:[PHP_INT_MAX];
 $args['number']=$limit;
 $args['offset']=($page-1)*$limit;
 $args['orderby']='name';$args['order']='ASC';
 $args['hide_empty']=false;
 unset($args['parent'],$args['child_of'],$args['object_ids'],$args['exclude']);
 return $args;
},110,3);

/** The source kind must change at JetEngine render time too, not only in Elementor settings. */
add_filter('jet-engine/listing/grid/source',static function($source,$settings,$renderer){
 if(($settings['_element_id']??'')==='registry_search_destination_grid' &&
    class_exists('Shared_Product_Discovery_Search_Presentation_v1') &&
    Shared_Product_Discovery_Search_Presentation_v1::active())return 'terms';
 return $source;
},125,3);

/**
 * JetEngine Layout Switcher reads the SAVED Elementor document, whereas our
 * Search Presentation Context projects the active source at render time.
 * Bind the existing native View to the selected context instead of adding
 * a second switcher or injecting CSS. Only search widget #6771764c changes.
 */
add_action('jet-engine-layout-switcher/init',static function($switcher):void{
 if(!class_exists('\Jet_Engine_Layout_Switcher\Elementor\View')||
    !class_exists('Shared_Product_Discovery_Search_Presentation_v1'))return;
 $switcher->register_view(new class extends \Jet_Engine_Layout_Switcher\Elementor\View {
  public function get_listing_settings_by_id($widget_id) {
   $original=parent::get_listing_settings_by_id($widget_id);
   if((string)$widget_id!=='6771764c' ||
       !Shared_Product_Discovery_Search_Presentation_v1::active())return $original;
   $ctx=Shared_Product_Discovery_Search_Presentation_v1::resolve();
   if(($ctx['listing_id']??0)<=0)return $original;
   return array_merge($original,[
    'lisitng_id'=>(string)$ctx['listing_id'],
    'columns'=>'2',
    'columns_mobile'=>'1',
    'columns_tablet'=>'2'
   ]);
  }
  public function find_relevant_switcher_on_page($grid_widget_id) {
   $original=parent::find_relevant_switcher_on_page($grid_widget_id);
   if((string)$grid_widget_id!=='6771764c'||
       !Shared_Product_Discovery_Search_Presentation_v1::active())return $original;
   $ctx=Shared_Product_Discovery_Search_Presentation_v1::resolve();
   if(!$original||!isset($ctx['listing_id']))return $original;
   foreach($original['layouts'] as &$layout){
    if(($layout['slug']??'')==='grid'){
      $layout['is_default_layout']='yes';
      $layout['lisitng_id']=(string)$ctx['listing_id'];
      $layout['columns']='2';
      $layout['columns_mobile']='1';
    }
    if(($layout['slug']??'')==='list'){
      $layout['is_default_layout']='';
      $layout['lisitng_id']=(string)($ctx['list_listing_id']??$ctx['listing_id']);
      $layout['columns']='1';
      $layout['columns_mobile']='1';
    }
   }unset($layout);
   return $original;
  }
 });
},90);
