<?php
/** Shared Service Activation authority for public archive and single requests. */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;
final class Shared_Service_Public_Access_v1 {
 private const CPTS=['tours'=>'tours-and-activities','properties'=>'properties','transportations'=>'transportations','visas'=>'travel-visa'];
 private const INTERNAL=['tour-rates','property-rates','transport-rates'];
 // Existing authored multilingual WordPress Page IDs for service landings.
 private const SERVICE_LANDING_PAGES=[
  44348=>'transportations',44482=>'transportations',44480=>'transportations',
  44484=>'transportations',44492=>'transportations',44494=>'transportations',
  44346=>'properties',44478=>'properties',44486=>'properties',
  44488=>'properties',44490=>'properties',44496=>'properties',
 ];
 public static function excluded_landing_pages():array {
  $ids=[];
  foreach(self::SERVICE_LANDING_PAGES as $id=>$owner)
   if(!service_channel_allowed($owner,'standalone'))$ids[]=(int)$id;
  return $ids;
 }
 public static function object_allowed(WP_Post $post):bool {
  if($post->post_type==='page' && isset(self::SERVICE_LANDING_PAGES[$post->ID]))
   return service_channel_allowed(self::SERVICE_LANDING_PAGES[$post->ID],'standalone');
  return self::post_allowed($post->post_type);
 }
 private static array $tax_cache=[];
 private static array $url_cache=[];
 private static array $path_head_policy_cache=[];
 private static ?array $landing_slug_map=null;
 private static function landing_owner_by_slug(string $head):?string {
  if(self::$landing_slug_map===null){
   $map=[];
   foreach(self::SERVICE_LANDING_PAGES as $id=>$owner){
    $post=get_post($id);
    if($post instanceof WP_Post && $post->post_type==='page'
      && !array_key_exists($post->post_name,$map))$map[$post->post_name]=$owner;
   }
   self::$landing_slug_map=$map;
  }
  return self::$landing_slug_map[$head]??null;
 }

