<?php
/** MAD4B Unified Fulfillment v2: native Service Activation is the service-family authority.
 * Read/preview policy only; no product, order or checkout mutation.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Unified_Booking_Fulfillment_V2')){
final class MAD4B_Unified_Booking_Fulfillment_V2 {
 const SCHEMA='mad4b.unified-booking-fulfillment.v2';
 const SERVICE_OPTION='service-activation';
 const FAMILIES=['tours'=>'Tours & Activities','properties'=>'Properties & Accommodation',
  'transportations'=>'Transportation','visas'=>'Visa Services'];
 const TYPES=[
  'tours-and-activities'=>['tours',null],
  'tour-rates'=>['tours','related_tour_id'],
  'properties'=>['properties',null],
  'units'=>['properties','jet_relation_223'],
  'property-rates'=>['properties','prop_related_property_id'],
  'transportations'=>['transportations',null],
  'transport-rates'=>['transportations','related_transportation_id']
 ];
 const META='_mad4b_booking_fulfillment_override_v1';
 const MODES=['inherit','inquiry_only','request_to_book','instant_booking'];
 public static function family(string $type):?string {
  $value=self::TYPES[$type][0]??null;
  if(!$value && function_exists('service_channel_owner'))
   $value=service_channel_owner($type);
  return $value && isset(self::FAMILIES[$value])?$value:null;
 }
 public static function parents(int $id,string $type):array|WP_Error {
  $link=self::TYPES[$type][1]??null;
  if(!$link)return ['product_id'=>$id,'rate_id'=>0];
  if($link==='jet_relation_223'){
   global $wpdb;
   $table=$wpdb->prefix.'jet_rel_default';
   $parents=$wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT parent_object_id FROM $table WHERE rel_id=%d AND child_object_id=%d LIMIT 2",223,$id));
   if(count($parents)!==1)return new WP_Error('unified_fulfillment_unit_relation_unverified');
   $parent=(int)$parents[0];
  }else{
   $parent=(int)get_post_meta($id,$link,true);
  }
  $ownerType=match($type){
   'tour-rates'=>'tours-and-activities',
   'units','property-rates'=>'properties',
   'transport-rates'=>'transportations',
   default=>null
  };
  if(!$parent||!$ownerType||get_post_type($parent)!==$ownerType)
   return new WP_Error('unified_fulfillment_parent_not_verified');
  return ['product_id'=>$parent,'rate_id'=>$type==='units'?0:$id];
 }
 public static function service_mode(string $family):string {
  if(!isset(self::FAMILIES[$family]))return 'disabled';
  if(!function_exists('service_channel_mode'))return 'disabled';
  return service_channel_mode($family);
 }
 public static function configured(string $family):string|WP_Error {
  if(!isset(self::FAMILIES[$family]))return new WP_Error('unified_fulfillment_service_unknown');
  $options=get_option(self::SERVICE_OPTION,[]);
  if(!is_array($options))return new WP_Error('unified_fulfillment_service_settings_invalid');
  $mode=$options[$family.'_booking_fulfillment_mode']??'inherit';
  if($mode===null||$mode==='')$mode='inherit';
  if(!in_array($mode,self::MODES,true))return new WP_Error('unified_fulfillment_service_mode_invalid');
  return $mode;
 }
 public static function resolved(int $id,string $channel='standalone'):array|WP_Error {
  if($id<1||!get_post($id))return new WP_Error('unified_fulfillment_product_not_found');
  $type=get_post_type($id);
  $family=self::family($type);
  if(!$family)return new WP_Error('unified_fulfillment_unsupported_product_type');
  if(!in_array($channel,['standalone','tour_component'],true))
   return new WP_Error('unified_fulfillment_channel_invalid');
  if(get_post_status($id)!=='publish')return new WP_Error('unified_fulfillment_post_not_published');
  $relation=self::parents($id,$type);
  if(is_wp_error($relation))return $relation;
  $product=(int)$relation['product_id'];
  if(get_post_status($product)!=='publish')return new WP_Error('unified_fulfillment_parent_not_published');
  $global=MAD4B_Booking_Fulfillment_Policy_V1::global_mode();
  $desired=$global;$source='global';
  $familyMode=self::configured($family);
  if(is_wp_error($familyMode))return $familyMode;
  if($familyMode!=='inherit'){$desired=$familyMode;$source='service_activation';}
  $productMode=get_post_meta($product,self::META,true);
  if($productMode!==''&&$productMode!==null&&$productMode!=='inherit'){
   if(!in_array($productMode,self::MODES,true))return new WP_Error('unified_fulfillment_product_override_invalid');
   $desired=$productMode;$source='product';
  }
  // Units have their own product-level override, and can inherit their Property.
  if($id!==$product&&$type==='units'){
   $unitMode=get_post_meta($id,self::META,true);
   if($unitMode!==''&&$unitMode!==null&&$unitMode!=='inherit'){
    if(!in_array($unitMode,self::MODES,true))return new WP_Error('unified_fulfillment_unit_override_invalid');
    $desired=$unitMode;$source='unit';
   }
  }
  // A Property Rate can also inherit its source Unit override, but only
  // after the Unit -> Property relation is verified against the Rate parent.
  if($type==='property-rates'){
   $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
   if(!$unit||get_post_type($unit)!=='units')
    return new WP_Error('unified_fulfillment_rate_unit_unverified');
   $unitRelation=self::parents($unit,'units');
   if(is_wp_error($unitRelation)||$unitRelation['product_id']!==$product)
    return new WP_Error('unified_fulfillment_rate_unit_owner_mismatch');
   $unitMode=get_post_meta($unit,self::META,true);
   if($unitMode!==''&&$unitMode!==null&&$unitMode!=='inherit'){
    if(!in_array($unitMode,self::MODES,true))
     return new WP_Error('unified_fulfillment_unit_override_invalid');
    $desired=$unitMode;$source='unit';
   }
  }
  if($relation['rate_id']){
   $rateMode=get_post_meta($relation['rate_id'],self::META,true);
   if($rateMode!==''&&$rateMode!==null&&$rateMode!=='inherit'){
    if(!in_array($rateMode,self::MODES,true))return new WP_Error('unified_fulfillment_rate_override_invalid');
    $desired=$rateMode;$source='rate';
   }
  }
  $service=self::service_mode($family);
  $active= $service==='standalone_and_tour'
   ||($service==='tour_only'&&$channel==='tour_component');
  // Service activation is a hard ceiling, never a default that can be overridden.
  $effective=$desired;$blocker=null;
  if(!$active){$effective='inquiry_only';$blocker='service_activation_disallows_channel';}
  $booking=function_exists('shared_product_pricing_setting')
   ?shared_product_pricing_setting('booking_enabled'):new WP_Error('booking_setting_unavailable');
  // Current native Booking Enabled governs Tours only. Do not silently
  // interpret it as a certified cross-product checkout switch.
  if($family==='tours' && ($booking!==true)){
   $effective='inquiry_only';$blocker='tour_booking_globally_disabled';
  }
  // Other engines have no live fulfillment execution adapter yet.
  $nativeExecutor=$family==='tours'?'booking_v2':'not_certified';
  $executionReady=$family==='tours'&&$active&&$booking===true;
  if($family!=='tours'&&$desired!=='inquiry_only'&&$active){
   // Requested service policy is retained for future native adapter support,
   // but an unimplemented executor must not look live to admins or clients.
   $effective='inquiry_only';
   $blocker='service_booking_executor_not_certified';
  }
  return [
   'contract'=>self::SCHEMA,'post_type'=>$type,'post_id'=>$id,
   'family'=>$family,'product_id'=>$product,'rate_id'=>$relation['rate_id']?:null,
   'channel'=>$channel,'service_activation_mode'=>$service,
   'global_mode'=>$global,'service_override'=>$familyMode,'requested_mode'=>$desired,
   'effective_mode'=>$effective,'source'=>$source,'service_active'=>$active,
   'executor'=>$nativeExecutor,'intent_execution_ready'=>$executionReady,
   'blocker'=>$blocker,'checkout_authority'=>false,
   'payment_authority'=>false,'price_authority'=>false
  ];
 }
 public static function authorize(int $id,string $intent,string $channel='standalone'):array|WP_Error {
  if(!in_array($intent,['inquiry','quote','request','instant'],true))
   return new WP_Error('unified_fulfillment_intent_invalid');
  $decision=self::resolved($id,$channel);
  if(is_wp_error($decision))return $decision;
  if(!$decision['service_active'])return new WP_Error('unified_fulfillment_service_inactive');
  if(in_array($intent,['request','instant'],true) &&
    $decision['blocker']==='service_booking_executor_not_certified')
   return new WP_Error('unified_fulfillment_native_executor_not_ready');
  if($channel==='tour_component'){
   // A caller-supplied channel string is not evidence of Tour embedding.
   $verified=function_exists('shared_product_verified_presentation_tour_id')
     ?(int)shared_product_verified_presentation_tour_id():0;
   if($verified<1||get_post_type($verified)!=='tours-and-activities'
     ||get_post_status($verified)!=='publish')
    return new WP_Error('unified_fulfillment_tour_context_not_verified');
  }
  $mode=$decision['effective_mode'];
  $allowed=match($mode){
   'inquiry_only'=>in_array($intent,['inquiry','quote'],true),
   'request_to_book'=>in_array($intent,['inquiry','quote','request'],true),
   'instant_booking'=>true,
   default=>false
  };
  if(!$allowed)return new WP_Error('fulfillment_intent_not_permitted');
  if(in_array($intent,['request','instant'],true)&&!$decision['intent_execution_ready'])
   return new WP_Error('unified_fulfillment_native_executor_not_ready');
  return $decision+['intent'=>$intent,'intent_allowed'=>true,'booking_authority'=>false];
 }
 public static function fields(array $fields,$page):array {
  if(($page->slug??'')!==self::SERVICE_OPTION)return $fields;
  $present=array_column($fields,'name');
  foreach(self::FAMILIES as $family=>$label){
   $name=$family.'_booking_fulfillment_mode';
   if(in_array($name,$present,true))continue;
   $fields[]=['name'=>$name,'title'=>'Booking Fulfillment — '.$label,
    'type'=>'select','object_type'=>'field','options_source'=>'manual','width'=>'100%',
    'description'=>'Inherit the global Booking Fulfillment mode, or set a separate default for this Service. Service Activation channel mode remains a hard gate. Product/Unit/Rate can override the mode but cannot bypass activation, availability or checkout readiness.',
    'options'=>[
      ['key'=>'inherit','value'=>'Inherit global setting'],
      ['key'=>'inquiry_only','value'=>'Inquiry Only'],
      ['key'=>'request_to_book','value'=>'Request to Book'],
      ['key'=>'instant_booking','value'=>'Instant Booking — subject to native readiness']
    ]];
  }
  return $fields;
 }
 public static function editor_boxes():void{
  foreach(self::TYPES as $type=>$schema)
   if(post_type_exists($type))add_meta_box('mad4b_unified_fulfillment_v2',
    'Booking Fulfillment · Inheritance',[__CLASS__,'editor_box'],
    $type,'side','default');
 }
 public static function editor_box(WP_Post $post):void {
  $type=get_post_type($post);
  $family=self::family($type);
  if(!$family)return;
  $value=(string)get_post_meta($post->ID,self::META,true);
  if($value==='')$value='inherit';
  wp_nonce_field('mad4b_unified_fulfillment_'.$post->ID,'mad4b_unified_fulfillment_nonce');
  echo '<p><strong>'.esc_html(self::FAMILIES[$family]).'</strong> · <span>'.esc_html(self::service_mode($family)).'</span></p>';
  echo '<label for="mad4b_unified_fulfillment_mode">Booking fulfillment override</label>';
  echo '<select name="mad4b_unified_fulfillment_mode" id="mad4b_unified_fulfillment_mode" style="width:100%">';
  foreach(MAD4B_Booking_Fulfillment_Policy_V1::labels() as $mode=>$label)
   echo '<option value="'.esc_attr($mode).'"'.selected($value,$mode,false).'>'.esc_html($label).'</option>';
  echo '</select><p><small>Inherit Global → Service Activation → Product → Unit (where applicable) → Rate. Activation and native readiness always gate execution.</small></p>';
  if($post->post_status==='publish'){
   $decision=self::resolved((int)$post->ID);
   if(is_wp_error($decision)){
    echo '<p><small>Policy preview blocked: '.esc_html($decision->get_error_code()).'</small></p>';
   }else{
    echo '<div style="padding:9px;background:#f4faf6;border-left:3px solid #398669;border-radius:4px">';
    echo '<strong>Effective: '.esc_html(str_replace('_',' ',(string)$decision['effective_mode'])).'</strong>';
    if($decision['requested_mode']!==$decision['effective_mode'])
     echo '<p style="margin:4px 0">Requested: '.esc_html(str_replace('_',' ',(string)$decision['requested_mode'])).'</p>';
    echo '<p style="margin:4px 0">Source: '.esc_html((string)$decision['source']).
     ' · Service: '.esc_html((string)$decision['service_activation_mode']).'</p>';
    if($decision['blocker'])echo '<small>Gate: '.esc_html((string)$decision['blocker']).'</small>';
    echo '</div>';
   }
  }
 }
 public static function editor_save(int $id,WP_Post $post):void {
  if(!isset($_POST['mad4b_unified_fulfillment_nonce'])||
     !is_string($_POST['mad4b_unified_fulfillment_nonce']))return;
  if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_unified_fulfillment_nonce'])),
    'mad4b_unified_fulfillment_'.$id)||
    wp_is_post_revision($id)||(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||
    !current_user_can('edit_post',$id)||
    !MAD4B_Booking_Fulfillment_Policy_V1::permitted())return;
  $value=sanitize_key(wp_unslash($_POST['mad4b_unified_fulfillment_mode']??''));
  if($value==='inherit')delete_post_meta($id,self::META);
  elseif(in_array($value,self::MODES,true))update_post_meta($id,self::META,$value);
 }
 public static function boot():void {
  add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'fields'],55,2);
  if(!is_admin())return;
  add_action('add_meta_boxes',[__CLASS__,'editor_boxes'],25);
  foreach(array_keys(self::TYPES) as $type)
   add_action('save_post_'.$type,[__CLASS__,'editor_save'],20,2);
 }
}
MAD4B_Unified_Booking_Fulfillment_V2::boot();
}
