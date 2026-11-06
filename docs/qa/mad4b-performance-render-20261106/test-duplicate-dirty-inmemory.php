<?php
/** Isolated in-memory WordPress stub. Does not access the site's DB. */
define('ABSPATH','/qa/');
define('WP_CONTENT_DIR','/qa/wp-content');
$GLOBALS['options_qa']=[
 'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'a1111111-1111-4111-a111-111111111111','brand_id'=>str_repeat('b',32)],
 'mad4b_css_ai_epoch_v1'=>5,
 'mad4b_adaptive_asset_job_queue_v1'=>[],
 'mad4b_adaptive_asset_notification_feed_v1'=>[],
 'mad4b_adaptive_presentation_discovery_v1'=>[
   'contract'=>'mad4b.presentation-discovery.v1','site_host'=>'tours.dev.mad4b.com',
   'site_uuid'=>'a1111111-1111-4111-a111-111111111111',
   'scanned_at'=>time()+10,'state'=>'observed','truncated'=>false,
 ],
 'mad4b_adaptive_presentation_scan_needed_v1'=>0,
];
$GLOBALS['scheduled_qa']=[];
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://tours.dev.mad4b.com'.$path;}
function site_url($path='/'){return home_url($path);}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function get_option($key,$fallback=false){return $GLOBALS['options_qa'][$key]??$fallback;}
function update_option($key,$value,$autoload=null){$GLOBALS['options_qa'][$key]=$value;return true;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
function sanitize_text_field($s){return (string)$s;}
function do_action($hook,$arg=null){}
function wp_next_scheduled($hook){return $GLOBALS['scheduled_qa'][$hook]??false;}
function wp_schedule_single_event($when,$hook){$GLOBALS['scheduled_qa'][$hook]=$when;return true;}
require '/var/www/html/wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
require '/var/www/html/wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-presentation-discovery.php';
$first=MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty('qa_duplicate_dirty','/qa-only/');
$dirty1=get_option('mad4b_adaptive_presentation_scan_needed_v1',0);
$second=MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty('qa_duplicate_dirty','/qa-only/');
$dirty2=get_option('mad4b_adaptive_presentation_scan_needed_v1',0);
$status=MAD4B_SCP_Adaptive_Presentation_Discovery::status();
$queue=MAD4B_SCP_Adaptive_Asset_Intelligence::queue();
$result=[
 'contract'=>'mad4b.css-deduplicated-dirty-inmemory.qa.v1',
 'first_queued'=>$first['queued']??null,'first_deduplicated'=>$first['deduplicated']??null,
 'first_revision'=>$first['revision']??null,
 'second_queued'=>$second['queued']??null,'second_deduplicated'=>$second['deduplicated']??null,
 'second_revision'=>$second['revision']??null,
 'queue_count'=>count($queue),
 'dirty_monotonic'=>$dirty2>$dirty1,
 'status_even_if_scanned_at_in_future'=>$status['state']??null,
 'one_scheduled_scan'=>count($GLOBALS['scheduled_qa'])===1,
];
$result['pass']=$result['first_queued'] && !$result['first_deduplicated']
 && $result['second_queued'] && $result['second_deduplicated']
 && $result['first_revision']===6 && $result['second_revision']===7
 && $result['queue_count']===1 && $result['dirty_monotonic']
 && $result['status_even_if_scanned_at_in_future']==='refresh_queued'
 && $result['one_scheduled_scan'];
echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
if (!$result['pass'])exit(2);
