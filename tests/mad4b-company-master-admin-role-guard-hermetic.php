<?php
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['environment']='production';$GLOBALS['site_origin']='https://wayfatours.com';
$GLOBALS['site']=['configured'=>true,'deployment_binding_match'=>true,'origin_match'=>true,'environment_match'=>true,
'environment'=>'production','site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c','canonical_origin'=>'https://wayfatours.com'];
class RoleMock {
 public array $capabilities;
 public function __construct(array $caps){$this->capabilities=$caps;}
 public function has_cap($cap):bool{return !empty($this->capabilities[$cap]);}
 public function remove_cap($cap):void{unset($this->capabilities[$cap]);}
 public function add_cap($cap,$grant=true):void{throw new RuntimeException('NEVER GRANT');}
}
$GLOBALS['master']=new RoleMock(['mad4b_manage_company_operations'=>true,'manage_woocommerce'=>true,
'edit_theme_options'=>true,'upload_files'=>true]);
function add_action(...$args){}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function home_url($path='/'){return $GLOBALS['site_origin'].'/';}
function untrailingslashit($value){return rtrim($value,'/');}
function wp_is_uuid($value){return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$value);}
function get_role($name){return $name==='master_admin'?$GLOBALS['master']:null;}
class MAD4B_SCP_Site_Profile { public static function status(){return $GLOBALS['site'];}}
require dirname(__DIR__).'/wp-content/mu-plugins/501-mad4b-company-role-reduction-guard.php';
$c='MAD4B_Company_Role_Reduction_Guard_V1';$passed=0;
$assert=function($ok,$msg)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$msg);$passed++;};
$x=$c::enforce_denied_master_caps();
$assert($x['removed']===2,'reduces forbidden only');
$assert($GLOBALS['master']->has_cap('mad4b_manage_company_operations'),'company management retained');
$assert($GLOBALS['master']->has_cap('upload_files'),'ordinary allowed capability retained');
$assert(!$GLOBALS['master']->has_cap('edit_theme_options'),'theme edit denied');
$assert(!$GLOBALS['master']->has_cap('manage_woocommerce'),'woocommerce settings denied');
$assert($c::enforce_denied_master_caps()['removed']===0,'idempotent on healthy company');
foreach(['configured','deployment_binding_match','origin_match','environment_match'] as $key){
 $GLOBALS['master']->capabilities['manage_options']=true;
 $GLOBALS['site'][$key]=false;
 $assert($c::enforce_denied_master_caps()['removed']===0&&$GLOBALS['master']->has_cap('manage_options'),'binding gate '.$key);
 $GLOBALS['site'][$key]=true;
 unset($GLOBALS['master']->capabilities['manage_options']);
}
$GLOBALS['master']->capabilities['edit_theme_options']=true;
$GLOBALS['site']['site_uuid']='bad';
$assert($c::enforce_denied_master_caps()['removed']===0,'uuid gate');
$GLOBALS['site']['site_uuid']='a3a7cc36-db7e-4a14-8456-b41857def05c';
$GLOBALS['site']['canonical_origin']='https://another.example';
$assert($c::enforce_denied_master_caps()['removed']===0,'origin mismatch denied');
$GLOBALS['site']['canonical_origin']='https://wayfatours.com';
$GLOBALS['environment']='local';
$assert($c::enforce_denied_master_caps()['removed']===0,'local full migration unaffected');
$GLOBALS['environment']='production';
$GLOBALS['site']['environment']='staging';
$assert($c::enforce_denied_master_caps()['removed']===0,'environment mismatch denied');
$GLOBALS['site']['environment']='production';
$assert($c::enforce_denied_master_caps()['removed']===1,'reintroduced theme capability automatically removed');
$assert($c::enforce_denied_master_caps()['capabilities_granted']===0,'never grants a capability');
$assert(count($GLOBALS['master']->capabilities)===2,'only existing allowed capabilities remain');
echo json_encode(['contract'=>'mad4b.company-master-admin.reducing-only-role-guard.v1','passed'=>$passed,
'grants'=>0,'job_submitted'=>false,'roles_recreated'=>false]),"\n";
