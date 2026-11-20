<?php
/** Original Authorization preflight only. No grants/tickets are issued or mutated. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function auth_check($valid,$why){
 if(!$valid){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Schema {
 static $ready=true,$critical=true;
 static function is_ready(){return self::$ready;}
 static function critical_ready(){return self::$critical;}
}
class MAD4B_SCP_MCP_Peer_Governance {
 static $guard=true;
 static function mutation_guard(){return self::$guard;}
}
class MAD4B_SCP_Transport_Context {
 static function resolve_server_for_ability($server,$ability,$input){return $server;}
}
class MAD4B_SCP_Capability_Descriptor_Registry {
 static function binding($name,$surface){
  return ['execution_lane'=>'write','descriptor_sha256'=>str_repeat('a',64)];
 }
}
class MAD4B_SCP_Identity_Context {
 static function current(){return ['subject_type'=>'fixture','request_id'=>'r-1'];}
}
class MAD4B_SCP_Agent_Registry {
 static $grants=0;
 static function resolve_agent($identity){
  return ['id'=>31,'public_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'];
 }
 static function exact_grant($agent,$server,$ability,$provider){
  self::$grants++;
  return ['id'=>'99','effect'=>'allow','resource_constraints'=>''];
 }
}
class MAD4B_SCP_Policy {
 static $mutate=true,$breakglass=true,$developer=true,$devbreak=true;
 static function can_mutate(){return self::$mutate;}
 static function can_breakglass(){return self::$breakglass;}
 static function can_developer(){return self::$developer;}
 static function can_developer_breakglass(){return self::$devbreak;}
}
class MAD4B_SCP_Staging_Write_Authority {
 static $manages=true,$ready=false,$calls=0;
 static function manages_agent($agent){return self::$manages;}
 static function current_execution_readiness($ability,$input){
  self::$calls++;
  return ['ready'=>self::$ready,'blockers'=>['readiness_unknown'],
    'candidate_binding_match'=>true,'current_grant_snapshot_ready'=>true];
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authorization.php';
$ref=new ReflectionMethod('MAD4B_SCP_Authorization','authorize_mutation_impl');
if(PHP_VERSION_ID<80100)$ref->setAccessible(true);
$run=function($server='mad4b-write')use($ref){
 return $ref->invoke(null,'mad4b/fixture-write',$server,'core',[]);
};
$r=$run();
auth_check(is_wp_error($r)&&MAD4B_SCP_Staging_Write_Authority::$calls===1,
 'Original positive authorization must perform a separate current-write readiness check');
foreach(['ready','critical'] as $field){
 foreach([new WP_Error('schema_unknown'),1,'true',null,false] as $bad){
  MAD4B_SCP_Schema::$$field=$bad;
  MAD4B_SCP_Agent_Registry::$grants=0;
  $r=$run();
  auth_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_governance_schema_unavailable'
   &&MAD4B_SCP_Agent_Registry::$grants===0,
   'Unverified original WordPress Schema gate cannot reach Agent grant');
 }
 MAD4B_SCP_Schema::$$field=true;
}
foreach([new WP_Error('peer_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_MCP_Peer_Governance::$guard=$bad;
 MAD4B_SCP_Agent_Registry::$grants=0;
 $r=$run();
 auth_check(is_wp_error($r)&&MAD4B_SCP_Agent_Registry::$grants===0,
  'Unverified original MCP Peer source cannot reach Agent grant');
}
MAD4B_SCP_MCP_Peer_Governance::$guard=true;
$map=['mutate'=>'mad4b-write','breakglass'=>'mad4b-breakglass',
 'developer'=>'mad4b-developer','devbreak'=>'mad4b-developer-breakglass'];
foreach($map as $field=>$server){
 foreach([new WP_Error('policy_unknown'),1,'true',null,false] as $bad){
  MAD4B_SCP_Policy::$$field=$bad;
  MAD4B_SCP_Agent_Registry::$grants=0;
  $r=$run($server);
  auth_check(is_wp_error($r)&&MAD4B_SCP_Agent_Registry::$grants===0,
   'Only exact true original Write/Developer/Breakglass policy can reach Agent grant');
 }
 MAD4B_SCP_Policy::$$field=true;
}
foreach([new WP_Error('managed_unknown'),1,'true',null] as $bad){
 MAD4B_SCP_Staging_Write_Authority::$manages=$bad;
 $before=MAD4B_SCP_Staging_Write_Authority::$calls;
 $r=$run();
 auth_check(is_wp_error($r) &&
  $r->get_error_code()==='mad4b_write_managed_agent_unverified' &&
  MAD4B_SCP_Staging_Write_Authority::$calls===$before,
  'Unverified managed-Agent source is denied before checking any readiness receipt');
}
MAD4B_SCP_Staging_Write_Authority::$manages=true;
foreach([new WP_Error('write_readiness_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Staging_Write_Authority::$ready=$bad;
 $r=$run();
 auth_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_write_authority_current_drift',
  'Unknown original current grant snapshot must stop governed mutation preflight');
}
echo 'PASS: AUTHORIZATION_PREFLIGHT_ORIGINAL '.($GLOBALS['checks']??0).
 ' native Schema, Peer, Policy, managed-Agent and current grant source checks'.PHP_EOL;
