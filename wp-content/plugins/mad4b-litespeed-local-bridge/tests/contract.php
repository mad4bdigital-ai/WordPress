<?php
error_reporting(E_ALL);
define('ABSPATH','/tmp/mad4b-provider-test/');
define('WP_CONTENT_DIR','/tmp/mad4b-provider-test/wp-content');
define('LSCWP_V','7.9.2');
$GLOBALS['env']='staging';$GLOBALS['host']='https://tours.dev.mad4b.com';$GLOBALS['opts']=[
 'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'],
 'litespeed.conf.optm-css_comb'=>'1','litespeed.conf.optm-css_async'=>'0',
 'litespeed.conf.optm-ucss'=>'0','litespeed.conf.guest_optm'=>'0'];
function add_action(){return true;}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_parse_url($s,$component){return parse_url($s,$component);}
function home_url(){return $GLOBALS['host'];}
function get_option($key,$default=false){return $GLOBALS['opts'][$key]??$default;}
function apply_filters($hook,$value){return $hook==='wpml_current_language'?'en':$value;}
function did_action($event){return 0;}
function is_admin(){return false;}
function is_user_logged_in(){return false;}
function wp_doing_ajax(){return false;}
final class MAD4B_LSC_Local_Provider_V1 {
 public static function local_css_eligible():bool{return (bool)($GLOBALS['css_authority_eligible']??false);}
}
require dirname(__DIR__).'/mad4b-litespeed-local-bridge.php';
function ok($v,$msg){if(!$v){fwrite(STDERR,"FAIL $msg\n");exit(3);}echo "PASS $msg\n";}
$c='MAD4B_LiteSpeed_Local_Bridge';
ok($c::active(),'Staging + origin + exact LS version');
ok($c::device('Mozilla/5.0 (Android 15) Mobile')==='mobile','Mobile user agent classification');
ok($c::device('Mozilla/5.0 (Macintosh; Intel Mac OS X)')==='desktop','Desktop user agent classification');
ok(!$c::local_css_compatible(),'CSS runtime denied unless MU336 has approved it');
$GLOBALS['css_authority_eligible']=true;
ok($c::local_css_compatible(),'CSS decisions delegate exclusively to MU336 authority');
$GLOBALS['css_authority_eligible']=false;
ok($c::vpi_queue(true,'https://tours.dev.mad4b.com/')===true,'No local VPI certificate means preserve LiteSpeed QUIC queue');
ok($c::vpi_queue(false,'https://tours.dev.mad4b.com/')===false,'Existing provider cancellation remains authoritative');
ok($c::lazy_exclusions(['/keep-original'])===['/keep-original'],'Never overwrite LiteSpeed native lazy load exclusions');
$GLOBALS['env']='production';ok(!$c::active(),'Production fail-closed');
$GLOBALS['env']='staging';$GLOBALS['host']='https://other.example';ok(!$c::active(),'Unenrolled company fail-closed');
echo "mad4b.litespeed.provider-bridge.v1 PASS\n";
