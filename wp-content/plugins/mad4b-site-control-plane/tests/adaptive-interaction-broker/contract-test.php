<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code(403); exit; }
/** Synthetic WordPress fixture: NO real users, secrets, database or external requests. */
define('ABSPATH','/fixture');
define('MAD4B_SCP_DIR', dirname(__DIR__,2).'/');
class WP_Error {private $code;private $message;private $data;function __construct($code,$message='',$data=[]){$this->code=$code;$this->message=$message;$this->data=$data;}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}function get_error_data(){return $this->data;}function add_data($d,$code=''){ $this->data=$d; }}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function wp_json_encode($v){return json_encode($v);}
function home_url(){return $GLOBALS['home_url_override']??'http://localhost:8091';}
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
function set_transient($name,$value,$ttl){
 if(array_key_exists('set_transient_override',$GLOBALS))return $GLOBALS['set_transient_override'];
 $GLOBALS['options'][$name]=$value;return true;
}
function get_transient($name){
 if(strpos($name,'quota_')!==false && array_key_exists('quota_read_override',$GLOBALS))
  return $GLOBALS['quota_read_override'];
 return $GLOBALS['options'][$name]??false;
}
class MAD4B_SCP_Site_Profile{static function status(){return [
 'origin_match'=>array_key_exists('origin_match_override',$GLOBALS)?$GLOBALS['origin_match_override']:true,
 'environment_match'=>array_key_exists('environment_match_override',$GLOBALS)?$GLOBALS['environment_match_override']:true,
 'site_uuid'=>array_key_exists('site_uuid_override',$GLOBALS)?$GLOBALS['site_uuid_override']:'site-fixture',
 'profile_digest'=>$GLOBALS['profile'],
 'revision'=>array_key_exists('site_revision_override',$GLOBALS)?$GLOBALS['site_revision_override']:3];}}
class MAD4B_SCP_Policy{
 static function can_read(){return array_key_exists('read_override',$GLOBALS)?$GLOBALS['read_override']:$GLOBALS['authorized'];}
 static function can_connect_user($id){return array_key_exists('connect_override',$GLOBALS)?$GLOBALS['connect_override']:$GLOBALS['authorized'];}
}
class MAD4B_SCP_CSO_Scope{static function safe_data($v){if(array_key_exists('safe_data_override',$GLOBALS))return $GLOBALS['safe_data_override'];if(!is_array($v))return false;foreach($v as $key=>$value){if(preg_match('/api[_-]?key|secret|password|token|credential/i',$key))return false;if(is_array($value)){if(!self::safe_data($value))return false;}elseif(!is_string($value)||strlen($value)>160)return false;}return true;}static function enabled($name){return false;}}
class MAD4B_SCP_Provider_Connection_Center{
 static function plan($args){return $GLOBALS['provider_status_override']??new WP_Error('provider_missing');}
}
class MAD4B_SCP_CSO01_Read_Foundation{static function can_read(){return true;}static function form_schema($input){
 return array_key_exists('form_schema_override',$GLOBALS)?$GLOBALS['form_schema_override']:
 ['descriptor_sha256'=>str_repeat('a',64),'fields'=>[]];
}static function form_validate($args){if(array_key_exists('form_validate_override',$GLOBALS))return $GLOBALS['form_validate_override'];if($args['expected_descriptor_sha256']!==str_repeat('a',64))return new WP_Error('stale_descriptor');if(($args['values']['label']??'')!=='sample')return ['valid'=>false,'issues'=>['invalid_value'],'field_errors'=>['label'=>'invalid']];return ['valid'=>true,'values_echoed'=>false];}}
 $GLOBALS['authorized']=true;$GLOBALS['actor']=123;$GLOBALS['profile']='fixture-profile';$GLOBALS['environment']='local';$GLOBALS['options']=[];
