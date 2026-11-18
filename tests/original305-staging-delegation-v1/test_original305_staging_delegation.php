<?php
declare(strict_types=1);
/** Hermetic regression: Staging proof is not a Production delegation. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['mad4b_test_store'] = [
 'managers' => ['manager-1' => ['state'=>'registered']],
 'members' => ['manager-1|27' => ['state'=>'active']],
 'assignments' => ['manager-1|all-royal-egypt'=>[
  'state'=>'active','manager_id'=>'manager-1','company_code'=>'all-royal-egypt',
  'company_site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  'binding_sha256'=>str_repeat('a',64),'rights'=>['view_company','operations_manage']
 ]]
];
function get_option($name,$default=[]) { return $GLOBALS['mad4b_test_store'][$name]??$default; }
final class MAD4B_Company_Portfolio_Manager_V1 {
 const MANAGERS='managers';const MEMBERS='members';const ASSIGNMENTS='assignments';
 public static bool $staging=true;
 public static function staging():bool {return self::$staging;}
 public static function has_delegation(string $m,string $c,string $r,int $u):bool {
  return false; // Production owner does not inherit Staging rights
 }
}
final class MAD4B_Company_Environment_Registry_V2 {
 public static array $site=[
  'company_id'=>'all-royal-egypt','environment'=>'staging',
  'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  'canonical_origin'=>'https://staging.allroyalegypt.com'
 ];
 public static function target(string $c,string $e):array {
  return self::$site;
 }
}
final class MAD4B_Company_Environment_Portfolio_Evidence_V1 {
 public static array $proof=[
  'contract'=>'mad4b.company-environment-portfolio-evidence.v1',
  'company'=>'all-royal-egypt','environment'=>'staging',
  'signed_identity_verified'=>true,'portfolio_assignment_bound'=>true,
  'operations_delegation_approved'=>true,'grant_issued'=>false,
  'production_mutation_allowed'=>false,
  'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  'canonical_origin'=>'https://staging.allroyalegypt.com'
 ];
 public static function readback(string $c,string $e):array {
  return self::$proof+['portfolio_binding_sha256'=>str_repeat('a',64)];
 }
}
require dirname(__DIR__,2).'/wp-content/plugins/mad4b-platform/modules/authority/326-mad4b-original-remote-hostinger-305.php';
$method=(new ReflectionClass('MAD4B_Original_Remote_Hostinger_305_V1'))
    ->getMethod('stagingOperationsDelegated');
$assertions=0;
$test=function(bool $expected,int $user=27,string $company='all-royal-egypt',string $environment='staging') use($method,&$assertions):void {
 $actual=$method->invoke(null,'manager-1',$company,$environment,$user);
 if($actual!==$expected){throw new RuntimeException("Staging delegated assertion ".($assertions+1)." failed");}
 ++$assertions;
};
$test(true);
$test(false,99);
$test(false,27,'egypt-tour-gates');
$test(false,27,'all-royal-egypt','production');
MAD4B_Company_Environment_Portfolio_Evidence_V1::$proof['signed_identity_verified']=false;
$test(false);
MAD4B_Company_Environment_Portfolio_Evidence_V1::$proof['signed_identity_verified']=true;
MAD4B_Company_Environment_Portfolio_Evidence_V1::$proof['production_mutation_allowed']=true;
$test(false);
MAD4B_Company_Environment_Portfolio_Evidence_V1::$proof['production_mutation_allowed']=false;
MAD4B_Company_Environment_Portfolio_Evidence_V1::$proof['portfolio_assignment_bound']=false;
$test(false);
MAD4B_Company_Environment_Portfolio_Evidence_V1::$proof['portfolio_assignment_bound']=true;
$GLOBALS['mad4b_test_store']['members']['manager-1|27']['state']='revoked';
$test(false);
$GLOBALS['mad4b_test_store']['members']['manager-1|27']['state']='active';
$GLOBALS['mad4b_test_store']['assignments']['manager-1|all-royal-egypt']['state']='revoked';
$test(false);
$GLOBALS['mad4b_test_store']['assignments']['manager-1|all-royal-egypt']['state']='active';
$GLOBALS['mad4b_test_store']['assignments']['manager-1|all-royal-egypt']['binding_sha256']=str_repeat('b',64);
$test(false);
$GLOBALS['mad4b_test_store']['assignments']['manager-1|all-royal-egypt']['binding_sha256']=str_repeat('a',64);
$GLOBALS['mad4b_test_store']['assignments']['manager-1|all-royal-egypt']['rights']=['view_company'];
$test(false);
$GLOBALS['mad4b_test_store']['assignments']['manager-1|all-royal-egypt']['rights']=['view_company','operations_manage'];
MAD4B_Company_Portfolio_Manager_V1::$staging=false;
$test(false);
echo json_encode(['state'=>'PASS','tests'=>$assertions,'production_rights_inherited'=>false,
 'source_scope'=>'staging_only','grant_created'=>false],JSON_UNESCAPED_SLASHES)."\n";
