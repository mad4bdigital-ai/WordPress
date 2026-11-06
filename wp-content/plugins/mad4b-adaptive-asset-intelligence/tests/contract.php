<?php
/** Native contract: no WordPress bootstrap, safe and repeatable. */
error_reporting(E_ALL);
define('ABSPATH','/tmp/');
define('WP_CONTENT_DIR','/tmp/wp-content');
define('WP_CLI',true);
$GLOBALS['opts']=['mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a','brand_id'=>'4f422f41f1564dc2a687326ac7c21634']];
$GLOBALS['events']=[];$GLOBALS['env']='staging';$GLOBALS['site']='https://tours.dev.mad4b.com';
function wp_get_environment_type(){return $GLOBALS['env'];}
function home_url(){return $GLOBALS['site'];}
function site_url(){return $GLOBALS['siteurl']??$GLOBALS['site'];}
function wp_parse_url($url,$kind=null){return parse_url($url,$kind);}
function get_option($key,$default=false){return $GLOBALS['opts'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['opts'][$key]=$value;return true;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
function sanitize_text_field($s){return strip_tags((string)$s);}
function do_action($event,$data=null){$GLOBALS['events'][]=[$event,$data];}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
function check($ok,$label){if(!$ok){fwrite(STDERR,'FAIL '.$label."\n");exit(3);}echo 'PASS '.$label."\n";}
$c='MAD4B_SCP_Adaptive_Asset_Intelligence';
check($c::scope(),'exact staging scope');
$a=$c::mark_dirty('elementor_document_saved','/tours-and-activities/');
check(!empty($a['queued'])&&count($c::queue())===1,'enqueue a changed published route');
check(get_option($c::EPOCH,1)===2,'invalidation epoch increments once');
$b=$c::mark_dirty('elementor_document_saved','/tours-and-activities/');
check(!empty($b['deduplicated'])&&get_option($c::EPOCH,1)===2,'dedup repeated authoring event');
check(count($c::notification_feed())===1,'first notification stored');
$ack=$c::acknowledge_worker('/tours-and-activities/','hold','high CLS');
check(!empty($ack['ok'])&&count($c::queue())===0,'worker acknowledgment clears the exact job');
check(count($GLOBALS['events'])===2,'both operational notifications dispatched to the shared event hook');
check(($GLOBALS['events'][1][0]??'')==='mad4b/notifications/recorded'
 && ($GLOBALS['events'][1][1]['external_delivery']??null)===false,'shared notification contract, no unsolicited external sends');
check(count($c::notification_feed())===2,'notification inbox persists hold');
$c::acknowledge_worker('/tours-and-activities/','hold','high CLS');
check(count($c::notification_feed())===2,'duplicate state deduped');
$bad=$c::acknowledge_worker('/wp-admin/','certified','unsafe');
check(empty($bad['ok']),'unsafe route rejected');
$source_uuid=$c::uuid();
$GLOBALS['env']='production';
check($c::scope(),'production works for the exact registered installed company');
check($c::environment()==='production','runtime environment readback is dynamic');
$source_db=$GLOBALS['opts'];
$GLOBALS['opts']=['mad4b_scp_brand_context_profile_v1'=>[
 'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
 'brand_id'=>'6568e330070a40e685ff36ccb7a726e1']];
$GLOBALS['site']='https://wayfatours.com';
$GLOBALS['siteurl']='https://wayfatours.com';
check($c::scope() && $c::host()==='wayfatours.com','independent Wayfa production scope');
check($c::uuid()!==$source_uuid,'different tenants retain distinct UUIDs');
check($c::worker_state()==='not_configured','company without native worker does not pretend a worker is active');
$GLOBALS['opts']['mad4b_adaptive_asset_worker_heartbeat_v1']=[
 'site_uuid'=>$c::uuid(),'site_host'=>$c::host(),'status'=>'active','last_run'=>time()];
check($c::worker_state()==='vps_local_chromium','correct fresh company worker heartbeat is accepted');
$GLOBALS['opts']['mad4b_adaptive_asset_worker_heartbeat_v1']['site_uuid']=$source_uuid;
check($c::worker_state()==='not_configured','cross-tenant heartbeat cannot activate worker');
$GLOBALS['opts']['mad4b_adaptive_asset_worker_heartbeat_v1']['site_uuid']=$c::uuid();
$GLOBALS['opts']['mad4b_adaptive_asset_worker_heartbeat_v1']['last_run']=time()-4000;
check($c::worker_state()==='not_configured','expired heartbeat cannot activate worker');
unset($GLOBALS['opts']['mad4b_adaptive_asset_worker_heartbeat_v1']);
check(count($c::queue())===0&&count($c::notification_feed())===0,'new company starts with its own clean queue and inbox');
$wayfa=$c::mark_dirty('initial_scan','/tours-and-activities/');
check(!empty($wayfa['queued'])&&count($c::queue())===1,'Wayfa can queue independently in production');
check(($c::notification_feed()[0]['site_uuid']??'')===$c::uuid(),'Wayfa notification belongs to Wayfa identity');
check(($GLOBALS['events'][count($GLOBALS['events'])-1][1]['environment']??'')==='production','production event environment is accurate');
$GLOBALS['site']='https://other.example';
check(!$c::scope(),'mismatched home/site origins fail closed');
$GLOBALS['site']='https://wayfatours.com';
$GLOBALS['env']='local';check(!$c::scope(),'unrecognized environment cannot enroll');
$GLOBALS['env']='production';
$GLOBALS['opts']['mad4b_scp_brand_context_profile_v1']['site_uuid']=$source_uuid;
check($c::scope(),'identity is resolved from registered site rather than a global constant');
$GLOBALS['opts']=$source_db;
$GLOBALS['env']='staging';
$GLOBALS['site']='https://tours.dev.mad4b.com';
$GLOBALS['siteurl']='https://tours.dev.mad4b.com';
check($c::scope(),'source scope remains operational after independent company test');
echo "mad4b.adaptive.asset.notification.contract.v2 PASS\n";