require dirname(__DIR__,2).'/includes/class-mad4b-scp-adaptive-interaction-broker.php';
require dirname(__DIR__,2).'/includes/class-mad4b-scp-error-contract-registry.php';
$count=0;function chk($name,$truth){global $count;$count++;if(!$truth){echo 'FAIL '.$name."\n";exit(1);}echo 'PASS '.$name."\n";}
$reasons=['mad4b_mcp_scoped_credential_required'=>'WAITING_AUTH','mad4b_mcp_credential_resolver_unavailable'=>'WAITING_AUTH','mad4b_provider_unknown'=>'WAITING_PROVIDER','mad4b_approval_exact_input_required'=>'WAITING_APPROVAL','mad4b_cso01_field_sensitive_or_invalid'=>'WAITING_SECURE_HANDOFF','mad4b_cso01_stale_descriptor'=>'WAITING_INPUT'];
foreach($reasons as $reason=>$state){$h=MAD4B_SCP_Adaptive_Interaction_Broker::hint($reason);chk('hint '.$reason,$h['state']===$state&&$h['credential_value_requested_in_chat']===false);}
chk('unknown hint none',MAD4B_SCP_Adaptive_Interaction_Broker::hint('unknown_error')===null);
$e=MAD4B_SCP_Error_Contract_Registry::enrich_wp_error(new WP_Error('mad4b_mcp_scoped_credential_required','no credential'));
$ed=$e->get_error_data();
chk('native error bridge adds registered safe hint',!empty($ed['adaptive_interaction'])&&$ed['adaptive_interaction']['state']==='WAITING_AUTH'&&$ed['adaptive_interaction']['mutation_authorized']===false);
chk('native error bridge does not ask for secrets',empty($ed['adaptive_interaction']['credential_value_requested_in_chat'])&&$ed['adaptive_interaction']['secret_ingress']==='separate_first_party_only');
$plan=MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'mad4b_mcp_scoped_credential_required','provider'=>'google_drive']);
chk('checkpoint created',!is_wp_error($plan)&&preg_match('/^aib\.[a-f0-9]{48}$/D',$plan['checkpoint_ref']));
chk('credential never in chat',$plan['credential_value_requested_in_chat']===false&&$plan['intervention']['form_in_chat_allowed']===false);
chk('insecure origin denied for secret',$plan['intervention']['blocker']==='verified_https_origin_required');
$status=MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']]);
chk('status actor bound',!is_wp_error($status)&&$status['checkpoint_ref']===$plan['checkpoint_ref']);
$resume=MAD4B_SCP_Adaptive_Interaction_Broker::resume(['checkpoint_ref'=>$plan['checkpoint_ref']]);
chk('no automatic unauthorized retry',!is_wp_error($resume)&&$resume['state']==='WAITING_HUMAN_OR_AUTH'&&!$resume['write_resume_supported']);
$providerPlan=MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>'mad4b_provider_unknown','provider'=>'missing_provider']);
chk('provider checkpoint created',!is_wp_error($providerPlan));
$providerReadback=MAD4B_SCP_Adaptive_Interaction_Broker::resume(['checkpoint_ref'=>$providerPlan['checkpoint_ref']]);
chk('provider module absent cannot cause fatal or authorize retry',!is_wp_error($providerReadback)&&$providerReadback['state']==='WAITING_HUMAN_OR_AUTH'&&!$providerReadback['readback_complete']&&!$providerReadback['write_resume_supported']);
foreach([new WP_Error('provider_unavailable'),null,true,1,
 ['action'=>new WP_Error('state_unverified')],
 ['action'=>'connect','consent_requires_browser'=>new WP_Error('action_unknown')]] as $bad){
 $GLOBALS['provider_status_override']=$bad;
 $out=MAD4B_SCP_Adaptive_Interaction_Broker::resume(['checkpoint_ref'=>$providerPlan['checkpoint_ref']]);
 chk('Unknown original provider readback must not mark recovery complete',
  is_array($out)&&$out['readback_complete']===false&&$out['state']==='WAITING_HUMAN_OR_AUTH');
}
unset($GLOBALS['provider_status_override']);
$GLOBALS['home_url_override']='https://operator.example.test';
foreach([new WP_Error('url_unknown'),null,true,'javascript:alert(1)','http://unknown.test'] as $bad_url){
 $GLOBALS['provider_status_override']=[
  'action'=>'connect','consent_requires_browser'=>true,
  'owner_handoff_url'=>$bad_url];
 $intervention=MAD4B_SCP_Adaptive_Interaction_Broker::status(
  ['checkpoint_ref'=>$providerPlan['checkpoint_ref']]);
 chk('Unverified Owner handoff URL never becomes a ready action',
  is_array($intervention)
  &&$intervention['intervention']['ready']===false
  &&!isset($intervention['intervention']['owner_handoff_url']));
}
$GLOBALS['provider_status_override']=[
 'action'=>'connect','consent_requires_browser'=>true,
 'owner_handoff_url'=>'https://operator.example.test/provider/connect'];
$verified_handoff=MAD4B_SCP_Adaptive_Interaction_Broker::status(
 ['checkpoint_ref'=>$providerPlan['checkpoint_ref']]);
chk('Valid native HTTPS provider owner handoff remains available',
 is_array($verified_handoff)&&$verified_handoff['intervention']['ready']===true
 &&$verified_handoff['intervention']['owner_handoff_url']==='https://operator.example.test/provider/connect');