 public static function post_allowed(string $type):bool {
  if(in_array($type,self::INTERNAL,true))return false;
  $owner=function_exists('service_channel_owner')?service_channel_owner($type):null;
  return !$owner || (function_exists('service_channel_allowed')&&service_channel_allowed($owner,'standalone'));
 }
 public static function tax_owners(string $tax):array {
  if($tax==='destinations')return [];
  if(isset(self::$tax_cache[$tax]))return self::$tax_cache[$tax];
  $obj=get_taxonomy($tax);$owners=[];
  if($obj&&$obj->public)foreach((array)$obj->object_type as $postType){
   $owner=function_exists('service_channel_owner')?service_channel_owner($postType):null;
   if($owner)$owners[$owner]=true;
  }
  return self::$tax_cache[$tax]=array_keys($owners);
 }
 public static function tax_allowed(string $tax):bool {
  $owners=self::tax_owners($tax);
  foreach($owners as $owner)if(service_channel_allowed($owner,'standalone'))return true;
  return !$owners;
 }
 public static function main_decision():array {
  global $wp_the_query;
  if(!$wp_the_query instanceof WP_Query)return ['kind'=>'none','allow'=>true];
  $q=$wp_the_query;
  if($q->is_singular()){
   $post=$q->get_queried_object();
   if($post instanceof WP_Post){
    // Service Activation tour_only keeps independent Transportation Singles
    // disabled. A precisely source-linked, authorized MAD4B Tour Quick View
    // is a non-commercial tour_component, not a standalone service visit.
    if($post->post_type==='transportations'
      &&class_exists('MAD4B_Extra_Quick_Preview_V1',false)
      &&MAD4B_Extra_Quick_Preview_V1::scoped_transport_query_allowed($q))
     return ['kind'=>'tour_component_preview','name'=>'transportations','allow'=>true];
    return ['kind'=>'post','name'=>$post->post_type,'allow'=>self::object_allowed($post)];
   }
  }
  if($q->is_post_type_archive()){
   $types=(array)$q->get('post_type');
   $allow=false;
   foreach($types as $type)if(is_string($type)&&self::post_allowed($type))$allow=true;
   if($types)return ['kind'=>'archive','name'=>implode(',',$types),'allow'=>$allow];
  }
  if($q->is_tax()){
   $term=$q->get_queried_object();
   if($term instanceof WP_Term)return ['kind'=>'taxonomy','name'=>$term->taxonomy,'allow'=>self::tax_allowed($term->taxonomy)];
  }
  return ['kind'=>'other','allow'=>true];
 }
 public static function visitor():bool {
  return !is_admin()&&!wp_doing_ajax()&&!(defined('REST_REQUEST')&&REST_REQUEST)
   &&!(defined('WP_CLI')&&WP_CLI)
   &&!((is_preview()||isset($_GET['elementor-preview']))&&current_user_can('edit_posts'));
 }
 /** Guest AJAX listings must apply the same non-clickable link policy. */
 public static function public_render():bool {
  if(wp_doing_ajax())return !current_user_can('edit_posts');
  return self::visitor();
 }
 /** Resolve only URLs owned by this website; never rewrite third-party links. */
 public static function link_allowed(string $href):bool {
  $href=trim(html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
  if(!$href||$href[0]==='#'||preg_match('~^(mailto:|tel:|data:|javascript:)~i',$href))return true;
  if(array_key_exists($href,self::$url_cache))return self::$url_cache[$href];
  $url=wp_parse_url($href);
  if(!is_array($url))return true;
  $host=strtolower((string)($url['host']??''));
  // Company hostnames come from native WordPress source fields only.
  $valid=[strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)),
    strtolower((string)wp_parse_url(site_url(),PHP_URL_HOST))];
  if((defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED))$valid[]='localhost';
  if($host&&!in_array($host,$valid,true))return self::$url_cache[$href]=true;
  $query=[];
  if(isset($url['query']))wp_parse_str($url['query'],$query);
  if(($query['mad4b_transport_quickview']??'')==='1'
    &&class_exists('MAD4B_Extra_Quick_Preview_V1',false)){
   // Only retain an exact URL issued by the shared controller after linked
   // Tour, provider, WPML and editor-nonce checks; arbitrary query flags,
   // direct service permalinks and lookalike URLs stay disallowed.
   $parent=absint($query['mad4b_parent']??0);
   $transport=absint($query['mad4b_transport']??0);
   $verified=$parent&&$transport
    ?MAD4B_Extra_Quick_Preview_V1::transport_url($parent,$transport):'';
   if($verified!==''&&hash_equals($verified,$href))
    return self::$url_cache[$href]=true;
  }
  if(isset($query['taxonomy'])&&is_string($query['taxonomy'])){
   $tax=sanitize_key($query['taxonomy']);
   if(taxonomy_exists($tax))return self::$url_cache[$href]=self::tax_allowed($tax);
  }
  foreach($query as $key=>$value)if(is_string($key)&&taxonomy_exists($key))
   return self::$url_cache[$href]=self::tax_allowed($key);
  if(isset($query['post_type'])&&is_string($query['post_type']))
   return self::$url_cache[$href]=self::post_allowed(sanitize_key($query['post_type']));
  if(isset($query['p'])&&is_scalar($query['p'])){
   $p=get_post(absint($query['p']));
   if($p)return self::$url_cache[$href]=self::object_allowed($p);
  }
  if(isset($query['page_id']) && is_scalar($query['page_id'])){
   $page=get_post(absint($query['page_id']));
   if($page)return self::$url_cache[$href]=self::object_allowed($page);
  }
  $path=trim(rawurldecode((string)($url['path']??'')),'/');
  if(!$path)return self::$url_cache[$href]=true;
  $segments=explode('/',$path);
  if(in_array($segments[0],['en','ar','fr','de','it','es'],true))array_shift($segments);
  $head=(string)($segments[0]??'');
  // URL-level cache retains full query semantics. For a clean permalink,
  // the top path segment's service/taxonomy authority is stable throughout
  // this visitor request. Avoid repeated get_post_types/get_taxonomies scans.
  if(array_key_exists($head,self::$path_head_policy_cache))
   return self::$url_cache[$href]=self::$path_head_policy_cache[$head];
  $landing_owner=self::landing_owner_by_slug($head);
  if($landing_owner!==null)
   return self::$url_cache[$href]=self::$path_head_policy_cache[$head]
    =service_channel_allowed($landing_owner,'standalone');
  foreach(get_post_types(['public'=>true],'objects') as $post_type=>$obj){
   if(!function_exists('service_channel_owner') || !service_channel_owner($post_type))continue;
   $slug=is_array($obj?->rewrite)?trim((string)($obj->rewrite['slug']??$post_type),'/'):$post_type;
   if($head===$post_type||$head===$slug)
    return self::$url_cache[$href]=self::$path_head_policy_cache[$head]
      =self::post_allowed($post_type);
  }
  foreach(array_merge(self::INTERNAL,['units']) as $post_type){
   if($head===$post_type)return self::$url_cache[$href]=self::$path_head_policy_cache[$head]
      =self::post_allowed($post_type);
  }
  foreach(get_taxonomies(['public'=>true],'objects') as $tax){
   if($tax->name==='destinations'||!is_array($tax->rewrite))continue;
   if($head===trim((string)($tax->rewrite['slug']??$tax->name),'/'))
    return self::$url_cache[$href]=self::$path_head_policy_cache[$head]
      =self::tax_allowed($tax->name);
  }
  return self::$url_cache[$href]=self::$path_head_policy_cache[$head]=true;
 }
 /** Remove href from rendered linked Terms, buttons and menu items. */
 public static function unlink_markup(string $html):string {
  // Most Elementor cards/containers contain no anchor element. The old
  // '<' check parsed ALL HTML tags even for text and price widgets. Avoid
  // invoking the tokenizer unless an <a... candidate exists; false positives
  // such as <abbr still go through the exact original WordPress processor.
  if (stripos($html,'<a')===false)return $html;
  if(!class_exists('WP_HTML_Tag_Processor'))return $html;
  $tags=new WP_HTML_Tag_Processor($html);$changed=false;
  while($tags->next_tag(['tag_name'=>'A'])){
   $href=$tags->get_attribute('href');
   if(!is_string($href)||self::link_allowed($href))continue;
   $tags->remove_attribute('href');
   $tags->remove_attribute('target');
   $tags->remove_attribute('rel');
   $tags->set_attribute('aria-disabled','true');
   $tags->set_attribute('tabindex','-1');
   $tags->set_attribute('role','text');
   $tags->set_attribute('class',trim((string)$tags->get_attribute('class').' service-link-inactive'));
   $changed=true;
  }
  return $changed?$tags->get_updated_html():$html;
 }
 public static function standalone_types(string $tax):array {
  $map=self::CPTS;$allowed=[];
  foreach(self::tax_owners($tax) as $owner){
   if(isset($map[$owner])&&service_channel_allowed($owner,'standalone'))$allowed[]=$map[$owner];
  }
  return $allowed;
 }
}

