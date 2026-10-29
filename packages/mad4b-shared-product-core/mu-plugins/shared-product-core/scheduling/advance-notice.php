<?php
/** Inheritable, generic notice deadlines for composite bookings. No inventory authority. Lab only. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
final class Shared_Product_Advance_Notice {
 const META_KEY='shared_product_advance_notice_days';
 const VERSION='1.0.0';
 public static function types(): array {
  $types=['tours-and-activities'=>'Tour / optional activity','tour-rates'=>'Tour / extra rate',
   'properties'=>'Accommodation / hotel','units'=>'Accommodation unit / room / cabin',
   'transportations'=>'Transportation','transport-com'=>'Transport service','guides'=>'Guide service'];
  $types=apply_filters('shared_product_advance_notice_types',$types);
  if(!is_array($types))return [];
  return array_filter($types,static fn($label,$type)=>is_string($type)&&post_type_exists($type)&&is_string($label),ARRAY_FILTER_USE_BOTH);
 }
 public static function woocommerce_fields(): array {
  $fields=[['title'=>'Component-specific advance notice','type'=>'title','id'=>'shared_product_notice_components_title',
    'desc'=>'Optional overrides; blank means inherit. Each source may come from this type default, an existing JetEngine post/term meta, or the item meta box. The longest notice across booked components applies.']];
  $all=get_taxonomies([], 'objects');
  foreach(self::types() as $type=>$label){
   $fields[]=['title'=>$label.' default (days)','id'=>'shared_product_schedule_lead_default_'.$type,
    'type'=>'number','custom_attributes'=>['min'=>0,'max'=>365,'step'=>1],
    'desc'=>'Blank = inherit. 0 = explicitly allow same-day. 1–365 = days before service date.'];
   $fields[]=['title'=>$label.' existing meta key','id'=>'shared_product_schedule_lead_meta_'.$type,
    'type'=>'text','desc'=>'Optional existing JetEngine post meta field. Generic item meta-box works even when blank.'];
   $taxes=[''=>'Do not use taxonomy term meta'];
   foreach(get_object_taxonomies($type,'names') as $slug){
    $term=$all[$slug]??null;$taxes[$slug]=($term?$term->label:$slug).' ('.$slug.')';
   }
   $fields[]=['title'=>$label.' taxonomy','id'=>'shared_product_schedule_lead_taxonomy_'.$type,
    'type'=>'select','options'=>$taxes,'desc'=>'Dynamically discovered registered taxonomies for this content type.'];
   $fields[]=['title'=>$label.' term meta key','id'=>'shared_product_schedule_lead_term_meta_'.$type,
    'type'=>'text','desc'=>'Optional existing term meta. If multiple terms match, the longest notice wins.'];
  }
  $fields[]=['type'=>'sectionend','id'=>'shared_product_notice_components_title'];
  return $fields;
 }
 public static function option_suffix(string $type): string { return str_replace('-','_',sanitize_key($type)); }
 private static function days($value): ?int {
  if(!is_scalar($value)||is_bool($value)||$value==='')return null;
  if(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>365]])===false)return null;
  return (int)$value;
 }
 private static function legacy_tour_filter(array $value): array {
  if($value['type']!=='tours-and-activities')return $value;
  $days=apply_filters('shared_product_program_schedule_lead_days',$value['days'],$value['id']);
  if(self::days($days)!==null)$value['days']=(int)$days;
  return $value;
 }
 public static function product_binding(int $id): array {
  $type=get_post_type($id);
  $plugin='TinySolutions\\cptwooint\\Helpers\\Fns';
  $integrated=class_exists($plugin)&&$plugin::is_supported($type);
  $product=$integrated&&function_exists('wc_get_product')?wc_get_product($id):null;
  return ['source_post_id'=>$id,'source_post_type'=>$type,
   'woocommerce_integrated'=>$integrated,
   'woo_product_id'=>$product instanceof WC_Product?(int)$product->get_id():null,
   'duplicate_product_created'=>false];
 }
 public static function source(int $id): array|WP_Error {
  $type=get_post_type($id);
  if(!$type||!isset(self::types()[$type])||get_post_status($id)!=='publish'||!get_post($id))
   return new WP_Error('advance_notice_invalid_component','Unsupported or missing schedule component');
  // Existing post-meta key may be selected globally per type; generic meta-box key is fallback.
  $custom=sanitize_key((string)Shared_Product_Schedule_Policy::option('lead_meta_'.$type,''));
  foreach(array_unique(array_filter([$custom,self::META_KEY,
        $type==='tours-and-activities'?(string)Shared_Product_Schedule_Policy::option('lead_meta_key',''):''])) as $key) {
   $value=get_post_meta($id,$key,true);
   $days=self::days($value);
   if($days!==null)return self::legacy_tour_filter(['days'=>$days,'source'=>'post_meta','key'=>$key,'id'=>$id,'type'=>$type,'level'=>'entity'])+['product_binding'=>self::product_binding($id)];
  }
  // Optional existing taxonomy term-meta source per post type.
  $taxonomy=sanitize_key((string)Shared_Product_Schedule_Policy::option('lead_taxonomy_'.$type,
     $type==='tours-and-activities'?Shared_Product_Schedule_Policy::option('lead_term_taxonomy',''):''));
  $meta=sanitize_key((string)Shared_Product_Schedule_Policy::option('lead_term_meta_'.$type,
     $type==='tours-and-activities'?Shared_Product_Schedule_Policy::option('lead_term_meta_key',''):''));
  if($taxonomy&&$meta&&taxonomy_exists($taxonomy)&&is_object_in_taxonomy($type,$taxonomy)){
   $terms=get_the_terms($id,$taxonomy);$terms=is_array($terms)?$terms:[];
   $found=[];
   foreach($terms as $term){
    $days=self::days(get_term_meta((int)$term->term_id,$meta,true));
    if($days!==null)$found[]=['days'=>$days,'source'=>'term_meta','key'=>$meta,'term_id'=>(int)$term->term_id,'id'=>$id,'type'=>$type,'level'=>'taxonomy'];
   }
   if($found){
    usort($found,static fn($a,$b)=>$b['days']<=>$a['days']);
    return self::legacy_tour_filter($found[0])+['product_binding'=>self::product_binding($id)]; // multiple terms: longest mandatory notice, deterministic.
   }
  }
  $default=self::days(Shared_Product_Schedule_Policy::option('lead_default_'.$type,''));
  if($default!==null)return self::legacy_tour_filter(['days'=>$default,'source'=>'woocommerce_type_default','id'=>$id,'type'=>$type,'level'=>'type'])+['product_binding'=>self::product_binding($id)];
  $global=self::days(Shared_Product_Schedule_Policy::option('lead_days',5));
  if($global===null)$global=5;
  return self::legacy_tour_filter(['days'=>$global,'source'=>'woocommerce_global','id'=>$id,'type'=>$type,'level'=>'global'])+['product_binding'=>self::product_binding($id)];
 }
 public static function resolve(int $id,?int $parent_id=null): array|WP_Error {
  $own=self::source($id);
  if(is_wp_error($own))return $own;
  // A child inherits its parent if the child has no entity/term/type rule.
  if($parent_id && $parent_id!==$id && $own['level']==='global'){
   $parent=self::source($parent_id);
   if(!is_wp_error($parent)){
    $own=$parent+['inherited_by'=>$id];
    $own['inherited_by']=$id;
    $own['origin_component_id']=$parent_id;
    $own['type']=get_post_type($id);
    $own['id']=$id;
    $own['level']='inherited';
    $own['product_binding']=self::product_binding($id);
   }
  }
  return $own;
 }
 public static function linked_unit(int $unit,int $property,int $rate=0): bool {
  if(!$unit||get_post_type($unit)!=='units'||get_post_status($unit)!=='publish')return false;
  $linked=false;
  if($property && get_post_type($property)==='properties'){
   foreach(['unit_related_property_id','related_property_id','prop_related_property_id'] as $key){
    $value=get_post_meta($unit,$key,true);
    if((int)$value===$property){$linked=true;break;}
   }
   if(!$linked)foreach(['prop_related_unit_id','related_units_id'] as $key){
    $value=get_post_meta($property,$key,true);
    if(is_scalar($value)&&ctype_digit((string)$value))$value=[(int)$value];
    if(is_array($value)&&in_array($unit,array_map('intval',$value),true)){$linked=true;break;}
   }
  }
  $linked=apply_filters('shared_product_advance_notice_unit_link_valid',$linked,$unit,$property,$rate);
  return $linked===true;
 }
 public static function combine(int $tour,?int $rate=null,array $selections=[],array $extras=[],array $additional=[]): array|WP_Error {
  $property=absint($selections['property_id']??0);
  $unit=absint($selections['unit_id']??0);
  $descriptors=[['id'=>$tour,'parent'=>null,'role'=>'tour','active'=>!$rate,'branch'=>'base']];
  if($rate)$descriptors[]=['id'=>$rate,'parent'=>$tour,'role'=>'rate','active'=>true,'branch'=>'base'];
  if($property)$descriptors[]=['id'=>$property,'parent'=>null,'role'=>'property','active'=>!$unit,'branch'=>'stay'];
  if($unit)$descriptors[]=['id'=>$unit,'parent'=>$property?:null,'role'=>'unit','active'=>true,'branch'=>'stay'];
  if(!empty($selections['transport_id']))$descriptors[]=['id'=>absint($selections['transport_id']),'parent'=>null,'role'=>'transport','active'=>true,'branch'=>'transport'];
  foreach($extras as $item){
   $extra=absint($item['tour_id']??$item['extra_tour_id']??0);
   $extraRate=absint($item['rate_id']??$item['extra_rate_id']??0);
   if($extra)$descriptors[]=['id'=>$extra,'parent'=>null,'role'=>'extra','active'=>!$extraRate,'branch'=>'extra:'.$extra];
   if($extraRate)$descriptors[]=['id'=>$extraRate,'parent'=>$extra,'role'=>'extra_rate','active'=>true,'branch'=>'extra:'.$extra];
  }
  foreach($additional as $entry)if(is_array($entry)&&!empty($entry['id']))
   $descriptors[]=['id'=>absint($entry['id']),'parent'=>absint($entry['parent']??0),'role'=>'extension','active'=>true,'branch'=>'extension:'.absint($entry['id'])];
  $result=[];$seen=[];$max=0;$winner=[];
  foreach($descriptors as $descriptor){
   $id=$descriptor['id'];if(!$id||isset($seen[$id]))continue;$seen[$id]=true;
   $data=self::resolve($id,$descriptor['parent']?:null);
   if(is_wp_error($data))return $data;
   $data['role']=$descriptor['role'];$data['branch']=$descriptor['branch'];
   $data['effective']=$descriptor['active'];$result[]=$data;
   if($descriptor['active']&&($data['days']>$max||!$winner)){$max=$data['days'];$winner=$data;}
  }
  return ['days'=>$max,'rule'=>'maximum_required_notice','winner'=>$winner,
   'components'=>$result,'source'=>'composite_advance_notice','policy_version'=>self::VERSION];
 }
}
// Generic per-item UI; blank value deletes override and inherits; integer zero is explicit.
add_action('add_meta_boxes',static function(){
 foreach(Shared_Product_Advance_Notice::types() as $type=>$label){
  add_meta_box('shared-product-advance-notice','Advance Notice (Booking / Requests)',
   static function($post){
    wp_nonce_field('shared_product_notice_save','shared_product_notice_nonce');
    $raw=get_post_meta($post->ID,Shared_Product_Advance_Notice::META_KEY,true);
    echo '<p><label for="shared-product-advance-days">Minimum days before service date</label></p>';
    echo '<input id="shared-product-advance-days" name="shared_product_advance_notice_days" type="number" min="0" max="365" step="1" style="width:100px" value="'.esc_attr($raw).'">';
    echo '<p class="description">Blank = inherit policy. Zero = same-day request allowed. This does not confirm inventory or booking.</p>';
   },$type,'side','default');
 }
});
add_action('save_post',static function($post_id,$post,$update){
 if(!isset($_POST['shared_product_notice_nonce'])||
    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_product_notice_nonce'])),'shared_product_notice_save'))return;
 if(wp_is_post_revision($post_id)||wp_is_post_autosave($post_id)||!current_user_can('edit_post',$post_id))return;
 if(!isset(Shared_Product_Advance_Notice::types()[$post->post_type]))return;
 $value=isset($_POST['shared_product_advance_notice_days'])?
  sanitize_text_field(wp_unslash($_POST['shared_product_advance_notice_days'])):'';
 if($value===''){delete_post_meta($post_id,Shared_Product_Advance_Notice::META_KEY);return;}
 if(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>365]])===false)return;
 update_post_meta($post_id,Shared_Product_Advance_Notice::META_KEY,(string)(int)$value);
},20,3);
