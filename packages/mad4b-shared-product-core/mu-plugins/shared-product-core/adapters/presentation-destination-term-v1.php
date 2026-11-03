<?php
/**
 * Read-only unified destination taxonomy presentation context.
 * Never changes Destination Activation, Service Activation, rates or inventory.
 * Elementor/JetEngine controls own authoring; this class only resolves source
 * objects, term relations, and section eligibility from canonical term data.
 */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
final class Shared_Product_Destination_Term_Presentation_v1 {
 const ARCHIVE_DOCUMENTS=[45649,48558];
 const TEMPLATES=[45799,45774,45886];
 const QUERY_RELATIONS=[
  283=>'other_countries',
  284=>'descendant:place_attraction',
  285=>'descendant:city',
  286=>'descendant:state_governorate',
  287=>'descendant:zone_area',
  288=>'sibling:state_governorate',
  289=>'sibling:city',
  290=>'sibling:zone_area',
  291=>'sibling:place_attraction',
  294=>'other_governorates',
  296=>'same_governorate_cities',
  299=>'same_city_zones',
  // Presentation-only region scope: activated destinations near the
  // current place, independently of commercial standalone inventory.
  301=>'region_attractions',
  302=>'region_areas',
  303=>'region_cities',
 ];
 const LEVELS=['continent','country','state_governorate','city','zone_area','place_attraction'];
 public static function current():?WP_Term {
  if(!is_tax('destinations'))return null;
  $term=get_queried_object();
  return $term instanceof WP_Term && $term->taxonomy==='destinations'
    ? WP_Term::get_instance((int)$term->term_id) : null;
 }
 public static function language():string {
  $lang=apply_filters('wpml_current_language',null);
  return is_string($lang)&&$lang!==''?$lang:'en';
 }
 public static function ancestors(WP_Term $term):array {
  $result=[];$cursor=$term;$seen=[];
  for($i=0;$i<30&&$cursor->parent;$i++){
   $id=(int)$cursor->parent;
   if(isset($seen[$id]))break;
   $seen[$id]=true;
   $parent=WP_Term::get_instance($id);
   if(!$parent||$parent->taxonomy!=='destinations')break;
   $result[]=$parent;
   $cursor=$parent;
  }
  return $result;
 }
 public static function ancestor(WP_Term $term,string $type):?WP_Term {
  if(!in_array($type,self::LEVELS,true))return null;
  foreach(self::ancestors($term) as $candidate)
   if((string)get_term_meta($candidate->term_id,'destination_type',true)===$type)
    return $candidate;
  return null;
 }
 public static function field(WP_Term $term,string $field):string {
  if(str_starts_with($field,'ancestor_')){
   $kind=substr($field,strlen('ancestor_'));
   $parent=self::ancestor($term,$kind);
   // Missing intermediate levels are valid; never manufacture a city.
   if(!$parent||!self::activation($parent))return '';
   return $parent->name;
  }
  if($field==='address'){
   $raw=get_term_meta($term->term_id,'google_address',true);
   return is_string($raw)?trim(wp_strip_all_tags($raw)):'';
  }
  return '';
 }
 public static function activation(WP_Term $term):bool {
  // EN is the canonical editorial policy for translations. Inherit ONLY
  // missing WPML flags from an expressly public EN term; never overwrite
  // translated 0/false, never publish a missing translation or change DB.
  $public=(string)get_term_meta($term->term_id,'is_public',true);
  if($public===''){
   $en=(int)apply_filters('wpml_object_id',$term->term_id,'destinations',false,'en');
   if($en && $en!==$term->term_id)
     $public=(string)get_term_meta($en,'is_public',true);
  }
  if($public!=='1')return false;
  if(function_exists('destinations_activation_allowed')&&!destinations_activation_allowed($term->term_id))return false;
  return true;
 }
 /**
  * A public Discovery card is not a Bookable Offer. Resolve independently
  * from sales/menu inventory: a formally activated country, public+featured
  * term, real image and non-hidden ancestry suffice for visual exploration.
  * Strict native_destinations_sale_term_enabled() remains authoritative ONLY
  * for sales navigation and checkout-oriented interfaces.
  */
 public static function eligible(WP_Term $term,string $lang):bool {
  if(!self::activation($term))return false;
  if(!in_array((string)get_term_meta($term->term_id,'is_featured',true),['1','true'],true))return false;
  if(!function_exists('native_destinations_term_has_featured_image')||
     !native_destinations_term_has_featured_image($term))return false;
  if(!function_exists('native_destination_configured'))return false;
  $approvedCountries=array_map(static fn($x)=>(int)$x['id'],native_destination_configured($lang));
  $rootpath=array_merge([$term->term_id],array_map('intval',get_ancestors($term->term_id,'destinations','taxonomy')));
  $canonicalPath=array_map(static function($id){
    $mapped=(int)apply_filters('wpml_object_id',$id,'destinations',false,'en');
    return $mapped?:$id;
  },$rootpath);
  if(!array_intersect($approvedCountries,$canonicalPath))return false;
  $activeGovs=array_map('intval',(array)(get_option('destination-activation',[])['active_governorates']??[]));
  foreach($canonicalPath as $id){
    $type=(string)get_term_meta($id,'destination_type',true);
    if($type==='state_governorate'&&!in_array($id,$activeGovs,true))return false;
    $public=(string)get_term_meta($id,'is_public',true);
    if(in_array($public,['0','false','no','disabled'],true))return false;
  }
  return true;
 }
 public static function eligible_for_sale(WP_Term $term,string $lang):bool {
  return self::eligible($term,$lang) && function_exists('native_destinations_sale_term_enabled')
    && native_destinations_sale_term_enabled($term->term_id,$lang);
 }
 private static function terms_from_ids(array $ids,string $lang,?string $targetType=null):array {
  $selected=[];
  foreach($ids as $id){
   $term=WP_Term::get_instance((int)$id);
   if(!$term||$term->taxonomy!=='destinations')continue;
   if($targetType!==null&&(string)get_term_meta($id,'destination_type',true)!==$targetType)continue;
   if(!self::eligible($term,$lang))continue;
   $selected[$term->term_id]=$term;
  }
  uasort($selected,static fn($a,$b)=>strcasecmp($a->name,$b->name));
  return array_values($selected);
 }
 private static function all_descendants(WP_Term $parent):array {
  $desc=get_term_children($parent->term_id,'destinations');
  return is_wp_error($desc)?[]:array_map('intval',$desc);
 }
 private static function same_level(WP_Term $term):array {
  if(!$term->parent)return [];
  // Restrict siblings to EXACT parent rather than every term at level.
  global $wpdb;
  return array_map('intval',$wpdb->get_col($wpdb->prepare(
   "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='destinations' AND parent=%d AND term_id<>%d",
   $term->parent,$term->term_id)));
 }
 private static function visible_countries(WP_Term $current,string $lang):array {
  if(!function_exists('native_destination_approved'))return [];
  $ids=[];
  foreach(native_destination_approved($lang) as $row){
   $id=(int)($row['translated_id']??0);
   if($id && $id!==$current->term_id)$ids[]=$id;
  }
  return self::terms_from_ids($ids,$lang,'country');
 }
 public static function relation(WP_Term $current,string $relation,string $language=''):array {
  static $memo=[];
  $lang=$language?:self::language();
  $key=$current->term_id.'|'.$relation.'|'.$lang;
  if(isset($memo[$key]))return $memo[$key];
  $result=[];$type=null;
  if(str_starts_with($relation,'descendant:')){
   $type=substr($relation,11);
   $result=self::terms_from_ids(self::all_descendants($current),$lang,$type);
  }elseif(str_starts_with($relation,'sibling:')){
   $type=substr($relation,8);
   $result=self::terms_from_ids(self::same_level($current),$lang,$type);
  }elseif($relation==='other_countries'){
   $country=self::ancestor($current,'country');
   $result=self::visible_countries($country??$current,$lang);
  }elseif($relation==='other_governorates'){
   $country=self::ancestor($current,'country');
   $country=$country?:((string)get_term_meta($current->term_id,'destination_type',true)==='country'?$current:null);
   if($country){
    $excludes=array_fill_keys(array_merge([$current->term_id],array_map(fn($x)=>$x->term_id,self::ancestors($current))),true);
    $terms=self::terms_from_ids(self::all_descendants($country),$lang,'state_governorate');
    $result=array_values(array_filter($terms,fn($t)=>!isset($excludes[$t->term_id])));
   }
  }elseif(in_array($relation,['region_attractions','region_areas','region_cities'],true)){
   $map=['region_attractions'=>'place_attraction','region_areas'=>'zone_area','region_cities'=>'city'];
   $kind=$map[$relation];
   $nearest=self::ancestor($current,'state_governorate');
   if(!$nearest && (string)get_term_meta($current->term_id,'destination_type',true)==='state_governorate')$nearest=$current;
   if($nearest){
    $terms=self::terms_from_ids(self::all_descendants($nearest),$lang,$kind);
    $result=array_values(array_filter($terms,fn($item)=>$item->term_id!==$current->term_id));
   }
  }elseif($relation==='same_governorate_cities'||$relation==='same_city_zones'){
   $kind=$relation==='same_governorate_cities'?'state_governorate':'city';
   $want=$relation==='same_governorate_cities'?'city':'zone_area';
   $parent=self::ancestor($current,$kind);
   if($parent){
    $terms=self::terms_from_ids(self::all_descendants($parent),$lang,$want);
    $result=array_values(array_filter($terms,fn($t)=>$t->term_id!==$current->term_id));
   }
  }
  return $memo[$key]=$result;
 }
 public static function by_query_id(WP_Term $current,int $query_id,string $language=''):array {
  if(!isset(self::QUERY_RELATIONS[$query_id]))return [];
  // Preserve several different hierarchy levels without rendering a huge
  // country-wide attractions catalogue inside an Elementor Swiper.
  // Twelve matches per native Listing is the single shared presentation cap.
  return array_slice(self::relation($current,self::QUERY_RELATIONS[$query_id],$language),0,12);
 }
 public static function has_related(WP_Term $current):bool {
  foreach(self::QUERY_RELATIONS as $id=>$relation){
   if(self::by_query_id($current,$id))return true;
  }
  return false;
 }
 public static function has_service(WP_Term $term,string $service):bool {
  if(!in_array($service,['tours','properties','transportations'],true))return false;
  if(!function_exists('service_channel_allowed')||!service_channel_allowed($service,'standalone'))return false;
  $types=['tours'=>'tours-and-activities','properties'=>'properties','transportations'=>'transportations'];
  $ids=array_merge([$term->term_id],self::all_descendants($term));
  if(!$ids)return false;
  global $wpdb;
  $sql="SELECT p.ID FROM {$wpdb->posts} p
   INNER JOIN {$wpdb->term_relationships} r ON r.object_id=p.ID
   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
   WHERE tt.taxonomy='destinations' AND tt.term_id IN (".implode(',',array_map('intval',$ids)).")
   AND p.post_status='publish' AND p.post_password='' AND p.post_type=%s
   AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm
     WHERE pm.post_id=p.ID AND pm.meta_key='tour_mode' AND pm.meta_value='extra_trip')
   LIMIT 1";
  return (bool)$wpdb->get_var($wpdb->prepare($sql,$types[$service]));
 }
 public static function snapshot(WP_Term $term):array {
  $related=[];
  foreach(self::QUERY_RELATIONS as $id=>$name)$related[$name]=array_map(fn($x)=>$x->term_id,self::by_query_id($term,$id));
  return ['version'=>'1.0.0','authority'=>'destination_presentation_readonly',
    'term_id'=>$term->term_id,'term_type'=>get_term_meta($term->term_id,'destination_type',true),
    'ancestors'=>array_map(fn($x)=>['id'=>$x->term_id,'name'=>$x->name,'type'=>get_term_meta($x->term_id,'destination_type',true)],self::ancestors($term)),
    'fields'=>array_combine(array_map(fn($x)=>'ancestor_'.$x,array_slice(self::LEVELS,1,4)),
      array_map(fn($x)=>self::field($term,'ancestor_'.$x),array_slice(self::LEVELS,1,4))),
    'relations'=>$related,'sections'=>[
      'about'=>trim(wp_strip_all_tags($term->description))!=='',
      'tours'=>self::has_service($term,'tours'),
      'transfers'=>self::has_service($term,'transportations'),
      'stay'=>self::has_service($term,'properties'),
      'more'=>self::has_related($term)]];
 }
}
