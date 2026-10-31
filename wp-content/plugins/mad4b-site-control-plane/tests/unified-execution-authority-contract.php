<?php
define('ABSPATH',__DIR__);
define('WP_CLI',true);
class WP_Error {
    private $c;
    private $d;
    public function __construct($c,$m='',$d=array()){$this->c=$c;$this->d=$d;}
    public function get_error_code(){return $this->c;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
$GLOBALS['uid']=1;
$GLOBALS['bearer']=true;
$GLOBALS['registered']=false;
$GLOBALS['environment']='staging';
$GLOBALS['origin_ok']=true;
function get_current_user_id(){return $GLOBALS['uid'];}
function current_user_can($cap){return $GLOBALS['uid']===1;}
function get_userdata($id){return $id===1?(object)array('ID'=>1):false;}
function user_can($user,$cap){return (int)$user->ID===1;}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return true;}
    public static function origin_enrolled(){return $GLOBALS['origin_ok'];}
    public static function site_urls_match_enrollment(){return $GLOBALS['origin_ok'];}
    public static function site_uuid(){return 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';}
    public static function current_environment(){return $GLOBALS['environment'];}
    public static function oauth_user_ids(){return array(1);}
    public static function user_is_enrolled($id){return (int)$id===1;}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    public static function verified_bearer_active(){return $GLOBALS['bearer'];}
}
class MAD4B_SCP_Identity_Context {
    public static function current(){
        return array('authenticated'=>true,'subject_type'=>'oauth',
          'auth_method'=>'oauth2_bearer','subject_fingerprint'=>str_repeat('a',64),
          'client_fingerprint'=>str_repeat('b',64));
    }
}
class MAD4B_SCP_CLI_Execution_Context {
    public static function resolve($op,$mutation){
        return array('local_operation_authorized'=>true,'read_context_verified'=>true,
          'host_site_binding_verified'=>true,'canonical_origin'=>'https://tours.dev.mad4b.com',
          'blockers'=>array());
    }
}
class MAD4B_SCP_Agent_Registry {
    public static function resolve_agent($identity){
        return $GLOBALS['registered']
            ? array('wp_user_id'=>1,'environment'=>'staging') 
            : new WP_Error('mad4b_nhi_subject_unbound');
    }
}
class MAD4B_SCP_Policy {public static function can_breakglass(){return false;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-execution-authority.php';
function check($b,$msg){if(!$b){fwrite(STDERR,"FAIL ".$msg."\n");exit(1);}}
$oauth=MAD4B_SCP_Execution_Authority::resolve('plan');
check(is_array($oauth)&&$oauth['transport']==='oauth_mcp','verified OAuth');
check(!$oauth['execution_authorized']&&!$oauth['grants_created'],'resolver never grants');
$GLOBALS['uid']=2;
check(is_wp_error(MAD4B_SCP_Execution_Authority::resolve('plan')),'unenrolled OAuth rejected');
$GLOBALS['uid']=1;$GLOBALS['origin_ok']=false;
check(is_wp_error(MAD4B_SCP_Execution_Authority::resolve('plan')),'unmatched origin rejected');
$GLOBALS['origin_ok']=true;$GLOBALS['bearer']=false;
check(is_wp_error(MAD4B_SCP_Execution_Authority::resolve('apply')),'CLI --user is not authenticating');
$GLOBALS['uid']=0;
$unbound=MAD4B_SCP_Execution_Authority::resolve('plan');
if(function_exists('posix_geteuid')&&posix_geteuid()===0) {
  check(is_array($unbound)&&$unbound['registry_bound']===false,'unbound host may plan only');
  check(is_wp_error(MAD4B_SCP_Execution_Authority::resolve('apply')),'unbound host may not execute');
} else {
  check(is_wp_error($unbound),'nonroot host cannot plan');
}
$GLOBALS['registered']=true;
$host=MAD4B_SCP_Execution_Authority::resolve('plan');
if(function_exists('posix_geteuid')&&posix_geteuid()===0) {
  check(is_array($host)&&$host['transport']==='host_cli','verified root host');
  check($oauth['canonical_principal']===$host['canonical_principal'],'one logical principal across transports');
} else {
  check(is_wp_error($host),'non-privileged host rejected');
}
$GLOBALS['environment']='production';
check(is_wp_error(MAD4B_SCP_Execution_Authority::require_access('plan',array('staging'))),'staging boundary');
echo "PASS unified execution authority, no new grants or synthetic OAuth\n";
