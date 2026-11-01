<?php
/** Native contract: no WordPress bootstrap, safe and repeatable. */
error_reporting(E_ALL);
define('ABSPATH','/tmp/');
define('WP_CONTENT_DIR','/tmp/wp-content');
define('WP_CLI',true);
$GLOBALS['opts']=['mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a']];
$GLOBALS['events']=[];$GLOBALS['env']='staging';$GLOBALS['site']='https://tours.dev.mad4b.com';
function wp_get_environment_type(){return $GLOBALS['env'];}
function home_url(){return $GLOBALS['site'];}
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
$GLOBALS['env']='production';check(!$c::scope(),'production fail closed');
check(empty($c::mark_dirty('test','/')['queued']),'production cannot enqueue');
$GLOBALS['env']='staging';$GLOBALS['site']='https://other.example';check(!$c::scope(),'cross-site fail closed');
echo "mad4b.adaptive.asset.notification.contract.v1 PASS\n";
