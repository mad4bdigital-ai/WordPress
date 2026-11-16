<?php
/** Original Company Master Admin REST and tenant-bound actor fences. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['company_grants']=array();
$GLOBALS['company_routes']=array();
$GLOBALS['company_actions']=array();
$GLOBALS['company_mutations']=0;
$GLOBALS['company_env']='production';
class WP_Error {
 private $error;private $data;
 public function __construct($name,$message='',$data=array()){$this->error=$name;$this->data=$data;}
 public function get_error_code(){return $this->error;}
 public function get_error_data(){return $this->data;}
}
class WP_User {}
function add_action($name,$callback,$priority=10){
 $GLOBALS['company_actions'][$name]=$callback;
}
function register_rest_route($namespace,$route,$config){
 $key=trim($namespace,'/').'/'.ltrim($route,'/');
 if(isset($GLOBALS['company_routes'][$key]))throw new RuntimeException('Duplicate company route');
 $GLOBALS['company_routes'][$key]=$config;
}
function current_user_can($cap){return !empty($GLOBALS['company_grants'][$cap]);}
function wp_get_environment_type(){return $GLOBALS['company_env'];}
function get_option($name,$default=null){return $default;}
function wp_insert_user($request){$GLOBALS['company_mutations']++;throw new RuntimeException('Forbidden create');}
function verify_company($ok,$name){if(!$ok)throw new RuntimeException('FAIL COMPANY: '.$name);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-master-admin.php';
$c='MAD4B_SCP_Company_Master_Admin';
verify_company(isset($GLOBALS['company_actions']['rest_api_init'])
 && isset($GLOBALS['company_actions']['admin_post_mad4b_company_staff_operation']),
 'Original plugin must own REST and form actions');
$c::routes();
$prefix='mad4b/v1/admin/company-team';
$expected=array(
 $prefix=>'GET',
 $prefix.'/create'=>'POST',
 $prefix.'/translation-manager'=>'POST',
 $prefix.'/assign-task'=>'POST',
);
verify_company(count($GLOBALS['company_routes'])===4,'Exactly four Company REST interfaces');
foreach($expected as $route=>$method){
 $cfg=$GLOBALS['company_routes'][$route]??null;
 verify_company(is_array($cfg),'Missing original REST '.$route);
 verify_company($cfg['methods']===$method,'GET/POST privilege split incorrect');
 verify_company($cfg['permission_callback']===array($c,'can'),
  'Missing exact company permission '.$route);
 verify_company(call_user_func($cfg['permission_callback'])===false,
  'Anonymous tenant permission unexpectedly allowed '.$route);
}
verify_company($c::createStaff(array()) instanceof WP_Error,
 'Guest must not create WordPress staff');
verify_company($c::setTranslationManager(1,true) instanceof WP_Error,
 'Guest must not elevate WPML translations');
verify_company($c::assignTask(1,2) instanceof WP_Error,
 'Guest must not assign board tasks');
verify_company($GLOBALS['company_mutations']===0,'No side effects without permission');
$GLOBALS['company_grants'][$c::CAP]=true;
verify_company($c::can()===true && current_user_can('manage_options')===false,
 'Company capability must not silently elevate WP administrator rights');
foreach($expected as $route=>$method)verify_company(
 call_user_func($GLOBALS['company_routes'][$route]['permission_callback'])===true,
 'Exact capability should suffice for tenant operations '.$route);
$GLOBALS['company_grants']=array();
// Even a manually attempted role migration must fail on Production.
try{$c::applyOwnerApprovedMigration();throw new RuntimeException('Production migration bypass');}
catch(RuntimeException $error){
 verify_company($error->getMessage()!=='Production migration bypass',
  'Production role migration must be closed');
}
$GLOBALS['company_env']='staging';
try{$c::applyOwnerApprovedMigration();throw new RuntimeException('Staging owner bypass');}
catch(RuntimeException $error){
 verify_company($error->getMessage()!=='Staging owner bypass',
  'Staging setup requires original browser owner approval');
}
verify_company($GLOBALS['company_mutations']===0,'No WordPress user created in source-only test');
echo "PASS: COMPANY_MASTER_REST_NATIVE_GATES four tenant-owned endpoints and owner-only migration\n";
