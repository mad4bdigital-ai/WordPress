<?php
/** Original MAD4B readiness draft gates: fake WordPress only, no network. */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
if(!function_exists('str_starts_with')){function str_starts_with($haystack,$needle){return strpos($haystack,$needle)===0;}}
class WP_Error {
 private $code;
 public function __construct($code,$message='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['caps'][$cap]??false;}
function get_current_user_id(){return 17;}
function get_current_blog_id(){return 1;}
function add_action($hook,$handler,$priority=10,$args=1){$GLOBALS['actions'][$hook]=$handler;}
function register_rest_route($ns,$route,$args){$GLOBALS['rest_routes'][$ns.$route]=$args;}
function rest_ensure_response($v){return ['response'=>$v,'read_only'=>true];}
function get_option($name,$default=null){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['writes'][]=$name;$GLOBALS['options'][$name]=$value;return true;}
function get_role($name){return in_array($name,['editor','shop_manager','operator','company_translation_manager'],true)?(object)['name'=>$name]:null;}
function get_users($args=[]){return $GLOBALS['users'];}
function get_user_by($by,$id){return $GLOBALS['people'][$id]??false;}
function user_can($user,$cap){return $GLOBALS['user_can']===true;}
function wp_json_encode($v,$options=0){return json_encode($v,$options);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function absint($v){return abs((int)$v);}
function is_email($v){return is_string($v)&&strpos($v,'@')!==false;}
function contract_check($result,$reason){
 if(!$result){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class TestRequest {
 private $body;
 function __construct($body){$this->body=$body;}
 function get_json_params(){return $this->body;}
}
class OriginalFakeDb {
 public $prefix='wp_';public $queries=0;
 function prepare($query,...$args){return $query;}
 function get_var($query){return 'wp_mad4b_readiness_event_outbox';}
}
$wpdb=new OriginalFakeDb();
class MAD4B_SCP_Site_Profile {
 static $status;
 static function status(){return self::$status;}
}
class MAD4B_SCP_Company_Master_Admin {
 static function managedStaff($id){return $id===17;}
}
class MAD4B_SCP_Readiness_Workflow_Options {
 const GROUPS=['media'=>'Media','origin'=>'Origin','wpml'=>'Translation',
  'pricing'=>'Pricing','destinations'=>'Destinations','content'=>'Content',
  'operations'=>'Operations','seo'=>'SEO','general'=>'General'];
 static function settings(){return ['board_id'=>4,'stage_id'=>7];}
 static function validate_board_stage($board,$stage){return false;}
}
class MAD4B_SCP_Readiness_Scopes {
 const TASK_SOURCE='mad4b-readiness-scope';
 static function build(){return [
  'scope-one'=>['scope_id'=>str_repeat('a',64),'batch_id'=>str_repeat('b',64),
   'group'=>'media','severity'=>'high','language'=>'en','content_type'=>'post',
   'total'=>2,'assignee_id'=>17,'approval'=>'unreviewed','task_id'=>0]
 ];}
 static function batches(){return [
  'batch-one'=>['batch_id'=>str_repeat('b',64),'group'=>'media','severity'=>'high',
   'language'=>'en','content_type'=>'post','issue_count'=>2,'work_packet_count'=>1]
 ];}
}
class MAD4B_SCP_Readiness_Integrations {
 const STORE='readiness_test_journal';
 static function bitflows_flow_status($hook){return ['installed'=>false,'configured'=>false];}
}
class MAD4B_SCP_Content_Readiness_Alerts {
 const STORE='readiness_test_sources';
 static function summary(){return [];}
}
$GLOBALS['options']=[];$GLOBALS['writes']=[];
$GLOBALS['caps']=['manage_options'=>true,'mad4b_manage_company_operations'=>true];
$GLOBALS['user_can']=true;
$GLOBALS['people']=[17=>(object)['ID'=>17,'display_name'=>'Company Editor',
 'roles'=>['editor'],'user_email'=>'editor@example.invalid'],
 33=>(object)['ID'=>33,'display_name'=>'Foreign Staff',
 'roles'=>['editor'],'user_email'=>'foreign@example.invalid']];
$GLOBALS['users']=array_values($GLOBALS['people']);
MAD4B_SCP_Site_Profile::$status=['authority_ready'=>true];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-preactivation.php';
$pre='MAD4B_SCP_Readiness_PreActivation';
$team='MAD4B_SCP_Readiness_Team_Preparation';
$events='MAD4B_SCP_Readiness_Event_Automation';
contract_check($pre::execution_locked()===true &&$events::disabled()===true,
 'Original email/task execution lock is not controlled by a draft setting');
$default=$pre::defaults();
contract_check($pre::normalize($default)===$default,
 'Original preview policy has an exact normalized roundtrip');
$danger=$default;$danger['providers']['fluent_smtp']='send_now';
contract_check(is_wp_error($pre::normalize($danger)),
 'Preview policy cannot enable SMTP delivery through WordPress draft PATCH');
$danger=$default;$danger['tasks']['require_human_approval']=false;
contract_check(is_wp_error($pre::normalize($danger)),
 'Task previews cannot remove owner approval gate');
$danger=$default;$danger['digest']['first_cycle']='dispatch';
contract_check(is_wp_error($pre::normalize($danger)),
 'First notification cycle remains quarantined');
$danger=$default;$danger['tasks']['template_title']='<script>send()</script>';
contract_check(is_wp_error($pre::normalize($danger)),
 'Template content cannot inject HTML or executable actions');
$draft=$team::defaults();
contract_check($team::normalize($draft)===$draft,
 'Original team assignments default to drafts, not real users or tasks');
$invalid=$draft;$invalid['assignments']['media']['proposed_user_id']=33;
contract_check(is_wp_error($team::normalize($invalid)),
 'Non-company WordPress user cannot be assigned via preactivation draft');
$valid=$draft;$valid['assignments']['media']['proposed_user_id']=17;
contract_check(!is_wp_error($team::normalize($valid)),
 'Original enrolled company editor can be proposed without any user mutation');
$candidates=$team::candidates('editor');
contract_check(count($candidates)===1&&$candidates[0]['user_id']===17,
 'Team preparation only exposes authorized enrolled staff candidates');
$plan=$team::plan();
contract_check($plan['mode']==='draft_only'
 &&$plan['actions_performed']['users_modified']===0
 &&$plan['actions_performed']['tasks_created']===0,
 'Preview of proposed company staff does not change WordPress privileges');
$blueprints=$team::blueprints();
contract_check($blueprints['all_providers_execution_disabled']===true
 &&$blueprints['fluent_boards']['native_task_created']===false
 &&$blueprints['bitflows']['flow_created']===false
 &&$blueprints['smtp']['test_message_sent']===false,
 'FluentBoards, Bitflows, SMTP are original previews, never execution');
$stale=$team::rest_patch(new TestRequest(['if_match'=>str_repeat('a',64),'settings'=>$valid]));
contract_check(is_wp_error($stale)&&!$GLOBALS['writes'],
 'Stale team draft CAS cannot write even an internal option');
$saved=$team::rest_patch(new TestRequest(['if_match'=>$team::revision(),'settings'=>$valid]));
contract_check(!is_wp_error($saved) &&$GLOBALS['options'][$team::OPTION]===$valid
 &&count($GLOBALS['writes'])===2,
 'Matching CAS only stores original team draft plus audit history');
$pre::routes();$team::routes();$events::routes();
contract_check(isset($GLOBALS['rest_routes']['mad4b-content-readiness/v1/admin/preactivation'])
 &&isset($GLOBALS['rest_routes']['mad4b-content-readiness/v1/admin/preactivation/team-plan'])
 &&isset($GLOBALS['rest_routes']['mad4b-content-readiness/v1/admin/preactivation/email-dispatch']),
 'First-party Readiness REST uses original gated owner routes');
$preview=$pre::report();
contract_check($preview['execution_mode']==='disabled'
 &&$preview['gates']['scope_approved']===false
 &&$preview['gates']['provider_tasks_enabled']===false
 &&$preview['gates']['outbound_notifications_enabled']===false
 &&$preview['side_effects']['outbound_messages']===0,
 'Readiness report cannot promote draft policy to active production notification');
$sample=$pre::mock_task(current(MAD4B_SCP_Readiness_Scopes::build()));
contract_check($sample['preview_only']===true &&$sample['would_create']===false,
 'Original provider task mock never writes a Fluent Boards task');
$digest=$pre::mock_digest(current(MAD4B_SCP_Readiness_Scopes::batches()));
contract_check($digest['preview_only']===true
 &&$digest['would_invoke_hook']===false
 &&$digest['would_send_message']===false,
 'Original Bitflows digest preview cannot invoke an event hook or outbound email');
$before=count($GLOBALS['writes']);
$stale=$pre::rest_patch(new TestRequest(['if_match'=>'stale','settings'=>$default]));
contract_check(is_wp_error($stale)&&count($GLOBALS['writes'])===$before,
 'Stale central PreActivation policy cannot update even a draft');
$changed=$default;$changed['scope']['min_severity']='high';
$applied=$pre::rest_patch(new TestRequest([
 'if_match'=>$pre::revision(),'settings'=>$changed]));
contract_check(!is_wp_error($applied)
 &&$GLOBALS['options'][$pre::OPTION]===$changed
 &&$pre::execution_locked()===true,
 'Approved CAS can update local draft text, never unlock provider execution');
$email=$events::defaults();
contract_check($events::normalize_policy($email)===$email,
 'Readiness email settings are only a delivery-disabled draft');
$unsafe=$email;$unsafe['delivery_enabled']=true;
contract_check(is_wp_error($events::normalize_policy($unsafe)),
 'No draft can enable outbound email execution');
$unsafe=$email;$unsafe['recipient_user_ids']=[33];
contract_check(is_wp_error($events::normalize_policy($unsafe)),
 'Foreign recipient cannot enter original company policy');
$unsafe=$email;$unsafe['recipient_user_ids']=[17];
contract_check(!is_wp_error($events::normalize_policy($unsafe)),
 'Enrolled original staff email can be proposed without sending');
contract_check(is_wp_error($events::rest_dispatch()),
 'Original REST POST dispatch remains permanently locked');
MAD4B_SCP_Site_Profile::$status=new WP_Error('original_site_status_unavailable');
contract_check(is_wp_error($events::schema_prepare()),
 'Unknown site authority must deny event schema preparation without PHP crash');
MAD4B_SCP_Site_Profile::$status=['authority_ready'=>true];
$existing=$events::schema_prepare();
contract_check(is_array($existing)&&$existing['status']==='existing',
 'Already installed source event outbox returns read-only schema status');
$event_stale=$events::rest_save_policy(new TestRequest(['if_match'=>'bad','policy'=>$email]));
contract_check(is_wp_error($event_stale),
 'Stale email policy cannot write original WordPress options');
$email_revision=$events::revision();
$new_email=$email;$new_email['subject']='[{severity}] {group} — {event_count} changes';
$email_saved=$events::rest_save_policy(new TestRequest([
 'if_match'=>$email_revision,'policy'=>$new_email]));
contract_check(!is_wp_error($email_saved)
 &&$GLOBALS['options'][$events::STORE]===$new_email
 &&$events::disabled()===true,
 'Original team email draft CAS persists only non-sending policy');
$never=$events::capture([]);
contract_check($never['inserted']===0 &&$never['skipped']===0,
 'No readiness events produce no external emails or tasks');
echo 'PASS: READINESS_DRAFT_ONLY '.($GLOBALS['cases']??0).
 ' original team, provider previews, schema and email locks'.PHP_EOL;