/** Deny direct independent archives/singles, but preserve Elementor editing. */
add_action('template_redirect',static function():void{
 if(!Shared_Service_Public_Access_v1::visitor())return;
 $decision=Shared_Service_Public_Access_v1::main_decision();
 // Earlier presentation query guards can replace a denied service post_type
 // with an empty sentinel. Feed requests would then return HTTP 200 with an
 // empty payload; enforce the original public URL decision as well.
 if($decision['allow'] && is_feed()){
  $requested=isset($_SERVER['REQUEST_URI'])&&is_string($_SERVER['REQUEST_URI'])
   ?wp_unslash($_SERVER['REQUEST_URI']):'';
  if($requested!=='' && !Shared_Service_Public_Access_v1::link_allowed(home_url($requested)))
   $decision=['kind'=>'feed','allow'=>false];
 }
 if($decision['allow'])return;
 global $wp_query;
 if($wp_query instanceof WP_Query)$wp_query->set_404();
 $GLOBALS['shared_service_access_denied']=true;
 status_header(404);
 nocache_headers();
},0);
add_filter('redirect_canonical',static function($redirect){
 return !empty($GLOBALS['shared_service_access_denied'])?false:$redirect;
},1000);

/** Shared category results may not leak posts owned by disabled services. */
add_action('pre_get_posts',static function($query):void{
 if(is_admin()||!$query->is_main_query()||!$query->is_tax())return;
 $term=$query->get_queried_object();
 if(!$term instanceof WP_Term)return;
 $owners=Shared_Service_Public_Access_v1::tax_owners($term->taxonomy);
 if(!$owners)return;
 $types=Shared_Service_Public_Access_v1::standalone_types($term->taxonomy);
 $query->set('post_type',$types?:['post']);
 if(!$types)$query->set('post__in',[0]);
},900);
/**
 * Remove link destinations in rendered Elementor, JetEngine Terms,
 * native WP menus, posts and blocks. Saved templates are unchanged.
 */
