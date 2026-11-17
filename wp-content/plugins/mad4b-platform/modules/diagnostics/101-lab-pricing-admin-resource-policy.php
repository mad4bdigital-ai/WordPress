<?php
/** Keep ETG Lab pricing admin independent of unreachable third-party resources. */
if (!defined('ABSPATH') || wp_get_environment_type()!=='local') return;
add_action('admin_init',static function(){
 if(!in_array(($_GET['page']??''),['etg-dynamic-pricing-controls','shared-lab-booking-preview','shared-lab-booking-experience','booking-experience','booking-experience-preview','booking-experience-market-pricing','booking-experience-pricing-presets','wc-settings'],true))return;
 if(($_GET['page']??'')==='wc-settings' && ($_GET['tab']??'')!=='booking_experience')return;
 if(!current_user_can('manage_options'))return;
 header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'",false);
},PHP_INT_MAX);
