<?php
define('ABSPATH','/mock/');define('WP_ENVIRONMENT_TYPE','staging');
function wp_get_environment_type(){return $GLOBALS['environment']??'staging';}
function get_current_blog_id(){return 2;}
function home_url(){return 'https://staging.test.example';}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
class MAD4B_Runtime_Admission{public static function environment_status(){return ['verified'=>($GLOBALS['effective']??'staging')==='staging'];}}
class MAD4B_SCP_Site_Profile{public static function site_uuid(){return $GLOBALS['site_uuid']??'company-a';}}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
class MAD4B_SCP_Scheduler_Backlog{public static function status($a){return ['count'=>2];}}
function expect($yes,$msg){if(!$yes){fwrite(STDERR,"FAILED $msg\n");exit(9);}echo "PASS $msg\n";}
require __DIR__.'/governed-cron-plan.php';
$GLOBALS['options']=['mad4b_scheduler_hook_registry_v1'=>['fluentcrm_send_queue'=>['state'=>'verified','category'=>'messaging_or_form_side_effects']]];
$c='MAD4B_Governed_Cron_Preview';
$events=[100=>['fluentcrm_send_queue'=>[['args'=>['PII'=>'secret']]],'action_scheduler_run_queue'=>[['args'=>[]]]],200=>['wp_maintenance'=>[['args'=>[]]]]];
$plan=$c::plan($events,150);
expect($plan['status']==='preview_only' && !$plan['execute_enabled'],'preview never authorizes');
expect($plan['total_due']===2&&$plan['visible_items']===2,'due count excludes future');
expect($plan['due_categories']['messaging_or_form_side_effects']===1,'registry-declared message hook classified');
expect($c::classify('unknown_company_hook')==='unreviewed_unregistered','no company prefix implicit trust');
$GLOBALS['options']['mad4b_scheduler_hook_registry_v1']=[];
expect($c::classify('fluentcrm_send_queue')==='unreviewed_unregistered','missing registry quarantines source hook');
expect($plan['due_categories']['action_scheduler_side_effects']===1,'action scheduler classified');
expect($plan['central_backlog_count']===2,'existing native backlog observed');
$GLOBALS['site_uuid']='company-b';
$alternate=$c::plan($events,150);
expect($alternate['items'][0]['event_fingerprint']!==$plan['items'][0]['event_fingerprint'],'event fingerprints separated by registered site identity');
$GLOBALS['site_uuid']='company-a';
expect(!str_contains(json_encode($plan),'secret'),'no customer argument data in preview');
expect(!empty($plan['items'][0]['event_fingerprint'])&&!$plan['items'][0]['execution_authorized'],'fingerprinted and held');
$GLOBALS['effective']='production';$denied=$c::plan($events,150);
expect($denied['status']==='blocked','environment profile mismatch fails closed');
echo "CRON_PLAN_CONTRACT: PASS\n";
