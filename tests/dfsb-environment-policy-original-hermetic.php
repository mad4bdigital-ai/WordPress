<?php
/** Original first-party DFSB environment policy, hermetic read-only source. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 function __construct($code='unavailable'){}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
class MAD4B_SCP_Environment {
 static $value='staging';
 static function effective(){return self::$value;}
}
function get_option($key,$fallback=array()){return $GLOBALS['policy_options'][$key]??$fallback;}
function check_dfsb($condition,$why){
 if(!$condition){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
// Load the actual shared route registry; exercise WordPress hook scheduling
// without installing a menu, database, or issuing permission grants.
$GLOBALS['hook_schedule']=array();
function add_action($name,$callback,$priority=10){
 $GLOBALS['hook_schedule'][]=array('hook'=>$name,'callback'=>$callback,'priority'=>$priority);
}
function admin_url($path=''){return 'https://example.invalid/wp-admin/'.$path;}
function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-admin-route-registry.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-dfsb-environment-policy.php';
$c='MAD4B_SCP_DFSB_Environment_Policy';
$c::boot();
$routes=MAD4B_SCP_Admin_Route_Registry::routes();
check_dfsb(isset($routes['mad4b-dfsb-environments'])
 && $routes['mad4b-dfsb-environments']['required_capability']==='manage_options',
 'DFSB admin route must have exact registered capability and canonical slug');
$adminHooks=array_values(array_filter($GLOBALS['hook_schedule'],function($event){return $event['hook']==='admin_menu';}));
check_dfsb(count($adminHooks)===1 && $adminHooks[0]['priority']===92
 && $adminHooks[0]['callback']===array($c,'menu'),
 'DFSB submenu scheduled once after parent using route registry');
check_dfsb(count($GLOBALS['hook_schedule'])===2
 && $GLOBALS['hook_schedule'][1]['hook']==='admin_post_mad4b_scp_dfsb_policy_save',
 'Save action remains unchanged; boot itself performs no write');
$GLOBALS['policy_options']=array();
check_dfsb(!$c::allows('publication'),'Unconfigured Site Profile never permits DFSB publishing');
$sha=str_repeat('a',40);
$GLOBALS['policy_options'][$c::OPTION]=[
 'staging'=>['publication'=>true,'managed_routes'=>true,
 'native_local_boot'=>true,'native_local_source_sha'=>$sha],
 'production'=>['publication'=>false,'managed_routes'=>false,
 'native_local_boot'=>false,'native_local_source_sha'=>str_repeat('b',40)]
];
check_dfsb($c::allows('publication') && $c::allows('managed_routes'),
 'Exact Site Profile policy flags permit the selected staging capabilities');
check_dfsb($c::localPackageApproved($sha),
 'Only the SHA bound to the exact source profile may boot');
foreach(['unknown','publication_all','production','__proto__'] as $feature){
 check_dfsb(!$c::allows($feature),
 'Unsupported or spoofed feature must not be a policy grant: '.$feature);
}
check_dfsb($c::localPackageApproved('A'.substr($sha,1)),
 'Equivalent uppercase hexadecimal input canonicalizes to the identical SHA');
foreach(['',str_repeat('b',40),substr($sha,0,39),$sha.'x'] as $invalid){
 check_dfsb(!$c::localPackageApproved($invalid),
 'Source digest drift cannot satisfy exact current Site Profile source');
}
MAD4B_SCP_Environment::$value='production';
check_dfsb(!$c::allows('publication')&&!$c::localPackageApproved($sha),
 'Independent production settings never inherit staging');
MAD4B_SCP_Environment::$value='development';
check_dfsb(!$c::allows('publication')&&!$c::localPackageApproved($sha),
 'Unconfigured environment cannot inherit other stage');
MAD4B_SCP_Environment::$value='staging';
foreach([false,null,1,new WP_Error('environment_unknown')] as $invalid_env){
 MAD4B_SCP_Environment::$value=$invalid_env;
 check_dfsb($c::environment()==='' && !$c::allows('publication'),
 'Invalid Site Profile environment must be a denied state');
}
MAD4B_SCP_Environment::$value='staging';
foreach([new WP_Error('database_unknown'),false,'unexpected'] as $bad_options){
 $GLOBALS['policy_options'][$c::OPTION]=$bad_options;
 check_dfsb(!$c::allows('native_local_boot')&&!$c::localPackageApproved($sha),
 'Malformed persisted policy cannot grant exact source boot');
}
$GLOBALS['policy_options'][$c::OPTION]=[
 'staging'=>['publication'=>new WP_Error('invalid'), 'native_local_boot'=>1,
 'managed_routes'=>'true','native_local_source_sha'=>$sha]];
check_dfsb(!$c::allows('publication')&&!$c::allows('managed_routes')
 &&!$c::localPackageApproved($sha),'Truthy/error-shaped options never grant publication or boot');
$view=$c::snapshot();
check_dfsb($view['authorizing']===false && $view['publication']===false
 && $view['native_local_boot']===false,
 'Policy snapshot cannot itself authorize a mutation');
echo 'PASS: DFSB_ENVIRONMENT_POLICY '.($GLOBALS['cases']??0).
 ' native policy gating and source SHA cases'.PHP_EOL;
