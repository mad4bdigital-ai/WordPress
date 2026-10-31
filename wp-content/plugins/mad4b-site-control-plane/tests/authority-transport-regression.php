<?php
/**
 * Read-only simulation of existing MAD4B Execution_Authority.
 * No credentials, grants, agents, endpoints, or production changes.
 */
define('ABSPATH',__DIR__);
define('WP_CLI',true);
final class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s));}
$GLOBALS['auth_mode']='host';
$GLOBALS['host_ready']=true;
$GLOBALS['current_user']=0;
$GLOBALS['bound']=true;
$GLOBALS['agent_user']=9;
$GLOBALS['site_uuid']='00000000-0000-4000-8000-000000000009';
function get_current_user_id(){return $GLOBALS['current_user'];}
function current_user_can($cap){return $cap==='manage_options' && $GLOBALS['current_user']===9;}
function get_userdata($id){return $id===9?(object)array('ID'=>9):false;}
function user_can($u,$cap){return is_object($u)&&$u->ID===9&&$cap==='manage_options';}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return true;}
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
    public static function site_uuid(){return $GLOBALS['site_uuid'];}
    public static function current_environment(){return 'staging';}
    public static function oauth_user_ids(){return array(9);}
    public static function user_is_enrolled($id){return (int)$id===9;}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_active(){return $GLOBALS['auth_mode']==='oauth';}
}
class MAD4B_SCP_Identity_Context {
    public static function current(){
        return array('authenticated'=>true,'subject_type'=>'oauth',
            'auth_method'=>'oauth2_bearer',
            'subject_fingerprint'=>str_repeat('a',64),
            'client_fingerprint'=>str_repeat('b',64));
    }
}
class MAD4B_SCP_CLI_Execution_Context {
    public static function resolve($op,$mutation){
        $ok=$GLOBALS['host_ready'];
        return array('read_context_verified'=>$ok,'local_operation_authorized'=>$ok,
            'host_site_binding_verified'=>$ok,'blockers'=>$ok?array():array('host_binding_invalid'),
            'canonical_origin'=>'https://tours.dev.mad4b.com');
    }
}
class MAD4B_SCP_Agent_Registry {
    public static function resolve_agent($identity){
        if (!$GLOBALS['bound'])return new WP_Error('mad4b_nhi_subject_unbound');
        return array('id'=>7,'wp_user_id'=>$GLOBALS['agent_user'],
            'environment'=>'staging','status'=>'enabled');
    }
}
class MAD4B_SCP_Policy {public static function can_breakglass(){return false;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-execution-authority.php';
function check($b,$reason){if(!$b){fwrite(STDERR,"FAIL: $reason\n");exit(1);}}
$host=MAD4B_SCP_Execution_Authority::require_access('apply',array('staging','production'));
check(is_array($host)&&$host['transport']==='host_cli','verified host accepted as host, not OAuth');
check($host['registry_bound']===true,'host registry bound');
check(!$host['execution_authorized']&&!$host['mutation_performed'],'host actor does not grant execution');
$GLOBALS['host_ready']=false;
$_SERVER['HTTP_X_MAD4B_BROKER']='true';
$forged=MAD4B_SCP_Execution_Authority::resolve('apply');
check(is_wp_error($forged)&&$forged->get_error_code()==='mad4b_actor_host_binding_unverified',
    'broker header alone cannot authenticate');
unset($_SERVER['HTTP_X_MAD4B_BROKER']);
$GLOBALS['host_ready']=true;
$GLOBALS['bound']=false;
$unbound=MAD4B_SCP_Execution_Authority::resolve('apply');
check(is_wp_error($unbound)&&$unbound->get_error_code()==='mad4b_actor_registry_binding_required',
    'host without registered subject cannot apply');
$GLOBALS['bound']=true;
$GLOBALS['agent_user']=8;
$mismatch=MAD4B_SCP_Execution_Authority::resolve('apply');
check(is_wp_error($mismatch)&&$mismatch->get_error_code()==='mad4b_actor_registry_binding_conflict',
    'agent principal mismatch denied');
$GLOBALS['agent_user']=9;
$GLOBALS['auth_mode']='oauth';
$GLOBALS['current_user']=9;
$oauth=MAD4B_SCP_Execution_Authority::require_access('apply',array('staging','production'));
check(is_array($oauth)&&$oauth['transport']==='oauth_mcp','verified OAuth classified separately');
check($oauth['canonical_principal']===$host['canonical_principal'],
    'same owner maps to canonical principal without copying subject fingerprints');
check(!$oauth['execution_authorized']&&!$oauth['grants_created'],
    'verified OAuth also cannot create a grant from authentication alone');
echo "PASS: verified host, verified OAuth, broker assertion denial, registry binding and principal parity (9 checks)\n";
