<?php
/**
 * Plugin Name: MAD4B Operational Services — Source Catalog v1
 * Description: Internal, non-sellable operational-service CPT with native source, stage and cost review.
 * Version: 1.1.3
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Operational_Services_V1',false)){
final class MAD4B_Operational_Services_V1 {
 const CPT='operational-services';
 const FAMILY='service-family';
 const TYPE='service-type';
 const CONTRACT='mad4b.operational-service-source.v1';
 const FAMILY_SLUG='tour-leader';
 const SERVICES=[
  'airport-meet-assist'=>['Airport Meet & Assist','per_service',0],
  'airport-waiting-standby'=>['Airport Waiting / Standby','per_hour',60],
  'airport-departure-assistance'=>['Airport Departure Assistance','per_service',0],
  'meeting-point-group-assembly'=>['Meeting Point / Group Assembly','per_service',0],
  'train-station-assistance'=>['Train Station Assistance','per_service',0],
  'cruise-embark-disembark'=>['Cruise Embarkation / Disembarkation Support','per_service',0],
  'hotel-check-in-assistance'=>['Hotel Check-in Assistance','per_service',0],
  'intercity-handover'=>['Intercity Handover','per_service',0],
  'tour-leader-escort'=>['Tour Leader Escort / Coordination','per_day',0],
 ];
 const BASIS=['per_service','per_hour','per_day','per_person','per_group','per_room','per_night','custom'];
 const META=[
  'service_unit'=>'string','supplier_mode'=>'string','supplier_id'=>'string',
  'currency'=>'string','base_cost_minor'=>'integer','included_wait_minutes'=>'integer',
  'extra_wait_cost_minor'=>'integer','availability_notes'=>'string',
  'location_scope'=>'string','operational_notes'=>'string','cost_state'=>'string',
  'cost_evidence_reference'=>'string'
 ];
 public static function register():void{
   if(!post_type_exists(self::CPT))register_post_type(self::CPT,[
     'label'=>'Operational Services','labels'=>[
       'name'=>'Operational Services','singular_name'=>'Operational Service',
       'add_new_item'=>'Add Operational Service','edit_item'=>'Edit Operational Service'],
     'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,
     'show_ui'=>true,'show_in_menu'=>true,'show_in_rest'=>false,
     'rest_base'=>'operational-services',
     'supports'=>['title','editor','revisions'],
     'menu_icon'=>'dashicons-groups','has_archive'=>false,
     'capability_type'=>'post','map_meta_cap'=>true,
     'rewrite'=>false
   ]);
   foreach([
    self::FAMILY=>['Service Families','Service Family'],
    self::TYPE=>['Service Types','Service Type']
   ] as $taxonomy=>$label){
     if(!taxonomy_exists($taxonomy))register_taxonomy($taxonomy,[self::CPT],[
       'labels'=>['name'=>$label[0],'singular_name'=>$label[1]],
       'public'=>false,'publicly_queryable'=>false,
       'show_ui'=>true,'show_admin_column'=>true,'show_in_rest'=>false,
       'hierarchical'=>true,'rewrite'=>false
     ]);
   }
   foreach(self::META as $field=>$type) {
      register_post_meta(self::CPT,'_mad4b_os_'.$field,[
        'single'=>true,'type'=>$type,'show_in_rest'=>false,
        'auth_callback'=>static fn($allowed,$metaKey,$postId)=>
          current_user_can('edit_post',(int)$postId)
      ]);
   }
 }
 public static function field(int $id,string $name) {
   return get_post_meta($id,'_mad4b_os_'.$name,true);
 }
 public static function source(int $id):array|WP_Error{
   if($id<1||get_post_type($id)!==self::CPT)
     return new WP_Error('operational_service_not_found');
   $terms=wp_get_post_terms($id,self::TYPE,['fields'=>'slugs']);
   $families=wp_get_post_terms($id,self::FAMILY,['fields'=>'slugs']);
   if(is_wp_error($terms)||is_wp_error($families))
     return new WP_Error('operational_service_taxonomy_unavailable');
   $mode=(string)self::field($id,'supplier_mode');
   $supplier=(string)self::field($id,'supplier_id');
   $external=$mode==='external';
   if(!$external){$mode='self';$supplier='self';}
   $unit=(string)self::field($id,'service_unit');
   if(!in_array($unit,self::BASIS,true))$unit='per_service';
   $state=(string)self::field($id,'cost_state');
   if(!in_array($state,['unpriced','draft_cost','requires_approval'],true))
     $state='unpriced';
   return ['contract'=>self::CONTRACT,'id'=>$id,
    'title'=>wp_strip_all_tags(get_the_title($id)),
    'family_slugs'=>$families,'type_slugs'=>$terms,
    'service_unit'=>$unit,'supplier_mode'=>$mode,'supplier_id'=>$supplier,
    'supplier_assignment_valid'=>!$external||$supplier!=='',
    'currency'=>(string)self::field($id,'currency'),
    'base_cost_minor'=>self::field($id,'base_cost_minor'),
    'included_wait_minutes'=>(int)self::field($id,'included_wait_minutes'),
    'extra_wait_cost_minor'=>self::field($id,'extra_wait_cost_minor'),
    'cost_state'=>$state,
    'location_scope'=>(string)self::field($id,'location_scope'),
    'availability_notes'=>(string)self::field($id,'availability_notes'),
    'cost_evidence_reference'=>(string)self::field($id,'cost_evidence_reference'),
    'cost_approved'=>false,'retail_price_authorized'=>false,
    'supplier_booking_confirmed'=>false,'checkout_ready'=>false,
    'financial_owner'=>'Tour Rate / 278-279, not this catalog'];
 }
 public static function byVariant(string $variant):int{
   static $cache=[];
   if(!isset(self::SERVICES[$variant]))return 0;
   if(array_key_exists($variant,$cache))return $cache[$variant];
   $ids=get_posts(['post_type'=>self::CPT,'post_status'=>'publish',
      'posts_per_page'=>2,'fields'=>'ids','suppress_filters'=>true,
      'tax_query'=>[['taxonomy'=>self::TYPE,'field'=>'slug','terms'=>[$variant]]]]);
   return $cache[$variant]=count($ids)===1?(int)$ids[0]:0;
 }
 public static function box($post):void{
   if(!($post instanceof WP_Post)||$post->post_type!==self::CPT)return;
   wp_nonce_field('mad4b_os_save_v1','mad4b_os_nonce');
   $fields=[
      ['service_unit','Unit of calculation',self::BASIS],
      ['supplier_mode','Supplier assignment',['self','external']],
      ['supplier_id','Supplier reference (only for external)','text'],
      ['currency','Cost currency',['EGP','USD','EUR','GBP']],
      ['base_cost_minor','DRAFT internal cost, minor units (not retail)','number'],
      ['included_wait_minutes','Included waiting minutes (0 means unset)','number'],
      ['extra_wait_cost_minor','DRAFT extra waiting cost/minor unit','number'],
      ['cost_state','Cost verification state',['unpriced','draft_cost','requires_approval']],
      ['cost_evidence_reference','Original supplier receipt / internal ledger reference','text'],
      ['location_scope','Location / point of service','text'],
      ['availability_notes','Availability restrictions and shift policy','textarea'],
      ['operational_notes','Operational terms / obligations','textarea']
   ];
   echo '<p><strong>Internal fulfillment source only.</strong> These fields do not authorize Rate pricing, Woo charges, supplier stock, or booked availability. Company is the default supplier unless explicitly assigned.</p>';
   echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">';
   foreach($fields as [$field,$label,$kind]){
     $value=self::field((int)$post->ID,$field);
     if($field==='service_unit' && $value==='')$value='per_service';
     if($field==='supplier_mode' && $value==='')$value='self';
     if($field==='cost_state' && $value==='')$value='unpriced';
     if($field==='currency' && $value==='')$value='EGP';
     $name='mad4b_os_'.$field;
     echo '<label style="display:block"><strong>'.esc_html($label).'</strong><br>';
     if(is_array($kind)){
       echo '<select name="'.esc_attr($name).'" style="width:100%">';
       foreach($kind as $option)
         echo '<option value="'.esc_attr($option).'" '.selected((string)$value,(string)$option,false).'>'.esc_html($option).'</option>';
       echo '</select>';
     }elseif($kind==='textarea'){
       echo '<textarea name="'.esc_attr($name).'" rows="2" style="width:100%">'.esc_textarea((string)$value).'</textarea>';
     }else{
       echo '<input type="'.($kind==='number'?'number':'text').'" '.($kind==='number'?'min="0" step="1" ':''). 'name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" style="width:100%">';
     }
     echo '</label>';
   }
   echo '</div>';
 }
 public static function boxes():void{
   if((bool)get_option("mad4b_operational_services_jet_owner_v1"))return;
   add_meta_box('mad4b_ops_service_cost_v1','MAD4B — Fulfillment & Cost Draft',
     [self::class,'box'],self::CPT,'normal','high');
 }
 private static function cleaned(string $field,$raw):?string{
   if(!is_scalar($raw)||is_bool($raw))return null;
   $v=trim((string)wp_unslash($raw));
   if(strlen($v)>3000)return null;
   if(in_array($field,['base_cost_minor','extra_wait_cost_minor','included_wait_minutes'],true)){
     if($v==='')return '';
     if(!preg_match('/^[0-9]+$/D',$v)||strlen($v)>16)return null;
     $i=filter_var($v,FILTER_VALIDATE_INT);
     return $i===false||$i<0?null:(string)$i;
   }
   if($field==='service_unit')return in_array($v,self::BASIS,true)?$v:null;
   if($field==='supplier_mode')return in_array($v,['self','external'],true)?$v:null;
   if($field==='currency')return in_array($v,['EGP','USD','EUR','GBP'],true)?$v:null;
   if($field==='cost_state')return in_array($v,
     ['unpriced','draft_cost','requires_approval'],true)?$v:null;
   return sanitize_textarea_field($v);
 }
 public static function save(int $postId,WP_Post $post):void{
   if((bool)get_option("mad4b_operational_services_jet_owner_v1"))return;
   if(wp_is_post_revision($postId)||wp_is_post_autosave($postId)||
      !current_user_can('edit_post',$postId)||
      !isset($_POST['mad4b_os_nonce'])||
      !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_os_nonce'])),'mad4b_os_save_v1'))
      return;
   $values=[];
   foreach(self::META as $field=>$type){
     $name='mad4b_os_'.$field;
     if(!array_key_exists($name,$_POST))continue;
     $value=self::cleaned($field,$_POST[$name]);
     if($value===null)return; // atomic refusal of malformed input
     $values[$field]=$value;
   }
   $mode=$values['supplier_mode']??self::field($postId,'supplier_mode')?:'self';
   $sid=$values['supplier_id']??self::field($postId,'supplier_id')?:'';
   if($mode==='external' && trim($sid)==='')return;
   if($mode==='self')$values['supplier_id']='self';
   foreach($values as $field=>$value)
      if($value==='')delete_post_meta($postId,'_mad4b_os_'.$field);
      else update_post_meta($postId,'_mad4b_os_'.$field,$value);
 }
 public static function pricePreflight($hint,$plan,$input){
   if(!is_array($hint)||!is_array($plan)||!is_array($input)||
       ($plan['kind']??'')!=='tour')return $hint;
   if(!class_exists('MAD4B_Tour_Leader_Itinerary_Audit_V1',false))return $hint;
   $tour=(int)($plan['parent_id']??0);
   $program=(int)($input['program_term_id']??0);
   $found=MAD4B_Tour_Leader_Itinerary_Audit_V1::serviceRows($tour,$program);
   $byStatus=[];$mapped=[];$missing=[];
   foreach($found as $entry){
     $id=(int)($entry['id']??0);
     if($id&&get_post_type($id)===self::CPT){
       $mapped[]=$id;
     }else $missing[]=(string)($entry['stage']??'');
     $role=(string)($entry['role']??'unassigned');
     $byStatus[$role]=($byStatus[$role]??0)+1;
   }
   $hint['tour_leader_cost_preflight']=[
     'contract'=>'mad4b.tour-leader-rate-preflight.v1',
     'candidate_count'=>count($found),'source_statuses'=>$byStatus,
     'catalog_source_ids'=>array_values(array_unique($mapped)),
     'unmapped_source_days'=>$missing,
     'source_contract'=>self::CONTRACT,'cost_approved'=>false,
     'price_approved'=>false,'retail_amount_modified'=>false,
     'requires_original_day_and_cost_review'=>count($found)>0
   ];
   return $hint;
 }
 public static function boot():void{
   // JetEngine registers native CPT at init 10; this is a graceful fallback only.
   add_action('init',[self::class,'register'],30);
   add_action('add_meta_boxes_'.self::CPT,[self::class,'boxes']);
   add_action('save_post_'.self::CPT,[self::class,'save'],10,2);
   add_filter('mad4b/rate_generator/plan',[self::class,'pricePreflight'],52,3);
   add_filter('mad4b/booking_operations/service_types',static function($types){
      if(!is_array($types))$types=[];
      $types[]=self::CPT;
      return array_values(array_unique($types));
   },20,1);
 }
}
MAD4B_Operational_Services_V1::boot();
}
