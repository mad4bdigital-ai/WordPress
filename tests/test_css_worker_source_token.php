<?php
error_reporting(E_ALL);
define('ABSPATH','/mock/');
define('WP_CONTENT_DIR','/mock/wp-content');
define('WP_CLI',true);
$GLOBALS['options']=[
 'mad4b_scp_brand_context_profile_v1'=>[
    'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
    'brand_id'=>'6568e330070a40e685ff36ccb7a726e1'
 ],
 'mad4b_adaptive_asset_job_queue_v1'=>[]
];
function service_visibility_active($type){return $type==='tours';}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$value,$autoload=null){$GLOBALS['options'][$k]=$value;return true;}
function home_url($p=''){return 'https://wayfatours.com'.$p;}
function site_url($p=''){return 'https://wayfatours.com'.$p;}
function wp_parse_url($u,$k=null){return parse_url($u,$k);}
function wp_get_environment_type(){return 'production';}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
require_once __DIR__.'/../wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
function yes($v,$why){if(!$v){fwrite(STDERR,"FAIL ".$why."\n");exit(3);}echo "PASS ".$why."\n";}
$c='MAD4B_SCP_Adaptive_Asset_Intelligence';
$path='/tours-and-activities/test-source-revision/';
$now=time();
$GLOBALS['options']['mad4b_adaptive_asset_job_queue_v1']=[
 ['id'=>str_repeat('a',64),'reason'=>'wp_content_saved','path'=>$path,
  'updated'=>$now-60,'state'=>'pending','source_token'=>str_repeat('b',32)]
];
$x=$c::worker_source_for_path($path);
yes(($x['ok']??null)===true,'exact job can be selected by original worker');
yes(($x['source_token']??'')===str_repeat('b',32),'explicit exact source token');
yes(($x['legacy_source_token']??null)===false,'new source token marked current');
yes(($c::worker_source_for_path('/wp-admin/')['ok']??null)===false,'unsafe admin denied');
yes(($c::worker_source_for_path('/unknown/')['ok']??null)===false,'no fabricated source job');
$GLOBALS['options']['mad4b_adaptive_asset_job_queue_v1'][0]['source_token']='';
$old=$c::worker_source_for_path($path);
yes(($old['ok']??null)===true&&strlen($old['source_token'])===32,'legacy fallback has bounded token');
yes($old['legacy_source_token']===true,'legacy source marked legacy');
$GLOBALS['options']['mad4b_adaptive_asset_job_queue_v1'][]=[
 'id'=>str_repeat('c',64),'reason'=>'elementor_document_saved','path'=>$path,
 'updated'=>$now-30,'state'=>'pending','source_token'=>str_repeat('d',32)];
yes(($c::worker_source_for_path($path)['error']??'')==='ambiguous_route','ambiguous duplicates fail closed');
$GLOBALS['options']['mad4b_adaptive_asset_job_queue_v1']=[
 ['path'=>'/','reason'=>'wp_content_saved'],
 ['path'=>'/transportations/source-price/','reason'=>'wp_content_saved'],
 ['path'=>'/units/source-room/','reason'=>'wp_content_saved'],
 ['path'=>'/properties/source-hotel/','reason'=>'wp_content_saved'],
 ['path'=>'/tours-and-activities/bookable-route/','reason'=>'wp_content_saved']
];
$triage=$c::source_queue_triage();
yes(($triage['source_only_outside_css_catalog']??-1)===3,'three commerce-only sources not falsely made public CSS');
yes(($triage['home_independent_owner']??-1)===1,'home remains first-fold owned');
yes(($triage['requires_original_catalog_and_css_proof']??-1)===1,'route still requires original catalog proof');
yes($triage['mutation_performed']===false&&$triage['source_tokens_consumed']===false,'read-only classification retains queue and tokens');
yes(count($GLOBALS['options']['mad4b_adaptive_asset_job_queue_v1'])===5,'no queue data removed');
echo "CSS_SOURCE_QUEUE_TRIAGE_5_OF_5_PASS\n";
echo "CSS_WORKER_REVISION_SOURCE_8_OF_8_PASS\n";
