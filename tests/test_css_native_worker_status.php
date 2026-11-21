<?php
/** Read-only original WP-Cron status, with no WordPress bootstrap or DB writes. */
error_reporting(E_ALL);
define('ABSPATH','/mock/');
define('WP_CONTENT_DIR','/tmp/css-mock');
$now=time();
$GLOBALS['options']=[
 'mad4b_scp_brand_context_profile_v1'=>[
  'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
  'brand_id'=>'6568e330070a40e685ff36ccb7a726e1'
 ],
 'mad4b_css_wpcron_dispatch_v1'=>[
  'contract'=>'mad4b.css.wpcron.dispatch.v1','site'=>'wayfa','host'=>'wayfatours.com',
  'jobs'=>['public'=>['last_completed'=>$now-110,'pending_id'=>0],
           'families'=>['last_completed'=>$now-5000,'pending_id'=>12]]
 ],
];
function get_option($k,$def=false){return $GLOBALS['options'][$k]??$def;}
function home_url($p=''){return 'https://wayfatours.com'.$p;}
function site_url($p=''){return 'https://wayfatours.com'.$p;}
function wp_parse_url($url,$kind=null){return parse_url($url,$kind);}
function wp_get_environment_type(){return 'production';}
final class MAD4B_CCSS_WPCron_Dispatch_V1 {
 public static function profile() {
  return ['site_id'=>'wayfa','site_host'=>'wayfatours.com',
          'environment'=>'production','tasks'=>['public'=>1800,'families'=>3600]];
 }
}
require_once __DIR__.'/../wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
function verify($val,$msg){if(!$val){fwrite(STDERR,"FAILED $msg\n");exit(3);}echo "PASS $msg\n";}
$c='MAD4B_SCP_Adaptive_Asset_Intelligence';
$a=$c::native_css_jobs();
verify($a['state']==='recent_verified_ack','recognize exact recent native completion');
verify($a['last_verified_task']==='public'&&$a['pending']===1,'keep unrelated family ticket pending');
verify($c::worker_state()==='native_wpcron_recent_verified_ack','native proof without synthetic manifest');
$GLOBALS['options']['mad4b_css_wpcron_dispatch_v1']['jobs']['public']['last_completed']=$now-4000;
$b=$c::native_css_jobs();
verify($b['state']==='no_recent_ack','old native receipt cannot count as current');
verify($c::worker_state()==='not_configured','no stale manifest worker assertion');
$GLOBALS['options']['mad4b_css_wpcron_dispatch_v1']['host']='someoneelse.example';
verify($c::native_css_jobs()['state']==='not_enrolled','cross company status fails closed');
echo "CSS_NATIVE_WORKER_STATUS_CONTRACT_PASS\n";