add_filter('elementor/widget/render_content',static function($html){
 if(!Shared_Service_Public_Access_v1::public_render()||!is_string($html))return $html;
 return Shared_Service_Public_Access_v1::unlink_markup($html);
},1100);
add_filter('wp_nav_menu',static function($html){
 return Shared_Service_Public_Access_v1::public_render()
  ?Shared_Service_Public_Access_v1::unlink_markup((string)$html):$html;
},1100);
add_filter('the_content',static function($html){
 return Shared_Service_Public_Access_v1::public_render()
  ?Shared_Service_Public_Access_v1::unlink_markup((string)$html):$html;
},1100);
add_filter('render_block',static function($html){
 return Shared_Service_Public_Access_v1::public_render()
  ?Shared_Service_Public_Access_v1::unlink_markup((string)$html):$html;
},1100);
add_action('wp_head',static function():void{
 if(!Shared_Service_Public_Access_v1::visitor())return;
 echo '<style id="service-link-inactive-style">'.
  '.service-link-inactive{cursor:default!important;pointer-events:none!important;opacity:.72;}'.
  '</style>'."\n";
},5);
/** WordPress-native the_terms()/get_the_term_list() before Elementor wrappers. */
add_action('init',static function():void{
 foreach(get_taxonomies(['public'=>true],'names') as $taxonomy){
  if(!Shared_Service_Public_Access_v1::tax_owners($taxonomy))continue;
  add_filter("term_links-{$taxonomy}",static function($links){
   if(!Shared_Service_Public_Access_v1::public_render())return $links;
   return array_map(static fn($html)=>is_string($html)
    ?Shared_Service_Public_Access_v1::unlink_markup($html):$html,(array)$links);
  },1000);
 }
},90);
/** Recompute cached visitor pages whenever the authoritative Option Page changes. */
add_action('update_option_service-activation',static function($previous,$next):void{
 if($previous!==$next)do_action('mad4b/public_html/safe_purge_all', 'registry_service_public_access');
},10,2);

/**
 * Rank Math XML discovery must reflect the same public URL authority,
 * including legacy auxiliary CPTs and shared taxonomy entries.
 */
add_filter('rank_math/sitemap/exclude_post_type',static function($exclude,$post_type){
 return (bool)$exclude || (is_string($post_type) && !Shared_Service_Public_Access_v1::post_allowed($post_type));
},1000,2);
add_filter('rank_math/sitemap/exclude_taxonomy',static function($exclude,$taxonomy){
 return (bool)$exclude || (is_string($taxonomy) && !Shared_Service_Public_Access_v1::tax_allowed($taxonomy));
},1000,2);
add_filter('rank_math/sitemap/entry',static function($entry,$type,$object){
 if(empty($entry))return $entry;
 if($type==='post' && $object instanceof WP_Post && !Shared_Service_Public_Access_v1::object_allowed($object))
  return false;
 if($type==='term' && $object instanceof WP_Term && !Shared_Service_Public_Access_v1::tax_allowed($object->taxonomy))
  return false;
 return $entry;
},1000,3);
add_action('update_option_service-activation',static function($old,$new):void{
 if($old===$new)return;
 if(class_exists('RankMath\\Sitemap\\Cache'))
  \RankMath\Sitemap\Cache::invalidate_storage();
},11,2);

/** Gate anonymous core REST collections and details of independently disabled services.
 * Internal verified Tour APIs and authorized editor/Elementor calls are unaffected.
 */
add_filter('rest_pre_dispatch',static function($result,$server,$request){
 if($result!==null || current_user_can('edit_posts'))return $result;
 if(!($request instanceof WP_REST_Request) || !in_array($request->get_method(),['GET','HEAD'],true))return $result;
 if(!preg_match('~^/wp/v2/([^/]+)(?:/|$)~',$request->get_route(),$matches))return $result;
 $resource=rawurldecode($matches[1]);
 if($resource==='pages' && preg_match('~^/wp/v2/pages/([0-9]+)(?:/|$)~',$request->get_route(),$page_match)){
  $page=get_post(absint($page_match[1]));
  if($page instanceof WP_Post && !Shared_Service_Public_Access_v1::object_allowed($page))
   return new WP_Error('service_landing_not_available','This landing page is not available independently.',['status'=>404]);
 }

 // CPT and taxonomy checks run in the namespace-aware REST guard.
 if($resource==='search'){
  $sub=$request->get_param('subtype');
  if(is_string($sub)&&post_type_exists($sub)&&!Shared_Service_Public_Access_v1::post_allowed($sub))
   return new WP_Error('service_search_not_available','This service is not available independently.',['status'=>404]);
 }
 return $result;
},1000,3);

/**
 * Public WordPress REST is another standalone entry point: URLs blocked at
 * template_redirect must not leak posts, rates or taxonomies through wp/v2.
 * Preserve authenticated editors, authorized integrations and Tour-native
 * booking endpoints outside the standard wp/v2 CPT/Term controllers.
 */
