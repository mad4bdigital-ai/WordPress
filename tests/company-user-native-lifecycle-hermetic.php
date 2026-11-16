<?php
/**
 * Original WordPress company user lifecycle REST admission and denial tests.
 * No database, WP user/role mutation, deletion, email or external network.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['user_routes']=array();
$GLOBALS['user_hooks']=array();
$GLOBALS['user_company_admin']=false;
$GLOBALS['user_suspended']=array();
$GLOBALS['user_delete_calls']=0;
class WP_User {
 public $ID;public $roles;public $user_login;
 public function __construct($id,$roles,$login='protected_user'){
  $this->ID=$id;$this->roles=$roles;$this->user_login=$login;
 }
}
class WP_Error {
 private $code;private $data;
 public function __construct($code,$message='',$data=array()){
  $this->code=$code;$this->data=$data;
 }
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
 public function add($code,$message) {$this->code=$code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['user_hooks'][$name]=$callback;}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['user_hooks'][$name]=$callback;}
function register_rest_route($namespace,$route,$config){
 $key=trim($namespace,'/').'/'.ltrim($route,'/');
 if(isset($GLOBALS['user_routes'][$key]))throw new RuntimeException('Duplicate REST route');
 $GLOBALS['user_routes'][$key]=$config;
}
function current_user_can($cap){
 return $cap==='mad4b_manage_company_operations' && $GLOBALS['user_company_admin'];
}
function get_current_user_id(){return 1;}
function get_userdata($id){
 if($id===42)return new WP_User($id,array('administrator'),'protected_admin');
 if($id===4)return new WP_User($id,array('editor'),'internal_staff');
 if($id===1)return new WP_User($id,array('master_admin'),'current_owner');
 return false;
}
function get_user_meta($id,$key,$single=false){
 if($key==='mad4b_company_suspension_v1' && isset($GLOBALS['user_suspended'][$id]))
  return array('previous_roles'=>array('editor'));
 if($key==='mad4b_company_team_manager' && $id===4)return 1;
 return false;
}
function wp_delete_user($id){++$GLOBALS['user_delete_calls'];return true;}
function absint($x){return abs((int)$x);}
class MAD4B_SCP_Company_Master_Admin {
 const STAFF_OWNER='mad4b_company_team_manager';
 const ALLOWED_ROLES=array('editor','author','operator','company_translation_manager');
 public static function managedStaff($id){return false;}
 public static function allowedStaff(WP_User $user){return false;}
}
function assert_user($ok,$name){
 if(!$ok)throw new RuntimeException('FAIL USER LIFECYCLE: '.$name);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-user-lifecycle.php';
$c='MAD4B_SCP_Company_User_Lifecycle';
assert_user(isset($GLOBALS['user_hooks']['rest_api_init'])
 && isset($GLOBALS['user_hooks']['rest_authentication_errors'])
 && isset($GLOBALS['user_hooks']['authenticate']),
 'Native REST and suspended login handlers must stay installed');
$c::rest();
$prefix='mad4b/v1/admin/company-team/users/(?P<id>\\d+)';
$expected=array(
 $prefix=>array('GET','PATCH'),
 $prefix.'/preflight'=>array('GET'),
 $prefix.'/lifecycle'=>array('POST'),
);
assert_user(count($GLOBALS['user_routes'])===3,'Exactly 3 original user lifecycle paths');
foreach($expected as $path=>$methods){
 $route=$GLOBALS['user_routes'][$path]??null;
 assert_user(is_array($route),'Missing original route '.$path);
 $rows=isset($route[0])?$route:array($route);
 assert_user(count($rows)===count($methods),'Unexpected method count '.$path);
 foreach($rows as $index=>$r){
  assert_user(($r['methods']??'')===$methods[$index],'Method drift '.$path);
  assert_user(is_callable($r['permission_callback']),'Missing permission '.$path);
  assert_user(call_user_func($r['permission_callback'])===false,
   'Unprivileged actor should not receive user lifecycle access '.$path);
 }
}
assert_user($c::suspend(4) instanceof WP_Error
 && $c::restore(4) instanceof WP_Error
 && $c::preflight(4) instanceof WP_Error
 && $c::permanentDelete(4,'internal_staff') instanceof WP_Error,
 'Direct writes and preflight must reject unauthenticated company manager');
$GLOBALS['user_company_admin']=true;
assert_user($c::permanentDelete(42,'protected_admin')->get_error_code()==='company_user_protected',
 'Even approved company managers cannot delete WordPress administrators');
assert_user($c::permanentDelete(1,'current_owner')->get_error_code()==='company_user_protected',
 'Even approved company managers cannot delete current owner');
assert_user($c::permanentDelete(4,'wrong_name')->get_error_code()==='company_user_confirmation_mismatch',
 'Exact username is required before a permanent delete');
assert_user($GLOBALS['user_delete_calls']===0,
 'No WordPress deletion must happen during unauthorized or mismatched command');
foreach($expected as $path=>$methods)foreach(
 (isset($GLOBALS['user_routes'][$path][0])
  ? $GLOBALS['user_routes'][$path]:array($GLOBALS['user_routes'][$path])) as $r)
 assert_user(call_user_func($r['permission_callback'])===true,
 'Only exact company capability should be sufficient for route admission');
$GLOBALS['user_company_admin']=false;
$GLOBALS['user_suspended'][1]=true;
$deny=$c::guardREST(true);
assert_user($deny instanceof WP_Error
 && $deny->get_error_code()==='company_user_suspended',
 'Suspended staff must be blocked at WordPress REST authentication');
$GLOBALS['user_suspended'][4]=true;
$login=$c::authenticate(get_userdata(4),'internal_staff','placeholder');
assert_user($login instanceof WP_Error
 && $login->get_error_code()==='company_user_suspended',
 'Suspended user cannot authenticate through WordPress');
echo "PASS: COMPANY_USER_LIFECYCLE_ORIGINAL 3 routes / 4 methods, no privileged deletion\n";
