<?php
/**
 * Public VIEW-ONLY preview of one explicitly approved local QA Tour.
 *
 * WordPress service/public-sale policy and the commercial write hold
 * stay unchanged. QA posts remain excluded from normal archives.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

function lab_qa_tour_public_view_context(): bool {
 if (!function_exists('partner_rights_local_write_hold_active')
   || !partner_rights_local_write_hold_active()) return false;
 $valid_fixture=get_post_status(66996)==='publish'
   && (string)get_post_meta(66996,'_booking_experience_qa_pricing_fixture',true)
     === 'cart-lab-20261026:tour';
 if (!$valid_fixture)return false;
 // Native JetSmartFilters has no main singular WP_Query. The signed context
 // is attested by the existing JSF validator BEFORE Query Builder executes.
 if (wp_doing_ajax() && ($_REQUEST['action']??'')==='jet_smart_filters')
   return ($GLOBALS['lab_qa_verified_signed_filter_tour']??0)===66996;
 global $wp_the_query;
 return $wp_the_query instanceof WP_Query
   && $wp_the_query->is_singular('tours-and-activities')
   && (int)$wp_the_query->get_queried_object_id() === 66996;
}

/** Do not use this function to authorize prices, bookings, or REST writes. */
function lab_qa_public_tour_view_only_allowed($id): bool {
 if (!lab_qa_tour_public_view_context()) return false;
 $id=absint($id);
 $post=$id?get_post($id):null;
 if (!$post instanceof WP_Post || $post->post_status!=='publish') return false;
 if ($id===66996) return true;
 $marker=(string)get_post_meta($id,'_booking_experience_qa_pricing_fixture',true);
 $qa_match=str_starts_with($marker,'cart-lab-20261026:')
  ||($post->post_type==='transportations'
     &&$marker==='local-qa-transport-matrix-20261027');
 if(!$qa_match)return false;
 if ($post->post_type==='tour-rates')
  return (int)get_post_meta($id,'related_tour_id',true)===66996;
 if ($post->post_type==='tours-and-activities'){
  if ((string)get_post_meta($id,'tour_mode',true)!=='extra_trip')return false;
  return in_array($id,array_map('absint',(array)get_post_meta(66996,'tour_optional_extras',true)),true);
 }
 if ($post->post_type==='transportations'){
  // Only verified native display candidates may bypass the QA password.
  // A linked but wrong-mode or unavailable transport remains protected.
  if(!class_exists('Shared_Product_Presentation_Transport_Context_v1'))return false;
  foreach (['vehicles'=>'vehicles_transport_services',
            'train'=>'train_transport_services','air'=>'air_transport_services'] as $category=>$key){
   if(!in_array($id,array_map('absint',(array)get_post_meta(66996,$key,true)),true))continue;
   $eligible=Shared_Product_Presentation_Transport_Context_v1::candidate($id,$category);
   if(!empty($eligible['ok']))return true;
  }
  return false;
 }
 if ($post->post_type==='properties'){
  static $verified_stay_properties=null;
  if ($verified_stay_properties===null){
   $verified_stay_properties=[];
   foreach ([67076,67078,67080,67082,67084,67086,67088,67090,67092] as $rate){
    if ((int)get_post_meta($rate,'related_tour_id',true)!==66996)continue;
    foreach ((array)get_post_meta($rate,'_booking_experience_stay_stages_v1',true) as $stage){
     foreach ((array)($stage['properties']??[]) as $pid)$verified_stay_properties[(int)$pid]=true;
    }
   }
  }
  return isset($verified_stay_properties[$id]);
 }
 return false;
}

/** Suppress the fixture exclusion only for the native verified Tour detail.
 * No relaxation of global search, archives, standalone Extras, REST or admin.
 */
add_action('pre_get_posts',static function($query):void {
 if (!$query instanceof WP_Query || !lab_qa_tour_public_view_context())return;
 $types=(array)$query->get('post_type');
 if (!array_intersect($types,['tour-rates','tours-and-activities','properties']))return;
 $excluded=(array)$query->get('post__not_in');
 if (!$excluded)return;
 $remaining=array_values(array_filter(array_map('absint',$excluded),
   static fn($id)=>!lab_qa_public_tour_view_only_allowed($id)));
 if (count($remaining)!==count($excluded))$query->set('post__not_in',$remaining);
},24);

/** A verified signed request grants view-only context for this one QA Tour.
 * It never grants account privileges or overrides booking/supplier policies.
 */
add_action('shared_product/presentation/signed_context_accepted',
 static function($tour,$provider,$listing){
  if((int)$tour!==66996)return;
  $valid=($provider==='jet-engine/tour_rates'&&(int)$listing===46193)
     ||($provider==='jet-engine/tour_rates_accordion'&&(int)$listing===56197);
  if($valid)$GLOBALS['lab_qa_verified_signed_filter_tour']=66996;
 },8,3);

/**
 * QA-only signed JSF read scope: without this hook, passworded fixtures
 * remain undiscoverable. Signer/provider and same-origin Referer have
 * already been verified by the neutral Presentation adapter.
 */
add_action('shared_product/presentation/component_signed_context_accepted',
 static function($tour,$component,$provider,$query_id){
  if((int)$tour!==66996||!wp_doing_ajax()
    ||($_REQUEST['action']??'')!=='jet_smart_filters')return;
  if((string)get_post_meta(66996,'_booking_experience_qa_pricing_fixture',true)
     !=='cart-lab-20261026:tour')return;
  if(!class_exists('Shared_Product_Presentation_Transport_Context_v1'))return;
  $catalog=Shared_Product_Presentation_Transport_Context_v1::categories();
  if(!isset($catalog[$component]))return;
  $binding=Shared_Product_Presentation_Transport_Context_v1::binding($component);
  if(empty($binding['ok'])||$provider!==$binding['provider_id']
    ||(int)$query_id!==(int)$binding['query_id'])return;
  $GLOBALS['lab_qa_verified_signed_filter_tour']=66996;
 },8,4);

/** Only the verified Tour 66996 public view may render QA transport
 * content. Original post_password remains untouched in the database. */
add_filter('post_password_required',static function($required,$post){
 if(!$required||!$post instanceof WP_Post||$post->post_type!=='transportations')return $required;
 return lab_qa_public_tour_view_only_allowed((int)$post->ID)?false:$required;
},20,2);

/**
 * Read-only price label on the approved fixture Tour detail ONLY.
 * This is NOT access to Supplier Quote, a checkout permission, or public
 * entitlement outside the isolated Lab fixture. Never changes product data.
 */
function lab_qa_extra_price_label_allowed(int $extra_id): bool {
 if (!lab_qa_tour_public_view_context()) return false;
 if (!in_array($extra_id,[67094,67098,67102,67106],true)) return false;
 $post=get_post($extra_id);
 if (!$post instanceof WP_Post || $post->post_type!=='tours-and-activities'
   || $post->post_status!=='publish' || $post->post_password!=='') return false;
 if ((string)get_post_meta($extra_id,'tour_mode',true)!=='extra_trip')return false;
 $fixture=[67094=>'cart-lab-20261026:extra-0',
   67098=>'cart-lab-20261026:extra-1',
   67102=>'cart-lab-20261026:extra-2',
   67106=>'cart-lab-20261026:extra-3'];
 if ((string)get_post_meta($extra_id,'_booking_experience_qa_pricing_fixture',true)
   !== $fixture[$extra_id]) return false;
 $linked=array_map('absint',(array)get_post_meta(66996,'tour_optional_extras',true));
 return in_array($extra_id,$linked,true);
}
