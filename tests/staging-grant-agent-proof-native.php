<?php
/** Original governed-write grant plan: exact OAuth Agent owner and environment. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message=''){ $this->code=$code; }
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return 11;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$v));}
class MAD4B_SCP_Site_Profile {
 public static function current_environment(){return $GLOBALS['scope_env'];}
 public static function user_is_enrolled($id){return $GLOBALS['user_enrolled'] && $id===11;}
}
class MAD4B_SCP_Agent_Registry {
 public static function get_agent_by_slug($slug) {
  return $GLOBALS['canonical_agent'];
 }
 public static function resolve_agent($identity) {
  return $GLOBALS['oauth_agent'];
 }
}
class MAD4B_SCP_Staging_Write_Authority {
 public static function is_exact_delegated_oauth_agent($agent,$id){
  return $GLOBALS['exact_delegated_check'];
 }
}
class MAD4B_SCP_Identity_Context {
 public static function current(){return $GLOBALS['identity'];}
}
function check_source($ok,$name){
 if(!$ok){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-grant-reconciliation-plan.php';
$c='MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan';
$m=new ReflectionMethod($c,'current_agent');
if(PHP_VERSION_ID<80100)$m->setAccessible(true);
$invoke=function()use($m){return $m->invoke(null);};
$GLOBALS['scope_env']='staging';$GLOBALS['user_enrolled']=true;
$GLOBALS['canonical_agent']=[
 'id'=>7,'public_id'=>'original-governed-agent','wp_user_id'=>11,
 'environment'=>'staging','status'=>'enabled','slug'=>'chatgpt-governed-write'
];
$GLOBALS['identity']=[
 'authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer',
 'subject_fingerprint'=>str_repeat('a',64)
];
$GLOBALS['oauth_agent']=$GLOBALS['canonical_agent'];
$GLOBALS['exact_delegated_check']=false;
$allowed=$invoke();
check_source(is_array($allowed)&&$allowed['id']===7,
 'Same enrolled original canonical Agent can be read for a governed plan without any grant write');
$GLOBALS['oauth_agent']['id']=8;
$GLOBALS['oauth_agent']['public_id']='other-agents-token';
check_source(is_wp_error($invoke()),
 'Foreign OAuth Agent is not the canonical governed-write principal');
$GLOBALS['exact_delegated_check']=new WP_Error('delegated_policy_unavailable');
check_source(is_wp_error($invoke()),
 'WP_Error from exact delegated policy is NEVER affirmative OAuth Agent ownership');
foreach(array(null,1,'allow') as $unknown_policy){
 $GLOBALS['exact_delegated_check']=$unknown_policy;
 check_source(is_wp_error($invoke()),
  'Nonboolean delegated policy result cannot be affirmative ownership');
}
$GLOBALS['exact_delegated_check']=true;
$delegated=$invoke();
check_source(is_array($delegated)&&$delegated['id']===7,
 'Explicit true from verified delegated OAuth policy permits read-only canonical plan');
$GLOBALS['exact_delegated_check']=false;
$GLOBALS['oauth_agent']['wp_user_id']=12;
check_source(is_wp_error($invoke()),'Foreign WordPress owner ID cannot inherit a governed Agent');
$GLOBALS['oauth_agent']['wp_user_id']=11;
$GLOBALS['oauth_agent']['status']='disabled';
check_source(is_wp_error($invoke()),'Disabled OAuth Agent cannot be used for reconciliation');
$GLOBALS['oauth_agent']=$GLOBALS['canonical_agent'];
$GLOBALS['identity']['auth_method']='unverified_basic';
check_source(is_wp_error($invoke()),'Bearer authentication method cannot be downgraded');
$GLOBALS['identity']['auth_method']='oauth2_bearer';
$GLOBALS['identity']=new WP_Error('no_trusted_oauth');
check_source(is_wp_error($invoke()),'Unknown original Identity Context denies read plan');
$GLOBALS['identity']=['authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer'];
$GLOBALS['user_enrolled']=false;
check_source(is_wp_error($invoke()),'Unenrolled WordPress principal cannot select canonical Agent');
$GLOBALS['user_enrolled']=true;
$GLOBALS['scope_env']='production';
check_source(is_wp_error($invoke()),'Staging Agent cannot be reused to mint Production plan');
echo 'PASS: STAGING_GRANT_AGENT_PROOF '.($GLOBALS['checks']??0).
 ' original OAuth/owner separation and no-grant assertions'.PHP_EOL;
