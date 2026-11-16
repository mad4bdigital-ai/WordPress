<?php
/**
 * Source-owned company communication, sender and operations REST fences.
 * Exercises native WordPress route registrations, no messages sent or edits.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['tenant_hooks']=array();
$GLOBALS['tenant_routes']=array();
$GLOBALS['tenant_perm']=false;
$GLOBALS['tenant_writes']=0;
function add_action($name,$callback,$priority=10,$argc=1){
 $GLOBALS['tenant_hooks'][$name][]=$callback;
}
function add_filter($name,$callback,$priority=10,$argc=1){
 $GLOBALS['tenant_hooks'][$name][]=$callback;
}
function register_rest_route($ns,$route,$cfg){
 $id=trim($ns,'/').'/'.ltrim($route,'/');
 $GLOBALS['tenant_routes'][$id][]=$cfg;
}
function current_user_can($cap){return $cap==='mad4b_manage_company_operations' && $GLOBALS['tenant_perm'];}
function get_option($key,$default=array()){return $default;}
function update_option($key,$value,$autoload=false){++$GLOBALS['tenant_writes'];throw new RuntimeException('Unexpected option write');}
class WP_Error {
 public function __construct($c,$message='',$data=array()){}
}
function check_company_rest($cond,$reason){
 if(!$cond)throw new RuntimeException('FAIL COMPANY COMMUNICATION REST: '.$reason);
}
$inc=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $inc.'class-mad4b-scp-company-communication.php';
require $inc.'class-mad4b-scp-company-communication-team.php';
require $inc.'class-mad4b-scp-company-sender-policy.php';
require $inc.'class-mad4b-scp-company-operations-policy.php';
check_company_rest(count($GLOBALS['tenant_hooks']['rest_api_init']??array())>=4,
 'Original four company source classes must register their REST handlers');
foreach($GLOBALS['tenant_hooks']['rest_api_init'] as $cb)call_user_func($cb);
$expected=array(
 'mad4b/v1/admin/company-communication'=>array('GET'),
 'mad4b/v1/admin/company-communication/preview'=>array('POST'),
 'mad4b/v1/admin/company-communication/team'=>array('GET'),
 'mad4b/v1/admin/company-communication/users'=>array('GET'),
 'mad4b/v1/admin/company-sender-policy'=>array('GET','PATCH'),
 'mad4b/v1/admin/company-sender-policy/preview'=>array('POST'),
 'mad4b/v1/admin/company-policies'=>array('GET','PATCH'),
);
check_company_rest(count($GLOBALS['tenant_routes'])===7,
 'Unexpected dynamic native company endpoint count');
foreach($expected as $route=>$methods){
 $roots=$GLOBALS['tenant_routes'][$route]??array();
 $rows=array();
 foreach($roots as $r) {
    if(isset($r['methods']))$rows[]=$r;
    else foreach($r as $sub)if(isset($sub['methods']))$rows[]=$sub;
 }
 check_company_rest(count($rows)===count($methods),
  'Unexpected method/route registration count at '.$route);
 $actual=array();
 foreach($rows as $row) {
    $actual[]=$row['methods'];
    check_company_rest(isset($row['callback']) && is_callable($row['callback']),
      'Missing actual WordPress provider callback on '.$route);
    check_company_rest(isset($row['permission_callback'])
      && is_callable($row['permission_callback']),
      'Missing original scoped permission callback on '.$route);
    check_company_rest(call_user_func($row['permission_callback'])===false,
      'Unenrolled user was given company settings/email/operations permission '.$route);
    $GLOBALS['tenant_perm']=true;
    check_company_rest(call_user_func($row['permission_callback'])===true,
      'Authorized company-only capability should admit scoped route '.$route);
    $GLOBALS['tenant_perm']=false;
 }
 sort($actual);sort($methods);
 check_company_rest($actual===$methods,'Unexpected HTTP methods on '.$route);
}
$policy=MAD4B_SCP_Company_Operations_Policy::get();
check_company_rest(($policy['hard_delete_policy']??'')==='disabled',
 'Hard deletion must be disabled by default without an approved tenant policy');
check_company_rest($GLOBALS['tenant_writes']===0,'Registration must never send email or change company settings');
echo "PASS: COMPANY_SETTINGS_COMMUNICATION_REST 7 endpoints and 9 scoped HTTP methods\n";