add_filter('rest_pre_dispatch',static function($result,$server,$request){
 if($result!==null || !$request instanceof WP_REST_Request)return $result;
 if(current_user_can('edit_posts'))return $result;
 if(!in_array($request->get_method(),['GET','HEAD'],true))return $result;
 $route='/'.ltrim($request->get_route(),'/');
 foreach(get_post_types(['show_in_rest'=>true],'objects') as $type=>$obj){
  if(Shared_Service_Public_Access_v1::post_allowed($type))continue;
  $base=trim((string)($obj->rest_base?:$type),'/');
  $namespace=trim((string)($obj->rest_namespace?:'wp/v2'),'/');
  $prefix='/'.$namespace.'/'.$base;
  if($route===$prefix || str_starts_with($route,$prefix.'/')){
   return new WP_Error('rest_no_route',__('No route was found matching the URL and request method.','default'),['status'=>404]);
  }
 }
 foreach(get_taxonomies(['show_in_rest'=>true],'objects') as $name=>$tax){
  if(Shared_Service_Public_Access_v1::tax_allowed($name))continue;
  $base=trim((string)($tax->rest_base?:$name),'/');
  $namespace=trim((string)($tax->rest_namespace?:'wp/v2'),'/');
  $prefix='/'.$namespace.'/'.$base;
  if($route===$prefix || str_starts_with($route,$prefix.'/')){
   return new WP_Error('rest_no_route',__('No route was found matching the URL and request method.','default'),['status'=>404]);
  }
 }
 return $result;
},50,3);

/** WordPress core REST Search: do not reintroduce disabled CPTs or authoring
 * templates through subtype=any, nor landing Pages under an enabled type.
 */
add_filter('rest_post_search_query',static function($args){
 if(current_user_can('edit_posts'))return $args;
 $requested=array_values(array_filter((array)($args['post_type']??[]),'is_string'));
 $allow=array_values(array_filter($requested,static function($type){
  return !in_array($type,['elementor_library','elementor-hf','jet-woo-builder'],true)
    && Shared_Service_Public_Access_v1::post_allowed($type);
 }));
 $args['post_type']=$allow?:['post'];
 if(!$allow)$args['post__in']=[0];
 $denied=Shared_Service_Public_Access_v1::excluded_landing_pages();
 if(!empty($args['post__in'])&&is_array($args['post__in'])){
  $args['post__in']=array_values(array_diff(array_map('intval',$args['post__in']),$denied))?:[0];
 }else{
  $args['post__not_in']=array_values(array_unique(array_merge(
   array_map('intval',(array)($args['post__not_in']??[])),$denied)));
 }
 return $args;
},1000,1);
add_filter('rest_term_search_query',static function($args){
 if(current_user_can('edit_posts'))return $args;
 $taxes=array_values(array_filter((array)($args['taxonomy']??[]),'is_string'));
 $allowed=array_values(array_filter($taxes,[Shared_Service_Public_Access_v1::class,'tax_allowed']));
 $args['taxonomy']=$allowed?:['category'];
 if(!$allowed)$args['include']=[0];
 return $args;
},1000,1);

/** Exclude disabled multilingual service Landing Pages before Rank Math
 * counts and paginates sitemap pages, not only at final URL rendering.
 */
foreach(['post_count/where','get_posts/where'] as $hook) {
 add_filter('rank_math/sitemap/'.$hook,static function($where,$post_type){
  if($post_type!=='page')return $where;
  $ids=Shared_Service_Public_Access_v1::excluded_landing_pages();
  if(!$ids)return $where;
  $numbers=implode(',',array_map('absint',$ids));
  return $where." AND p.ID NOT IN ($numbers) ";
 },1000,2);
}

/** Preserve all non-service Pages, but omit disabled service landings from
 * the public WP REST Page collection (including slug/search/include filters).
 */
add_filter('rest_page_query',static function($args){
 if(current_user_can('edit_posts'))return $args;
 $blocked=Shared_Service_Public_Access_v1::excluded_landing_pages();
 if(!empty($args['post__in'])){
  $args['post__in']=array_values(array_diff(array_map('intval',(array)$args['post__in']),$blocked))?:[0];
 }else{
  $args['post__not_in']=array_values(array_unique(array_merge(
    array_map('intval',(array)($args['post__not_in']??[])),$blocked)));
 }
 return $args;
},1000,1);
