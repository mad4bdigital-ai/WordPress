<?php
/**
 * Plugin Name: Context-Aware Listing Recommendations
 * Description: Read-only market, manual passport, service and destination eligibility for JetEngine listings.
 */
if(!defined('ABSPATH'))exit;

function recommendation_audience_codes($source,$kind){
 $parts=is_array($source)?$source:preg_split('/[\s,;]+/',(string)$source,-1,PREG_SPLIT_NO_EMPTY);
 if(count($parts)>60)return [];
 $valid=[];
 foreach($parts as $part){
  if(!is_scalar($part))return [];
  $part=trim((string)$part);
  $part=$kind==='passport'?strtoupper($part):strtolower($part);
  $pattern=$kind==='passport'?'/^[A-Z]{2}$/D':'/^[a-z0-9_-]{1,70}$/D';
  if(!preg_match($pattern,$part))return [];
  $valid[$part]=$part;
 }
 return array_values($valid);
}
function recommendation_context(){
 $context=['market_profile_id'=>'','passport_manual'=>'','passport_auto'=>'',
   'destination_id'=>0,'surface'=>'other','market_country'=>'','passport_verified'=>false];
 global $wp_the_query;
 if($wp_the_query instanceof WP_Query){
  if($wp_the_query->is_tax('destinations')){
   $term=$wp_the_query->get_queried_object();
   if($term instanceof WP_Term&&$term->taxonomy==='destinations'){
    $context['surface']='destination';$context['destination_id']=(int)$term->term_id;
   }
  }elseif($wp_the_query->is_singular('tours-and-activities')){
   $context['surface']='tour';
  }
 }
 if(class_exists('MAD4B_SCP_Market_Context')){
  $profile=MAD4B_SCP_Market_Context::current();
  $selected=is_array($profile)?($profile['selected_market']??null):null;
  if(is_array($selected)&&!empty($selected['profile_id'])&&class_exists('MAD4B_SCP_Market_Profiles')){
   $approved=MAD4B_SCP_Market_Profiles::approved((string)$selected['profile_id']);
   if(is_array($approved)&&($approved['profile_id']??'')===$selected['profile_id']){
    $context['market_profile_id']=strtolower((string)$selected['profile_id']);
    $context['market_country']=strtoupper((string)($selected['country']??''));
   }
  }
 }
 if(class_exists('Travel_Booking_Traveller_Nationality_Switcher')){
  $manual=Travel_Booking_Traveller_Nationality_Switcher::selected_manual();
  if(is_string($manual)&&preg_match('/^[A-Z]{2}$/D',$manual))$context['passport_manual']=$manual;
  // Auto-follow is a display hint only, never verified citizenship.
  if(!$context['passport_manual']&&class_exists('MAD4B_Country_Switcher')){
   $country=MAD4B_Country_Switcher::context();
   $auto=Travel_Booking_Traveller_Nationality_Switcher::valid($country['country']??'');
   if(is_string($auto)&&preg_match('/^[A-Z]{2}$/D',$auto))$context['passport_auto']=$auto;
  }
 }
 return apply_filters('company_recommendation_context',$context);
}
function recommendation_audience_accept(array $rules,array $context,array $policy){
 $markets=recommendation_audience_codes($rules['market_profile_ids']??'','market');
 $passport=recommendation_audience_codes($rules['passport_countries']??'','passport');
 // Malformed configured restrictions never accidentally broaden targeting.
 if(!empty($rules['market_profile_ids'])&&!$markets)return false;
 if(!empty($rules['passport_countries'])&&!$passport)return false;
 if($markets){
  $selected=strtolower((string)($context['market_profile_id']??''));
  if(!$selected||!in_array($selected,$markets,true))return false;
 }
 if($passport){
  $selected=(string)($context['passport_manual']??'');
  if(!$selected&&($policy['nationality']??'')==='any_context')$selected=(string)($context['passport_auto']??'');
  if(!$selected||!in_array($selected,$passport,true))return false;
 }
 return true;
}
function recommendation_eligible_post($post_id,?array $context=null,?array $assigned_term_ids=null){
 $context=$context??recommendation_context();
 $post=get_post(absint($post_id));
 if(!$post||$post->post_status!=='publish'||$post->post_password!=='')return false;
 $service=function_exists('service_channel_owner')?service_channel_owner($post->post_type):null;
 if(!$service)return true;
 $surface=$context['surface']??'other';
 if(function_exists('service_channel_allowed')){
  $channel=$surface==='tour'?'tour_component':'standalone';
  if(!service_channel_allowed($service,$channel))return false;
 }
 $settings=get_option('service-activation',[]);
 if(!is_array($settings))$settings=[];
 $policies=[
  'nationality'=>$settings['discovery_nationality_gate']??'manual_only',
  'market'=>$settings['discovery_market_gate']??'approved_profile'
 ];
 $rules=[
  'market_profile_ids'=>get_post_meta($post->ID,'recommendation_market_profile_ids',true),
  'passport_countries'=>get_post_meta($post->ID,'recommendation_passport_countries',true)
 ];
 $rules=apply_filters('company_recommendation_post_audience',$rules,$post,$context);
 if(!is_array($rules)||!recommendation_audience_accept($rules,$context,$policies))return false;
 if($surface==='destination'&&($context['destination_id']??0)>0&&taxonomy_exists('destinations')){
  $assigned=$assigned_term_ids??wp_get_object_terms($post->ID,'destinations',['fields'=>'ids']);
  if(is_wp_error($assigned)||!is_array($assigned)||!$assigned)return false;
  $target=absint($context['destination_id']);
  $policy=(string)($settings['discovery_location_policy']??'exact_locality');
  $found=in_array($target,array_map('absint',$assigned),true);
  if(!$found&&$policy==='locality_and_subareas'){
   foreach($assigned as $term_id){
    if(term_is_ancestor_of($target,absint($term_id),'destinations')){$found=true;break;}
   }
  }
  if(!$found)return false;
 }
 return true;
}
add_filter('jet-engine/query-builder/query/items',static function($items,$query){
 if(!is_array($items)||!is_object($query))return $items;
 $context=recommendation_context();
 $page=(string)($context['surface']??'other');
 // Page request and AJAX/REST requests share an identical audience guard.
 // When AJAX has no authenticated page context, targeted products fail closed.
 if($page!=='destination'&&!wp_doing_ajax()&&!defined('REST_REQUEST'))return $items;
 $definition=is_array($query->query??null)?$query->query:[];
 $types=array_map('strval',(array)($definition['post_type']??[]));
 $supported=['tours-and-activities','properties','transportations'];
 if(!$types||array_diff($types,$supported))return $items;
 $tax=$definition['tax_query']??[];
 $uses_destinations=false;
 foreach((array)$tax as $clause)
  if(is_array($clause)&&($clause['taxonomy']??'')==='destinations'){$uses_destinations=true;break;}
 if(!$uses_destinations)return $items;
 $settings=get_option('service-activation',[]);
 if(!is_array($settings))$settings=[];
 if(($settings['discovery_enabled']??'enabled')!=='enabled' ||
    ($settings['discovery_presentation_mode']??'native_listing')==='off')return [];
 $ids=[];
 foreach($items as $item){
  $id=$item instanceof WP_Post?$item->ID:
    (is_object($item)&&isset($item->ID)?absint($item->ID):0);
  if($id)$ids[]=$id;
 }
 $ids=array_values(array_unique($ids));
 if($ids)update_meta_cache('post',$ids);
 // Fetch taxonomy associations in one batched operation instead of one SQL
 // query for every Tour / Hotel / Transport card.
 $term_map=[];
 if($page==='destination' && $ids){
  $associations=wp_get_object_terms($ids,'destinations',['fields'=>'all_with_object_id']);
  if(is_wp_error($associations))return [];
  foreach($associations as $association){
   $post_id=absint($association->object_id??0);
   if($post_id)$term_map[$post_id][]=(int)$association->term_id;
  }
 }
 $out=[];
 foreach($items as $item){
  $post_id=$item instanceof WP_Post?$item->ID:
    (is_object($item)&&isset($item->ID)?absint($item->ID):0);
  if(!$post_id){$out[]=$item;continue;}
  $assigned=$page==='destination'?($term_map[$post_id]??[]):null;
  if(recommendation_eligible_post($post_id,$context,$assigned))$out[]=$item;
 }
 return $out;
},1700,2);

