<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code(403); exit; }
/** Synthetic WordPress fixture: NO real users, secrets, database or external requests. */
define('ABSPATH','/fixture');
class WP_Error {private $code;function __construct($code,$message='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_get_environment_type(){return 'local';}
function wp_json_encode($v){return json_encode($v);}
function home_url(){return 'http://localhost:8091';}
function wp_parse_url($u,$part=null){return parse_url($u,$part);}
function add_action($name,$call,$priority=10){}
function current_user_can($n){return $GLOBALS['authorized'];}
function get_current_user_id(){return $GLOBALS['actor'];}
function add_option($name,$val,$unused='',$autoload='no'){if(isset($GLOBALS['options'][$name]))return false;$GLOBALS['options'][$name]=$val;return true;}
function get_option($name,$default=null){return $GLOBALS['options'][$name]??$default;}
function delete_option($name){unset($GLOBALS['options'][$name]);}
function get_post_type_object($name){return $name==='post'?(object)['show_in_rest'=>true,'cap'=>(object)['edit_posts'=>'edit_posts']]:null;}
function get_registered_meta_keys($type,$name){return ['public_field'=>['type'=>'string','show_in_rest'=>true],'api_key'=>['type'=>'string','show_in_rest'=>true],'private_field'=>['type'=>'string','show_in_rest'=>false],'price_code'=>['type'=>'integer','show_in_rest'=>true]];}
function is_protected_meta($key,$type){return false;}
function update_option($name,$val,$unused=false){$GLOBALS['options'][$name]=$val;return true;}
class MAD4B_SCP_Site_Profile{static function status(){return ['origin_match'=>true,'environment_match'=>true,'site_uuid'=>'site-fixture','profile_digest'=>$GLOBALS['profile'],'revision'=>3];}}
class MAD4B_SCP_Policy{static function can_read(){return $GLOBALS['authorized'];}static function can_connect_user($id){return $GLOBALS['authorized'];}}
class MAD4B_SCP_CSO_Scope{static function safe_data($v){if(!is_array($v))return false;foreach($v as $key=>$value){if(preg_match('/api[_-]?key|secret|password|token|credential/i',$key))return false;if(is_array($value)){if(!self::safe_data($value))return false;}elseif(!is_string($value)||strlen($value)>160)return false;}return true;}static function enabled($name){return false;}}
class MAD4B_SCP_CSO01_Read_Foundation{static function can_read(){return true;}static function form_schema($input){return ['descriptor_sha256'=>str_repeat('a',64),'fields'=>[]];}static function form_validate($args){if($args['expected_descriptor_sha256']!==str_repeat('a',64))return new WP_Error('stale_descriptor');if(($args['values']['label']??'')!=='sample')return ['valid'=>false,'issues'=>['invalid_value'],'field_errors'=>['label'=>'invalid']];return ['valid'=>true,'values_echoed'=>false];}}
$GLOBALS['authorized']=true;$GLOBALS['actor']=123;$GLOBALS['profile']='fixture-profile';$GLOBALS['options']=[];
require dirname(__DIR__,2).'/includes/class-mad4b-scp-adaptive-interaction-broker.php';
$count=0;function chk($name,$truth){global $count;$count++;if(!$truth){echo 'FAIL '.$name."\n";exit(1);}echo 'PASS '.$name."\n";}
$reasons=['mad4b_mcp_scoped_credential_required'=>'WAITING_AUTH','mad4b_mcp_credential_resolver_unavailable'=>'WAITING_AUTH','mad4b_provider_unknown'=>'WAITING_PROVIDER','mad4b_approval_exact_input_required'=>'WAITING_APPROVAL','mad4b_cso01_field_sensitive_or_invalid'=>'WAITING_SECURE_HANDOFF','mad4b_cso01_stale_descriptor'=>'WAITING_INPUT'];
foreach($reasons as $reason=>$state){$h=MAD4B_SCP_Adaptive_Interaction_Broker::hint($reason);chk('hint '.$reason,$h['state']===$state&&$h['credential_value_requested_in_chat']===false);}
chk('unknown hint none',MAD4B_SCP_Adaptive_Interaction_Broker::hint('unknown_error')===null);
$plan=MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'mad4b_mcp_scoped_credential_required','provider'=>'google_drive']);
chk('checkpoint created',!is_wp_error($plan)&&preg_match('/^aib\.[a-f0-9]{48}$/D',$plan['checkpoint_ref']));
chk('credential never in chat',$plan['credential_value_requested_in_chat']===false&&$plan['intervention']['form_in_chat_allowed']===false);
chk('insecure origin denied for secret',$plan['intervention']['blocker']==='verified_https_origin_required');
$status=MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']]);
chk('status actor bound',!is_wp_error($status)&&$status['checkpoint_ref']===$plan['checkpoint_ref']);
$resume=MAD4B_SCP_Adaptive_Interaction_Broker::resume(['checkpoint_ref'=>$plan['checkpoint_ref']]);
chk('no automatic unauthorized retry',!is_wp_error($resume)&&$resume['state']==='WAITING_HUMAN_OR_AUTH'&&!$resume['write_resume_supported']);
$bad=MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'mad4b_mcp_scoped_credential_required','api_key'=>'dummy']);
chk('api key parameter rejected',is_wp_error($bad));
chk('unregistered error rejected',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'UNKNOWN'])));
chk('unknown field rejected',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'mad4b_mcp_scoped_credential_required','password'=>'dummy'])));
$inputPlan=MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'mad4b_cso01_stale_descriptor','ability_name'=>'cso/form-catalog']);
chk('typed form checkpoint created',!is_wp_error($inputPlan)&&$inputPlan['state']==='WAITING_INPUT');
$ref=$inputPlan['checkpoint_ref'];
$invalid=MAD4B_SCP_Adaptive_Interaction_Broker::resolve(['checkpoint_ref'=>$ref,'expected_descriptor_sha256'=>str_repeat('a',64),'values'=>['label'=>'invalid']]);
chk('invalid typed value denied cleanly',is_array($invalid)&&$invalid['state']==='INPUT_INVALID');
$secret=MAD4B_SCP_Adaptive_Interaction_Broker::resolve(['checkpoint_ref'=>$ref,'expected_descriptor_sha256'=>str_repeat('a',64),'values'=>['api_key'=>'dummy']]);
chk('typed secret rejected',is_wp_error($secret));
$valid=MAD4B_SCP_Adaptive_Interaction_Broker::resolve(['checkpoint_ref'=>$ref,'expected_descriptor_sha256'=>str_repeat('a',64),'values'=>['label'=>'sample']]);
chk('typed input validated',is_array($valid)&&$valid['state']==='INPUT_VALIDATED'&&$valid['values_echoed']===false);
$readback=MAD4B_SCP_Adaptive_Interaction_Broker::resume(['checkpoint_ref'=>$ref]);
chk('agent replan without native write',$readback['state']==='READY_FOR_AGENT_REPLAN'&&$readback['readback_complete']&&$readback['write_resume_supported']===false);
$fields=MAD4B_SCP_Adaptive_Interaction_Broker::fields(['object_type'=>'post','object_subtype'=>'post']);
chk('registered metadata present',is_array($fields)&&$fields['count']===1&&$fields['fields'][0]['key']==='public_field');
chk('private and credential fields excluded',$fields['values_exposed']===false&&!$fields['mutation_authorized']);
chk('unsupported post type denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::fields(['object_type'=>'post','object_subtype'=>'fake'])));
$GLOBALS['actor']=124;
chk('cross actor denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
$GLOBALS['actor']=123;$GLOBALS['profile']='changed';
chk('changed site profile denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
$GLOBALS['profile']='fixture-profile';$GLOBALS['authorized']=false;
chk('unauthorized denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
$GLOBALS['authorized']=true;$GLOBALS['actor']=123;
chk('forged checkpoint denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>'aib.'.str_repeat('0',48)])));
$key='mad4b_aib_v1_'.hash('sha256',$plan['checkpoint_ref']);$GLOBALS['options'][$key]['expires_at']=time()-1;
chk('expired checkpoint denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
echo 'SUMMARY '.$count.' passed 0 failed'."\n";
