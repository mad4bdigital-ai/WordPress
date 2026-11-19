<?php
/**
 * Neutral Product Service Channel Policy for the Local Commerce Lab.
 * One JetEngine Options Page `service-activation` remains the source of truth.
 * Modes: standalone_and_tour / tour_only / disabled. For existing settings,
 * `active_services` retains its historical public+tour behavior until a mode
 * has explicitly been saved. Unit and Rate CPTs inherit their owner service.
 */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function service_channel_owner($key){
 $types=[
  'tours'=>'tours','tours-and-activities'=>'tours','tour-rates'=>'tours',
  'properties'=>'properties','units'=>'properties','property-rates'=>'properties',
  'accommodation'=>'properties',
  'transportations'=>'transportations','transport-rates'=>'transportations',
  'transportation'=>'transportations',
  'train_line'=>'transportations','stations'=>'transportations',
  'shared-vehicle-lines'=>'transportations',
  'visas'=>'visas','travel-visa'=>'visas'
 ];
 return is_string($key)?($types[$key]??null):null;
}
function service_channel_mode($key){
 $owner=service_channel_owner($key);
 if(!$owner)return 'disabled';
 $settings=get_option('service-activation',[]);
 if(!is_array($settings))$settings=[];
 // Native JetEngine select fields are the management UI; older map is accepted.
 $direct=$settings[$owner.'_channel_mode']??null;
 if($direct!==null&&$direct!==''){
  return is_string($direct)&&in_array($direct,['standalone_and_tour','tour_only','disabled'],true)
    ?$direct:'disabled';
 }
 $modes=$settings['service_channel_modes']??[];
 if(is_array($modes)&&array_key_exists($owner,$modes)&&$modes[$owner]!==''){
  return is_string($modes[$owner])&&in_array($modes[$owner],['standalone_and_tour','tour_only','disabled'],true)
    ?$modes[$owner]:'disabled';
 }
 // Backward-compatible: absent mode never silently activates a disabled service.
 return in_array($owner,(array)($settings['active_services']??[]),true)?'standalone_and_tour':'disabled';
}
function service_channel_allowed($key,$channel){
 if(!in_array($channel,['standalone','tour_component','destination_discovery'],true))return false;
 $mode=service_channel_mode($key);
 // Discovery is read-only inquiry: it never permits standalone quotes or checkout.
 return $mode==='standalone_and_tour'||($mode==='tour_only'&&in_array($channel,['tour_component','destination_discovery'],true));
}
/** Server-verified rendering context; do not accept a GET param claiming Tour context. */
function service_visible_in_current_page($key){
 // An exact linked transport rendered in the shared, guarded Tour Quick View
 // is a Tour component, not an independently activated standalone service.
 if($key==='transportations'
   &&class_exists('MAD4B_Extra_Quick_Preview_V1',false)
   &&MAD4B_Extra_Quick_Preview_V1::scoped_transport_query_allowed())return true;
 // Authenticated editor Draft Preview: read-only Tour-linked transport display.
 // The transport channel must be enabled for Tour components. Guest discovery,
 // standalone pages, price/checkout and the activation policy are unchanged.
 if($key==='transportations' && !is_admin() && !wp_doing_ajax()
   && (!defined('REST_REQUEST') || !REST_REQUEST)
   && service_channel_allowed('transportations','tour_component')){
   global $wp_the_query;
   if($wp_the_query instanceof WP_Query
     && $wp_the_query->is_preview()
     && $wp_the_query->is_singular('tours-and-activities')){
     $tour=absint($wp_the_query->get_queried_object_id());
     if($tour && get_post_status($tour)==='draft'
       && is_user_logged_in() && current_user_can('edit_post',$tour)
       && (string)get_post_meta($tour,'tour_mode',true)==='cruise_tour'){
       foreach([
         'tour_vehicles_transport_status'=>'vehicles_transport_services',
         'tour_train_transport_status'=>'train_transport_services',
         'tour_air_transport_status'=>'air_transport_services'] as $flag=>$field){
         if((string)get_post_meta($tour,$flag,true)!=='1')continue;
         $ids=get_post_meta($tour,$field,true);
         if(is_string($ids))$ids=maybe_unserialize($ids);
         if(is_string($ids))$ids=explode(',',$ids);
         if(!is_array($ids)||count($ids)>80)continue;
         foreach($ids as $id)if(is_scalar($id)&&ctype_digit(trim((string)$id))
           &&(int)$id>0 &&get_post_type((int)$id)==='transportations')return true;
       }
     }
   }
 }
 // Keep the WordPress and Elementor editing experience intact even when the
 // public service is unavailable. This never relaxes checkout/quote policy.
 $editor=current_user_can('edit_posts')&&is_admin();
 if($editor)return true;
 if(service_channel_allowed($key,'standalone'))return true;
 if(!service_channel_allowed($key,'tour_component'))return false;
 // The signed AJAX render and the published main Tour share one authority.
 return function_exists('shared_product_verified_presentation_tour_id') && shared_product_verified_presentation_tour_id()>0;
}