// Optional product audience fields, stored as ordinary, separately controlled
// WordPress post meta and editable alongside JetEngine's other product fields.
add_action('add_meta_boxes',static function(){
 foreach(['tours-and-activities','properties','transportations'] as $type){
  if(!post_type_exists($type))continue;
  add_meta_box('recommendation-audience-rules','Recommendation Audience — Market / Passport',
   static function($post){
    wp_nonce_field('recommendation_audience_save','recommendation_audience_nonce');
    foreach([
     'recommendation_market_profile_ids'=>'Approved Market Profile IDs (comma separated; empty = all markets)',
     'recommendation_passport_countries'=>'Manual passport country codes, ISO A2 (empty = all nationalities)'
    ] as $key=>$label){
     echo '<p><label for="'.esc_attr($key).'">'.esc_html($label).'</label></p>';
     echo '<input style="width:100%" type="text" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.
      esc_attr((string)get_post_meta($post->ID,$key,true)).'" />';
    }
    echo '<p><em>Discovery visibility only. Auto passport is not verified citizenship. Never authorizes pricing, inventory, checkout or taxes.</em></p>';
   },$type,'side','default');
 }
});
add_action('save_post',static function($post_id,$post,$update){
 if(!$post instanceof WP_Post || !in_array($post->post_type,
  ['tours-and-activities','properties','transportations'],true))return;
 if(wp_is_post_revision($post_id)||wp_is_post_autosave($post_id)||
  empty($_POST['recommendation_audience_nonce'])||
  !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['recommendation_audience_nonce'])),'recommendation_audience_save')||
  !current_user_can('edit_post',$post_id))return;
 $pending=[];
 foreach(['recommendation_market_profile_ids'=>'market','recommendation_passport_countries'=>'passport'] as $key=>$kind){
  if(!isset($_POST[$key])||!is_string($_POST[$key]))continue;
  $source=trim(sanitize_text_field(wp_unslash($_POST[$key])));
  if($source===''){$pending[$key]='';continue;}
  $codes=recommendation_audience_codes($source,$kind);
  if(!$codes)return; // Reject the entire submission before any write.
  $pending[$key]=implode(',',$codes);
 }
 foreach($pending as $key=>$value){
  if($value==='')delete_post_meta($post_id,$key);
  else update_post_meta($post_id,$key,$value);
 }
},15,3);
