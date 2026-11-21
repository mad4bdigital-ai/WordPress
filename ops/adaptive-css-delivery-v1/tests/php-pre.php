<?php
define('ABSPATH','/var/www/html/');
$_SERVER['HTTP_HOST']='tours.dev.mad4b.com';
function get_option($key,$default=null) {return match($key){
  'home'=>'https://tours.dev.mad4b.com',
  'mad4b_css_ai_epoch_v1'=>14,
  'mad4b_ccss_listing_generation_v1'=>($GLOBALS['genListing']??0),
  'mad4b_ccss_global_generation_v1'=>($GLOBALS['genGlobal']??0),
  'mad4b_css_rum_v1'=>['mode'=>'observe','sample_bps'=>100],
  default=>$default};}
function wp_get_environment_type(){return 'staging';}
function wp_parse_url($u,$part){return parse_url($u,$part);}
function wp_salt($key){return 'fixture-strong-signed-gate-not-live-secret';}
class MAD4B_CCSS_WPCron_Dispatch_V1 {
  public static function profile() {return ['site_id'=>'source',
     'site_host'=>'tours.dev.mad4b.com','environment'=>'staging',
     'tasks'=>['public'=>300]];}
}
function add_action(...$args){}
?>