unset($GLOBALS['provider_status_override'],$GLOBALS['home_url_override']);
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
$GLOBALS['authorized']=true;$GLOBALS['environment']='staging';
chk('uncertified Staging pilot denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
$GLOBALS['environment']='production';
chk('uncertified Production pilot denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
$GLOBALS['environment']='local';
$GLOBALS['authorized']=true;$GLOBALS['actor']=123;
foreach(['site_uuid_override','site_revision_override'] as $field){
 foreach([new WP_Error('site_profile_source_unavailable'),[],true,null] as $bad){
  $GLOBALS[$field]=$bad;
  chk('Malformed original profile identity does not admit a checkpoint',
   MAD4B_SCP_Adaptive_Interaction_Broker::permitted()===false);
 }
 unset($GLOBALS[$field]);
}
$reason=['reason_code'=>'mad4b_mcp_scoped_credential_required'];
foreach([new WP_Error('bad_reason'),[],1] as $bad){
 $GLOBALS['safe_data_override']=true;
 chk('Untrusted plan input cannot be cast into a real checkpoint',
  is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::plan(['reason_code'=>$bad])));
}
unset($GLOBALS['safe_data_override']);
foreach([new WP_Error('quota_unknown'),'7',null] as $unknown){
 $GLOBALS['quota_read_override']=$unknown;
 chk('Unverified quota read cannot create a checkpoint',
  is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::plan($reason)));
}
unset($GLOBALS['quota_read_override']);
foreach([new WP_Error('transient_store_unknown'),1,'true',null] as $unknown){
 $GLOBALS['set_transient_override']=$unknown;
 chk('Unverified original checkpoint persistence is never considered success',
  is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::plan($reason)));
}
unset($GLOBALS['set_transient_override']);
foreach([new WP_Error('schema_unknown'),true,'schema_found'] as $unknown){
 $GLOBALS['form_schema_override']=$unknown;
 $result=MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$ref]);
 chk('Unverified original native form schema cannot become a ready form intervention',
  is_array($result)&&$result['intervention']['ready']===false);
}
unset($GLOBALS['form_schema_override']);
$key_for_form='mad4b_aib_v1_'.hash('sha256',$ref);
$old_expiry=$GLOBALS['options'][$key_for_form]['expires_at'];
foreach([new WP_Error('expiry_unknown'),null,'later'] as $invalid){
 $GLOBALS['options'][$key_for_form]['expires_at']=$invalid;
 chk('Malformed persisted original checkpoint must fail closed',
  is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$ref])));
}
$GLOBALS['options'][$key_for_form]['expires_at']=$old_expiry;
foreach(['authorized','read_override','connect_override','origin_match_override','environment_match_override'] as $field){
 foreach([new WP_Error('proof_unavailable'),1,'true',null] as $invalid){
  $GLOBALS[$field]=$invalid;
  chk('Original broker requires exact verified boolean permission '.$field,
   MAD4B_SCP_Adaptive_Interaction_Broker::permitted()===false);
 }
 if($field==='authorized')$GLOBALS['authorized']=true;
 else unset($GLOBALS[$field]);
}
foreach([new WP_Error('safe_value_check_unavailable'),1,'true',null] as $invalid){
 $GLOBALS['safe_data_override']=$invalid;
 chk('Unverified CSO safe data source cannot approve checkpoint creation',
  is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::plan($reason)));
}
unset($GLOBALS['safe_data_override']);
foreach([new WP_Error('form_evidence_unknown'),null,1,'true',
 ['valid'=>new WP_Error('form_validity_unknown')],
 ['valid'=>1]] as $invalid){
 $GLOBALS['form_validate_override']=$invalid;
 chk('Unverified native Form validation cannot release a retry checkpoint',
  is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::resolve(
    ['checkpoint_ref'=>$ref,'expected_descriptor_sha256'=>str_repeat('a',64),
    'values'=>['label'=>'sample']])));
}
unset($GLOBALS['form_validate_override']);
chk('forged checkpoint denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>'aib.'.str_repeat('0',48)])));
$key='mad4b_aib_v1_'.hash('sha256',$plan['checkpoint_ref']);$GLOBALS['options'][$key]['expires_at']=time()-1;
chk('expired checkpoint denied',is_wp_error(MAD4B_SCP_Adaptive_Interaction_Broker::status(['checkpoint_ref'=>$plan['checkpoint_ref']])));
$qa31_agent_source=file_get_contents(dirname(__DIR__,2).'/includes/class-mad4b-scp-agent-grant-lifecycle.php');
chk('qa31 W1 REST grant route owner-gated',is_string($qa31_agent_source)&&strpos($qa31_agent_source,"register_rest_route('mad4b/v1'")!==false&&strpos($qa31_agent_source,"'permission_callback'=>array(__CLASS__,'owner')")!==false);
// The new source-only CWV module may cite the existing mad4b/v1 route
// namespace, but it must not boot or register any endpoint on this branch.
$cwv=fopen(dirname(__DIR__,2).'/includes/class-mad4b-scp-core-web-vitals.php','r');
$source=$cwv?stream_get_contents($cwv):'';
if($cwv)fclose($cwv);
$bootstrap=file_get_contents(dirname(__DIR__,2).'/mad4b-site-control-plane.php');
chk('new source-only core-web-vitals API remains unloaded',
    is_string($source)&&strpos($source,"$ns = 'mad4b/v1';")!==false
    && strpos($source,'register_rest_route( $ns,')!==false
    && strpos($source,"'permission_callback'")!==false
    && is_string($bootstrap)
    && strpos($bootstrap,'class-mad4b-scp-core-web-vitals.php')===false);
echo 'SUMMARY '.$count.' passed 0 failed'."\n";
echo 'PASS: native adaptive interaction original checkpoint and readback source assertions' . PHP_EOL;
