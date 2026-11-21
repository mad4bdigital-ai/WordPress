<?php
/** Actual first-party Approval-Plan permission envelope, no ticket or grant creation. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_filter($name,$cb,$priority=10,$n=1){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class MAD4B_SCP_Transport_Context {
 static function current_server_id(){return 'mad4b-write';}
}
class MAD4B_SCP_Staging_Write_Authority {
 const CONTRACT='mad4b.staging-write-authority.v1';
 static $ready=true,$write=true,$effective=true,$delegated=true;
 static function current_execution_readiness($ability='',$input=null){
  return ['ready'=>self::$ready,'blockers'=>[]];
 }
 static function status(){return ['agent_public_id'=>'11111111-1111-4111-8111-111111111111'];}
 static function is_write_ability($name){return self::$write;}
 static function effective(){return self::$effective;}
 static function is_exact_delegated_oauth_agent($agent,$user){return self::$delegated;}
}
class MAD4B_SCP_Policy {
 static $mutate=true;
 static function can_mutate(){return self::$mutate;}
}
class MAD4B_SCP_Identity_Context {
 static $principal=null;
 static function current(){return self::$principal;}
}
class MAD4B_SCP_Delegation_Mode {
 static $user;
 static function authenticated_user($principal){return self::$user;}
}
class MAD4B_SCP_Agent_Registry {
 static $agent=['id'=>17,'public_id'=>'11111111-1111-4111-8111-111111111111'];
 static $grant=['effect'=>'allow'];
 static function resolve_agent($principal){return self::$agent;}
 static function exact_grant($id,$server,$ability,$provider){return self::$grant;}
}
function user_can($user,$cap){return $GLOBALS['user_can_result'];}
class MAD4B_SCP_Authorization {
 static $decision=['allowed'=>true,'ability'=>'mad4b/approval-plan',
  'provider'=>'core','server_id'=>'mad4b-write','execution_side_effects'=>false];
 static $calls=0;
 static function authorize_mutation($ability,$server,$provider,$input){
  self::$calls++;return self::$decision;
 }
}
class MAD4B_SCP_Resource_Constraint_Set {
 static function compile($ability,$provider,$input){return [
  'contract'=>'mad4b.resource-constraint-set.v1','resource_set_sha256'=>str_repeat('a',64)];}
}
class MAD4B_SCP_Impact_Policy {
 static function ticket_class_for($ability,$provider,$input){return 'mutation';}
}
class MAD4B_SCP_Servers {
 static function provider_for_ability($server,$ability){return 'core';}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
 static $bearer=false;
 static function verified_bearer_active(){return self::$bearer;}
}
function guard_assert($yes,$reason){
 if(!$yes){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-planning-guard.php';
$c='MAD4B_SCP_Staging_Write_Planning_Guard';
$payload=['ability'=>'mad4b/test-existing-write','server_id'=>'mad4b-write',
 'agent_public_id'=>'11111111-1111-4111-8111-111111111111',
 'provider'=>'core','input'=>[]];
$base_permission=true;
$args=['permission_callback'=>function($v)use(&$base_permission){return $base_permission;}];
$wrapped=$c::govern_registration($args,$c::ABILITY);
guard_assert(isset($wrapped['permission_callback'])
 &&is_callable($wrapped['permission_callback'])
 &&$wrapped['meta']['mcp']['surface']==='write'
 &&$wrapped['meta']['mcp']['mad4b_creates_pending_ticket_only']===true,
 'Original approval-plan bootstraps a pending ticket on governed write lane only');
$permission=$wrapped['permission_callback'];
guard_assert($permission($payload)===true && MAD4B_SCP_Authorization::$calls===1,
 'Original exact native pending-plan authorization receipt remains accepted');
foreach([new WP_Error('origin_denied'),false,1,'true',null,[]] as $unknown){
 $base_permission=$unknown;
 $before=MAD4B_SCP_Authorization::$calls;
 $result=$permission($payload);
 guard_assert($result!==true
  &&MAD4B_SCP_Authorization::$calls===$before,
  'Nonboolean original WordPress permission cannot reach the authority preflight');
}
$base_permission=true;
$GLOBALS['user_can_result']=true;
MAD4B_SCP_Delegation_Mode::$user=(object)['ID'=>17];
MAD4B_SCP_Identity_Context::$principal=[
 'subject_type'=>'oauth','auth_method'=>'oauth2_bearer'];
guard_assert($permission($payload)===true,
 'Exact native OAuth agent and enrolled WordPress user retain pending-plan eligibility');
foreach([new WP_Error('user_unknown'),false,null,(object)['ID'=>new WP_Error('id_unknown')]] as $bad){
 MAD4B_SCP_Delegation_Mode::$user=$bad;
 $before=MAD4B_SCP_Authorization::$calls;
 guard_assert(is_wp_error($permission($payload))
  &&MAD4B_SCP_Authorization::$calls===$before,
  'Unverified original OAuth WordPress user identity cannot initiate a pending approval');
}
MAD4B_SCP_Delegation_Mode::$user=(object)['ID'=>17];
foreach([new WP_Error('manage_options_unknown'),false,1,'true',null] as $bad){
 $GLOBALS['user_can_result']=$bad;
 $before=MAD4B_SCP_Authorization::$calls;
 guard_assert(is_wp_error($permission($payload))
  &&MAD4B_SCP_Authorization::$calls===$before,
  'Original WordPress manage_options must be exact boolean true for delegated agent');
}
$GLOBALS['user_can_result']=true;
foreach([new WP_Error('delegation_unknown'),false,1,'true',null] as $bad){
 MAD4B_SCP_Staging_Write_Authority::$delegated=$bad;
 $before=MAD4B_SCP_Authorization::$calls;
 guard_assert(is_wp_error($permission($payload))
  &&MAD4B_SCP_Authorization::$calls===$before,
  'Original delegated OAuth agent certificate must be exact boolean true');
}
MAD4B_SCP_Staging_Write_Authority::$delegated=true;
foreach([new WP_Error('grant_missing'),['effect'=>'deny'],['effect'=>new WP_Error('effect_unknown')],null] as $bad){
 MAD4B_SCP_Agent_Registry::$grant=$bad;
 $before=MAD4B_SCP_Authorization::$calls;
 guard_assert(is_wp_error($permission($payload))
  &&MAD4B_SCP_Authorization::$calls===$before,
  'Exact original OAuth agent must have a verified allow grant on mad4b-write');
}
MAD4B_SCP_Agent_Registry::$grant=['effect'=>'allow'];
MAD4B_SCP_Identity_Context::$principal=null;
$base_permission=true;
foreach([new WP_Error('policy_unknown'),false,1,'true',null] as $unknown){
 MAD4B_SCP_Policy::$mutate=$unknown;
 $before=MAD4B_SCP_Authorization::$calls;
 $result=$permission($payload);
 guard_assert(is_wp_error($result) && MAD4B_SCP_Authorization::$calls===$before,
  'Unverified original mutation policy cannot create an approval-plan ticket');
}
MAD4B_SCP_Policy::$mutate=true;
foreach([new WP_Error('readiness_unknown'),false,1,'true',null] as $unknown){
 MAD4B_SCP_Staging_Write_Authority::$ready=$unknown;
 $before=MAD4B_SCP_Authorization::$calls;
 $result=$permission($payload);
 guard_assert(is_wp_error($result)&&MAD4B_SCP_Authorization::$calls===$before,
  'Original live write readiness must be exact true before ticket admission');
}
MAD4B_SCP_Staging_Write_Authority::$ready=true;
foreach([new WP_Error('inventory_unknown'),false,1,'true',null] as $unknown){
 MAD4B_SCP_Staging_Write_Authority::$write=$unknown;
 $before=MAD4B_SCP_Authorization::$calls;
 $result=$permission($payload);
 guard_assert(is_wp_error($result)&&MAD4B_SCP_Authorization::$calls===$before,
  'Unknown original write inventory cannot authorize a ticket');
}
MAD4B_SCP_Staging_Write_Authority::$write=true;
foreach([
 new WP_Error('authorization_denied'),false,1,'true',null,[],
 ['allowed'=>true,'ability'=>'foreign-ability','provider'=>'core','server_id'=>'mad4b-write'],
 ['allowed'=>true,'ability'=>'mad4b/approval-plan','provider'=>'foreign','server_id'=>'mad4b-write'],
 ['allowed'=>true,'ability'=>'mad4b/approval-plan','provider'=>'core','server_id'=>'foreign'],
 ['allowed'=>new WP_Error('approval_unknown'),'ability'=>'mad4b/approval-plan',
  'provider'=>'core','server_id'=>'mad4b-write']
] as $unknown){
 MAD4B_SCP_Authorization::$decision=$unknown;
 guard_assert($permission($payload)!==true,
  'Only independently authorized exact native ability+server+provider may mint a pending ticket');
}
MAD4B_SCP_Authorization::$decision=['allowed'=>true,'ability'=>'mad4b/approval-plan',
 'provider'=>'core','server_id'=>'mad4b-write','execution_side_effects'=>false];
foreach([new WP_Error('write_authority_unknown'),false,1,'true',null] as $unknown){
 MAD4B_SCP_Staging_Write_Authority::$effective=$unknown;
 guard_assert(!in_array('ability:mad4b/approval-plan',
  $c::add_remote_planner_scope(['token_scopes'=>[]])['token_scopes'],true),
  'Unverified runtime authority may not add an OAuth planning scope');
}
MAD4B_SCP_Staging_Write_Authority::$effective=true;
foreach([new WP_Error('bearer_unknown'),false,1,'true',null] as $unknown){
 MAD4B_SCP_OAuth_Resource_Bridge::$bearer=$unknown;
 guard_assert(!in_array('ability:mad4b/approval-plan',
  $c::add_remote_planner_scope(['token_scopes'=>[]])['token_scopes'],true),
  'Unverified OAuth bearer may not inherit a governed pending ticket planning scope');
}
MAD4B_SCP_OAuth_Resource_Bridge::$bearer=true;
guard_assert(in_array('ability:mad4b/approval-plan',
 $c::add_remote_planner_scope(['token_scopes'=>[]])['token_scopes'],true),
 'Original exact verified bearer and write authority retain pending-ticket scope');
MAD4B_SCP_Staging_Write_Authority::$effective=true;
guard_assert($permission($payload)===true,
 'Valid original pending ticket permission recovers after denied probes without creating one');
echo 'PASS: STAGING_WRITE_PLANNING_GUARD_ORIGINAL '.($GLOBALS['checks']??0).
 ' exact WordPress permissions, runtime readiness, prior authorization and OAuth scope'.PHP_EOL;
