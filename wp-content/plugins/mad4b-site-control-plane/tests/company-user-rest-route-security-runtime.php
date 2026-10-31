<?php
/** QA31 hermetic REST-route security contract; no WordPress DB or writes. */
declare(strict_types=1);
define('ABSPATH', '/qa31-isolated/');
$GLOBALS['qa31_routes'] = [];
$GLOBALS['qa31_authorized'] = false;
$GLOBALS['qa31_user_id'] = 5;
$GLOBALS['qa31_users'] = [
  7 => (object)['ID'=>7,'roles'=>['administrator'],'user_login'=>'admin'],
  8 => (object)['ID'=>8,'roles'=>['subscriber'],'user_login'=>'staff'],
];
final class WP_Error {
  private string $code;
  public function __construct(string $code, string $message, array $data=[]) {$this->code=$code;}
  public function get_error_code(): string {return $this->code;}
}
final class MAD4B_SCP_Company_Master_Admin {
  const STAFF_OWNER = 'qa31_staff_owner';
  const ALLOWED_ROLES = ['subscriber'];
  public static function managedStaff(int $id): bool {return false;}
}
function add_filter(...$args): void {}
function add_action(...$args): void {}
function register_rest_route($namespace,$route,$args): void {
  $GLOBALS['qa31_routes'][$namespace.$route]=$args;
}
function current_user_can($cap): bool {
  if ($cap !== 'mad4b_manage_company_operations') throw new RuntimeException('unexpected capability');
  return $GLOBALS['qa31_authorized'];
}
function get_current_user_id(): int {return $GLOBALS['qa31_user_id'];}
function get_userdata($id) {return $GLOBALS['qa31_users'][$id]??false;}
function get_user_meta($id,$key,$single=true) {return null;}
function is_wp_error($value): bool {return $value instanceof WP_Error;}
function absint($value): int {return abs((int)$value);}
function qa31_assert($cond,$label): void {if (!$cond) throw new RuntimeException($label);}
require dirname(__DIR__).'/includes/class-mad4b-scp-company-user-lifecycle.php';
MAD4B_SCP_Company_User_Lifecycle::rest();
$prefix='mad4b/v1/admin/company-team/users/(?P<id>\\d+)';
$routes=$GLOBALS['qa31_routes'];
qa31_assert(count($routes)===3,'unexpected route count');
$profile=$routes[$prefix]??null;
$preflight=$routes[$prefix.'/preflight']??null;
$lifecycle=$routes[$prefix.'/lifecycle']??null;
qa31_assert(is_array($profile) && count($profile)===2,'missing profile methods');
qa31_assert(is_array($preflight) && is_array($lifecycle),'missing lifecycle routes');
$methods=[
  [$profile[0],'GET','profile'],
  [$profile[1],'PATCH','profile'],
  [$preflight,'GET','preflightREST'],
  [$lifecycle,'POST','route'],
];
foreach($methods as [$def,$method,$callback]) {
  qa31_assert(($def['methods']??null)===$method,'method drift');
  qa31_assert(($def['callback']??null)===[MAD4B_SCP_Company_User_Lifecycle::class,$callback],'handler drift');
  qa31_assert(is_callable($def['permission_callback']??null),'missing permission callback');
  $GLOBALS['qa31_authorized']=false;
  qa31_assert($def['permission_callback']()===false,'unauthorized request admitted');
  $GLOBALS['qa31_authorized']=true;
  qa31_assert($def['permission_callback']()===true,'authorized request rejected');
}
qa31_assert(($lifecycle['args']['action']['required']??false)===true,'action requirement missing');
qa31_assert(($lifecycle['args']['action']['enum']??[])===['suspend','restore','delete'],'action allowlist drift');
$GLOBALS['qa31_authorized']=false;
foreach(['preflight'=>fn()=>MAD4B_SCP_Company_User_Lifecycle::preflight(8),
          'suspend'=>fn()=>MAD4B_SCP_Company_User_Lifecycle::suspend(8),
          'restore'=>fn()=>MAD4B_SCP_Company_User_Lifecycle::restore(8),
          'delete'=>fn()=>MAD4B_SCP_Company_User_Lifecycle::permanentDelete(8,'staff')] as $label=>$call) {
  $result=$call();
  qa31_assert($result instanceof WP_Error && $result->get_error_code()==='company_user_forbidden',$label.' permission bypass');
}
$GLOBALS['qa31_authorized']=true;
foreach([5,7,9] as $id) {
  $result=MAD4B_SCP_Company_User_Lifecycle::preflight($id);
  qa31_assert($result instanceof WP_Error && $result->get_error_code()==='company_user_protected',$id.' protected-user denial missing');
}
echo "QA31_COMPANY_USER_REST_SECURITY: PASS\n";
