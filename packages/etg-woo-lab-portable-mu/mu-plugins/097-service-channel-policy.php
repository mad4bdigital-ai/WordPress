<?php
/**
 * Neutral Product Service Channel Policy for the Local Commerce Lab.
 * One JetEngine Options Page `service-activation` remains the source of truth.
 * Modes: standalone_and_tour / tour_only / disabled. For existing settings,
 * `active_services` retains its historical public+tour behavior until a mode
 * has explicitly been saved. Unit and Rate CPTs inherit their owner service.
 */
if(!defined('ABSPATH'))return;
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
 // Keep the WordPress and Elementor editing experience intact even when the
 // public service is unavailable. This never relaxes checkout/quote policy.
 $editor=current_user_can('edit_posts')&&is_admin();
 if($editor)return true;
 if(service_channel_allowed($key,'standalone'))return true;
 if(!service_channel_allowed($key,'tour_component'))return false;
 // Main Tour or a native signed AJAX render, never a visitor-supplied Tour hint.
 return function_exists('shared_product_verified_presentation_tour_id')
  && shared_product_verified_presentation_tour_id()>0;
}
