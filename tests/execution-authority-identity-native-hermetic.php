<?php
/**
 * Original Execution Authority read/apply resolution, no WordPress/DB/network.
 * Proven host transport identity is not a write authorization or new grant.
 */
define('ABSPATH',__DIR__.'/');define('WP_CLI',true);
class WP_Error {
 private $code; private $msg; private $data;
 public function __construct($code,$msg='',$data=null){
   $this->code=$code;$this->msg=$msg;$this->data=$data;
 }
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$x));}
function get_current_user_id(){return $GLOBALS['qe_uid'];}
function current_user_can($cap){return $GLOBALS['qe_admin']&&$cap==='manage_options';}
function get_userdata($id){return $id>0?(object)array('ID'=>$id):null;}
function user_can($user,$cap){return $GLOBALS['qe_admin'] && $cap==='manage_options';}
class MAD4B_SCP_Site_Profile {
 static function configured(){return $GLOBALS['qe_configured'];}
 static function origin_enrolled(){return $GLOBALS['qe_enrolled_site'];}
 static function site_urls_match_enrollment(){return $GLOBALS['qe_urls'];}
 static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
 static function site_origin(){return 'https://tours.example.invalid';}
 static function current_environment(){return $GLOBALS['qe_env'];}
 static function oauth_user_ids(){return $GLOBALS['qe_owner_ids'];}
 static function user_is_enrolled($id){return in_array((int)$id,$GLOBALS['qe_owner_ids'],true);}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
 static function verified_bearer_active(){return $GLOBALS['qe_oauth'];}
}
class MAD4B_SCP_Identity_Context {
 static function current(){return $GLOBALS['qe_identity'];}
}
class MAD4B_SCP_CLI_Execution_Context {
 static function resolve($op,$is_apply){
   $GLOBALS['qe_context_calls'][]=array($op,$is_apply);
   if($GLOBALS['qe_context_error'])return new WP_Error('qa_context_unavailable');
   return array('read_context_verified'=>$GLOBALS['qe_read'],
      'local_operation_authorized'=>$GLOBALS['qe_apply'],
      'host_site_binding_verified'=>$GLOBALS['qe_host_binding'],
      'blockers'=>$GLOBALS['qe_blockers'],
      'canonical_origin'=>'https://tours.example.invalid',
      'site_uuid'=>$GLOBALS['qe_cli_site_uuid']??'11111111-1111-4111-8111-111111111111',
      'environment'=>$GLOBALS['qe_cli_environment']??$GLOBALS['qe_env']);
 }
}
class MAD4B_SCP_Agent_Registry {
 static function subject_binding($type,$fingerprint){
   $GLOBALS['qe_registry_probes'][]=array($type,$fingerprint);
   return $GLOBALS['qe_subject_binding'];
 }
 static function get_agent_by_id($id){return $GLOBALS['qe_owner_agent'];}
 static function resolve_agent($identity){
   $GLOBALS['qe_resolved_identity']=$identity;
   return $GLOBALS['qe_agent']===null
      ?new WP_Error('mad4b_nhi_subject_unbound'):$GLOBALS['qe_agent'];
 }
}
class MAD4B_SCP_Governed_Runtime_Gates {
 static function raw_sql_breakglass_enabled(){return $GLOBALS['qe_sql_breakglass'];}
}
class MAD4B_SCP_Policy {
 static function can_breakglass(){return $GLOBALS['qe_breakglass'];}
}
function qe_reset(){
 $GLOBALS['qe_configured']=true;$GLOBALS['qe_enrolled_site']=true;
 $GLOBALS['qe_urls']=true;$GLOBALS['qe_env']='staging';
 $GLOBALS['qe_uid']=0;$GLOBALS['qe_admin']=true;$GLOBALS['qe_oauth']=false;
 $GLOBALS['qe_owner_ids']=array(22);$GLOBALS['qe_read']=true;
 $GLOBALS['qe_apply']=true;$GLOBALS['qe_host_binding']=true;
 $GLOBALS['qe_context_error']=false;$GLOBALS['qe_blockers']=array();
 $GLOBALS['qe_sql_breakglass']=false;$GLOBALS['qe_breakglass']=false;
 $GLOBALS['qe_identity']=array('authenticated'=>true,'subject_type'=>'oauth',
   'auth_method'=>'oauth2_bearer','subject_fingerprint'=>str_repeat('a',64),
   'client_fingerprint'=>str_repeat('b',64));
 $GLOBALS['qe_owner_agent']=array('id'=>7,'wp_user_id'=>22,'status'=>'enabled',
   'environment'=>'staging');
 $GLOBALS['qe_subject_binding']=array('agent_id'=>7,'status'=>'enabled');
 $GLOBALS['qe_agent']=array('id'=>7,'public_id'=>'11111111-1111-4111-8111-111111111111',
   'wp_user_id'=>22,'status'=>'enabled','environment'=>'staging');
 $GLOBALS['qe_context_calls']=array();$GLOBALS['qe_registry_probes']=array();
 $GLOBALS['qe_resolved_identity']=null;
 unset($GLOBALS['qe_cli_site_uuid'],$GLOBALS['qe_cli_environment']);
 unset($_SERVER['HTTP_AUTHORIZATION']);
}
function qe_check($ok,$why){if(!$ok)throw new RuntimeException('FAIL EXECUTION AUTHORITY: '.$why);}
function qe_denial($res,$code){
 qe_check(is_wp_error($res)&&$res->get_error_code()===$code,'Expected '.$code);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-execution-authority.php';
$c='MAD4B_SCP_Execution_Authority';
qe_reset();
qe_denial($c::resolve('unknown'),'mad4b_actor_operation_invalid');
qe_reset();
$GLOBALS['qe_configured']=false;
qe_denial($c::resolve('read'),'mad4b_actor_site_not_enrolled');
qe_reset();
$GLOBALS['qe_uid']=44;
qe_denial($c::resolve('apply'),'mad4b_actor_host_operator_unverified');
qe_reset();
$_SERVER['HTTP_AUTHORIZATION']='Bearer injected';
qe_denial($c::resolve('apply'),'mad4b_actor_host_operator_unverified');
qe_reset();
$GLOBALS['qe_apply']=false;
qe_denial($c::resolve('apply'),'mad4b_actor_host_binding_unverified');
$read=$c::resolve('read');
qe_check(is_array($read) && !$read['execution_authorized'],
    'read-only attestation must not grant write access');
qe_check($GLOBALS['qe_context_calls'][0]===array('authority.apply',true),
    'apply must request exact operation authorization from native context');
qe_reset();
$GLOBALS['qe_context_error']=true;
qe_denial($c::resolve('apply'),'mad4b_actor_host_binding_unverified');
qe_reset();
$GLOBALS['qe_blockers']=array('host_binding_mismatch');
qe_denial($c::resolve('apply'),'mad4b_actor_host_binding_unverified');
qe_reset();
$GLOBALS['qe_cli_site_uuid']='22222222-2222-4222-8222-222222222222';
qe_denial($c::resolve('apply'),'mad4b_actor_host_binding_unverified');
qe_reset();
$GLOBALS['qe_cli_environment']='production';
qe_denial($c::resolve('apply'),'mad4b_actor_host_binding_unverified');
qe_reset();
$GLOBALS['qe_agent']=null;
qe_denial($c::resolve('apply'),'mad4b_actor_registry_binding_required');
$read=$c::resolve('read');
qe_check(is_array($read)&&$read['registry_bound']===false
    && $read['execution_authorized']===false,
    'Unbound verified host may inspect only, never write');
qe_reset();
$GLOBALS['qe_agent']['wp_user_id']=24;
$actor=$c::resolve('apply');
qe_check(is_array($actor) && $actor['wp_user_id']===0
  && $actor['registry_bound']===true
  && $actor['registered_host_agent_id']===7
  && $actor['canonical_principal']===hash('sha256',"mad4b.host-principal.v1\0"
     .$actor['site_uuid']."\0".$actor['environment']."\0".$actor['subject_fingerprint']),
  'Host NHI identity must not be the bound WordPress user');
qe_reset();
$actor=$c::resolve('apply');
qe_check(is_array($actor)&&$actor['registry_bound']===true
  && $actor['wp_user_id']===0
  && $actor['transport']==='host_cli'
  && $actor['execution_authorized']===false
  && $actor['mutation_performed']===false
  && $actor['grants_created']===false,
  'Bound CLI host is merely identity evidence, not grant or execution');
qe_check($GLOBALS['qe_resolved_identity']['subject_fingerprint']===
 hash('sha256',"mad4b.host-operator.v1\0".MAD4B_SCP_Site_Profile::site_uuid()
   ."\0staging\0https://tours.example.invalid"),
  'Host fingerprint must be site/environment/origin bound');
qe_reset();
$GLOBALS['qe_owner_ids']=array(22,24);
$GLOBALS['qe_subject_binding']=null;
$actor=$c::resolve('apply');
qe_check(is_array($actor) && $actor['wp_user_id']===0
    && $actor['registry_bound']===true,
    'Multiple human admins cannot alter Host NHI identity');
qe_reset();
$GLOBALS['qe_owner_ids']=array();
$actor=$c::resolve('apply');
qe_check(is_array($actor) && $actor['wp_user_id']===0,
    'No WordPress admin enrollment is needed to identify an existing Host NHI');
qe_reset();
$GLOBALS['qe_agent']['status']='disabled';
qe_denial($c::resolve('apply'),'mad4b_actor_registry_binding_conflict');
qe_reset();
$GLOBALS['qe_agent']['id']=0;
qe_denial($c::resolve('apply'),'mad4b_actor_registry_binding_conflict');
qe_reset();
$GLOBALS['qe_agent']['environment']='production';
qe_denial($c::resolve('apply'),'mad4b_actor_registry_binding_conflict');
qe_reset();
$GLOBALS['qe_owner_ids']=array(22,24);
$GLOBALS['qe_owner_agent']['wp_user_id']=24;
$GLOBALS['qe_agent']['wp_user_id']=24;
$actor=$c::resolve('apply');
qe_check(is_array($actor)&&$actor['wp_user_id']===0
  && $actor['registry_bound']===true
  && $actor['registered_host_agent_id']===7,
  'Enrolled human owner never overrides existing Host NHI identity');
qe_reset();
$GLOBALS['qe_oauth']=true;$GLOBALS['qe_uid']=22;
$GLOBALS['qe_identity']['auth_method']='application_password';
qe_denial($c::resolve('apply'),'mad4b_actor_oauth_identity_invalid');
qe_reset();
$GLOBALS['qe_oauth']=true;$GLOBALS['qe_uid']=22;
$actor=$c::resolve('apply');
qe_check(is_array($actor)&&$actor['transport']==='oauth_mcp'
  && !$actor['execution_authorized'] && !$actor['grants_created'],
  'Verified OAuth identity alone cannot authorize a write');
qe_reset();
$GLOBALS['qe_env']='production';
$GLOBALS['qe_agent']['environment']='production';
qe_denial($c::require_access('apply',array('staging')),
  'mad4b_actor_environment_denied');
qe_reset();
$GLOBALS['qe_sql_breakglass']=true;
qe_denial($c::require_access('apply'),'mad4b_actor_breakglass_conflict');
qe_reset();
$GLOBALS['qe_breakglass']=true;
qe_denial($c::require_access('apply'),'mad4b_actor_breakglass_conflict');
echo "PASS: EXECUTION_AUTHORITY_IDENTITY_NATIVE 15 authorization scenarios\n";
