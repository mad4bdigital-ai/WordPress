<?php
/** Local compatibility shim: the native Accommodation Booking Core is the
 * only financial quote implementation, including optional inventory policy. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local')return;
function lab_accommodation_shadow_quote($input){
 if(!function_exists('abk_accommodation_shadow_quote'))
  return new WP_Error('stay_native_booking_core_unavailable');
 return abk_accommodation_shadow_quote($input);
}
