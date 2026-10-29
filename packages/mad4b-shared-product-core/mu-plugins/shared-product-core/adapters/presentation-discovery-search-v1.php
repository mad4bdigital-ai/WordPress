<?php
/**
 * Unified Presentation Context: Discovery Search v1.
 *
 * Read-only query source arbitration. This is NOT the Rate/Booking context;
 * no write, price, availability or authorization is granted by this resolver.
 */
defined('ABSPATH')||exit;
if(wp_get_environment_type()==='local' && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY) {
final class Shared_Product_Discovery_Search_Presentation_v1 {
 private const SCOPES=['all','destinations','tours-and-activities','properties','transportations','articles'];
 private const TERMS_LISTING=45927;
 private const TERM_PROVIDER='registry_search_destination_grid';
 private const DOC=30861;
 private const ID='6771764c';

 public static function scope(?array $source=null):string {
  $vars=$source??$_GET;
  $v=$vars['from']??'all';
  if(!is_scalar($v))return 'invalid';
  $v=sanitize_key((string)wp_unslash((string)$v));
  $aliases=['destination'=>'destinations','hotels'=>'properties','transfers'=>'transportations','services'=>'all',
    // Historical presentation alias; never a post type, never the canonical scope.
    'tours'=>'tours-and-activities'];
  $v=$aliases[$v]??$v;
  return in_array($v,self::SCOPES,true)?$v:'invalid';
 }
 public static function keyword(?array $source=null):string {
  $v=($source??$_GET)['s']??'';
  if(!is_scalar($v))return '';
  return trim(mb_substr(sanitize_text_field(wp_unslash((string)$v)),0,100));
 }
 public static function language():string {
  $lang=(string)apply_filters('wpml_current_language',null);
  return $lang!==''?$lang:'en';
 }
 public static function terms(string $language,string $keyword=''):array {
  if(!function_exists('native_destinations_sale_term_index')||
     !function_exists('native_destinations_sale_term_enabled'))return [];
  $option=(array)get_option('service-activation',[]);
  if(($option['discovery_enabled']??'disabled')!=='enabled' ||
     !in_array('destination',(array)($option['discovery_surfaces']??[]),true))return [];
  $idx=native_destinations_sale_term_index($language);
  $normalized=remove_accents(mb_strtolower($keyword,'UTF-8'));
  $out=[];
  foreach(array_keys($idx) as $en_id){
   $mapped=(int)apply_filters('wpml_object_id',(int)$en_id,'destinations',false,$language);
   if(!$mapped)continue;
   $term=WP_Term::get_instance($mapped);
   if(!$term||$term->taxonomy!=='destinations')continue;
   if((string)get_term_meta((int)$en_id,'destination_type',true)==='continent')continue;
   if(!native_destinations_sale_term_enabled($mapped,$language))continue;
   if($normalized!==''){
    $hay=remove_accents(mb_strtolower(wp_strip_all_tags($term->name.' '.$term->slug.' '.$term->description),'UTF-8'));
    if(mb_strpos($hay,$normalized,0,'UTF-8')===false)continue;
   }
   $out[$mapped]=$term->name;
  }
  natcasesort($out);
  return array_map('intval',array_keys($out));
 }
 public static function resolve(?array $source=null):array {
  $scope=self::scope($source);
  $keyword=self::keyword($source);
  $limit=function_exists('registry_search_limit')?registry_search_limit():6;
  $locale=self::language();
  $descriptor=['ok'=>$scope!=='invalid','version'=>'1.0.0','read_only'=>true,
   'slot'=>'discovery.search.results','scope'=>$scope,'keyword'=>$keyword,'locale'=>$locale,
   'template_id'=>self::DOC,'grid_widget_id'=>self::ID,
   'limit'=>$limit,'booking_authorized'=>false,'mutations_allowed'=>false];
  if($scope==='destinations'){
   $ids=self::terms($locale,$keyword);
   $variants=(array)get_option('registry_search_destination_card_listings',[]);
   $grid_id=(int)($variants['grid']??0);
   $list_id=(int)($variants['list']??0);
   $grid_id=$grid_id && get_post_status($grid_id)==='publish'?$grid_id:self::TERMS_LISTING;
   $list_id=$list_id && get_post_status($list_id)==='publish'?$list_id:$grid_id;
   // Retain the original Grid card's INTERNAL vertical composition across
   // both display modes. Grid/List only changes the OUTER column count.
   // A single JetEngine listing avoids unsigned AJAX render requests.
   $shared_id=$grid_id;
   return $descriptor+['source_kind'=>'taxonomy_terms','taxonomy'=>'destinations',
    'listing_id'=>$shared_id,'list_listing_id'=>$shared_id,'provider_id'=>self::TERM_PROVIDER,
    'term_ids'=>$ids,'total'=>count($ids),
    'pagination'=>'native_jetengine_term_offset'];
  }
  if($scope==='invalid')return $descriptor+['source_kind'=>'none','post_types'=>[],'listing_id'=>0,'provider_id'=>''];
  $types=function_exists('registry_search_public_types')?registry_search_public_types():['post'];
  $owners=['tours-and-activities'=>'tours-and-activities','properties'=>'properties','transportations'=>'transportations','articles'=>'post'];
  if($scope!=='all'){
   $type=$owners[$scope]??null;
   $types=$type && post_type_exists($type) && in_array($type,$types,true)?[$type]:[];
  }
  return $descriptor+['source_kind'=>'wp_posts','post_types'=>$types,
   'listing_id'=>16271,'list_listing_id'=>16271,'provider_id'=>'archive_category','term_ids'=>[]];
 }
 public static function active():bool {
  return !is_admin()&&!wp_doing_ajax()&&!(defined('REST_REQUEST')&&REST_REQUEST)
    && (is_search() || (isset($_GET['s'])&&is_scalar($_GET['s'])));
 }
}
}
