<?php
define('ABSPATH','/isolated/');
define('ICL_SITEPRESS_VERSION','mock');
class WP_Error {
 public function __construct(private string $code,private string $message='',private array $data=[]) {}
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($event,$cb,$priority=10,$args=1){}
function get_current_blog_id(){return 6;}
function get_current_user_id(){return 4;}
function current_user_can($cap){return !empty($GLOBALS['admin']);}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function absint($v){return abs((int)$v);}
function sanitize_key($str){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$str));}
function is_email($email){return (bool)filter_var($email,FILTER_VALIDATE_EMAIL);}
function get_user_by($kind,$id){return $GLOBALS['users'][$id]??null;}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags);}
function apply_filters($hook,$default,...$args){
  if($hook==='wpml_post_language_details')return $GLOBALS['langs'][$args[0]]??null;
  return $default;
}
function wp_get_environment_type(){return 'staging';}
function yes($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL ".$msg."\n");exit(7);}echo "PASS ".$msg."\n";}
class MAD4B_SCP_Readiness_Workflow_Options {
 const GROUPS=['media'=>'Media','origin'=>'Origin','wpml'=>'WPML',
 'pricing'=>'Pricing','destinations'=>'Destinations','content'=>'Content',
 'operations'=>'Operations','seo'=>'SEO','general'=>'General'];
}
class MAD4B_SCP_Readiness_Integrations {const STORE='mad4b_content_readiness_events_v1';}
class MAD4B_SCP_Content_Readiness_Alerts {const STORE='mad4b_content_readiness_issues_v1';}
class MAD4B_SCP_Company_Master_Admin {
 public static function managedStaff($id){return $id===101;}
}
class MAD4B_SCP_Readiness_PreActivation {const HOOK='mad4b/readiness/scope_digest';}
$GLOBALS['options']=[];
$GLOBALS['users']=[
 101=>(object)['user_email'=>'editor@example.invalid','roles'=>['editor']],
 102=>(object)['user_email'=>'foreign@example.invalid','roles'=>['administrator']],
];
$GLOBALS['admin']=true;
$GLOBALS['langs']=[88=>['language_code'=>'fr']];
require dirname(__DIR__,2).'/includes/class-mad4b-scp-readiness-event-automation.php';
$c='MAD4B_SCP_Readiness_Event_Automation';
$d=$c::defaults();
yes($c::disabled() && $d['delivery_enabled']===false && $d['first_cycle']==='quarantine',
  'delivery remains disabled with first-cycle quarantine');
yes(!is_wp_error($c::normalize_policy($d)),'safe default email schema');
$bad=$d;$bad['delivery_enabled']=true;
yes(is_wp_error($c::normalize_policy($bad)),'external email cannot activate from draft');
$bad=$d;$bad['max_messages_per_cycle']=100;
yes(is_wp_error($c::normalize_policy($bad)),'mass email dispatch limit rejected');
$bad=$d;$bad['recipient_user_ids']=[102];
yes(is_wp_error($c::normalize_policy($bad)),'foreign user cannot receive company alerts');
$good=$d;$good['recipient_user_ids']=[101];
yes(!is_wp_error($c::normalize_policy($good)),'enrolled company staff can be proposed');
$bad=$d;$bad['subject']='Hidden {secret}';
yes(is_wp_error($c::normalize_policy($bad)),'unknown subject tokens fail closed');
$bad=$d;$bad['body']='<b>raw html</b>';
yes(is_wp_error($c::normalize_policy($bad)),'html email draft rejected');
$id=hash('sha256','qa-event-source');
$eventId=hash('sha256',$id.'|reopened|1');
$event=['event_id'=>$eventId,'issue_id'=>$id,'event'=>'reopened','group'=>'pricing',
'severity'=>'high','at'=>'2026-10-28T17:00:00+00:00'];
$GLOBALS['options'][MAD4B_SCP_Readiness_Integrations::STORE]=['events'=>[$event]];
$GLOBALS['options'][MAD4B_SCP_Content_Readiness_Alerts::STORE]=[
 $id=>['id'=>$id,'post_type'=>'tour-rates','object_id'=>88,'reopens'=>1,'status'=>'open']
];
$normal=$c::normalize_event($event);
yes($normal['language_code']==='fr' && $normal['language_status']==='wpml_resolved',
  'WPML source language wins over guessed language');
yes($normal['status']==='held' && preg_match('/^[a-f0-9]{64}$/',$normal['batch_key']),
  'source event maps to stable held digest cohort');
$bad=$event;$bad['event_id']=str_repeat('a',64);
yes($c::normalize_event($bad)===null,'unverified event cannot enter the outbox');
$site=$c::event_language(['post_type'=>'site','object_id'=>0]);
yes($site['code']==='site' && $site['status']==='site_wide',
    'site-wide issues do not become missing WPML translations');
$und=$c::event_language(['post_type'=>'tours-and-activities','object_id'=>133]);
yes($und['code']==='und' && $und['status']==='needs_source_review',
    'missing WPML language is flagged for review');
$send=$c::rest_dispatch();
yes(is_wp_error($send)&&$send->get_error_data()['status']===423,'sending returns explicit lock');
echo "READINESS_EVENT_AUTOMATION_CONTRACT: PASS\n";
