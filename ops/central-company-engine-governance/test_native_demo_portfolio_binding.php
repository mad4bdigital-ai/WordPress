<?php
/** Hermetic regression of signed Staging Demo binding and existing manager rights. */
declare(strict_types=1);
define('ABSPATH','/');
define('DB_NAME','etg_commerce_staging');
$GLOBALS['db_options']=[];
$GLOBALS['valid_ledger']=true;
$GLOBALS['demo_scope']=[
 'contract'=>'mad4b.central-host-scope.v1','scope_kind'=>'central_native_host',
 'company_target'=>false,'environment'=>'staging','workload'=>'tours-central',
 'state'=>'CENTRAL_HOST_IDENTITY_VERIFIED',
 'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 'canonical_origin'=>'https://tours.dev.mad4b.com'];
$GLOBALS['demo_target']=[
 'role'=>'self_hosted_demo_target','host_authority_reused_for_write'=>false,
 'write_authorized'=>false,'environment'=>'staging',
 'site_uuid'=>$GLOBALS['demo_scope']['site_uuid'],
 'canonical_origin'=>$GLOBALS['demo_scope']['canonical_origin']];
function wp_get_environment_type(){return 'staging';}
function home_url($path=''){return 'https://tours.dev.mad4b.com'.($path?'/':'');}
function untrailingslashit($v){return rtrim($v,'/');}
function wp_parse_url($v,$what=-1){return parse_url($v,$what);}
function wp_is_uuid($v){return is_string($v)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$v)===1;}
function get_option($key,$fallback=[]){return $GLOBALS['db_options'][$key]??$fallback;}
class WP_Error {
 private string $code;
 public function __construct($code,$message='',$data=null){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_Native_VPS_Principal_V1{
 public static function scope(){return $GLOBALS['demo_scope'];}
 public static function existing(){return ['original_signed_host_ledger'=>true];}
 public static function ledger($p,$s){return $GLOBALS['valid_ledger'];}
}
class MAD4B_Company_Environment_Registry_V2{
 public static function target($code,$env){
  return $code==='tours-central'&&$env==='staging'?$GLOBALS['demo_target']:null;
 }
}
// Evaluate only the class declaration. The WordPress lifecycle boot and
// sibling includes belong to WordPress, not this isolated test.
$path=__DIR__.'/original-broker-source/mad4b-company-portfolio-manager.php';
$source=file_get_contents($path);
$marker='MAD4B_Company_Portfolio_Manager_V1::boot();';
if(!$source||substr_count($source,$marker)!==1)throw new RuntimeException('SOURCE_UNEXPECTED');
eval('?>'.substr($source,0,strpos($source,$marker)));
function ck($ok,$reason){if(!$ok)throw new RuntimeException('FAILED '.$reason);}
$C='MAD4B_Company_Portfolio_Manager_V1';
$b=$C::company_binding('tours-central');
ck(is_array($b),'original Demo binding accepted');
ck($b['company_site_uuid']===$GLOBALS['demo_scope']['site_uuid'],'exact UUID');
ck(preg_match('/^[a-f0-9]{64}$/D',$b['binding_sha256'])===1,'stable scoped fingerprint');
$manager='123e4567-e89b-52d3-a456-426614174000';
$GLOBALS['db_options'][$C::MANAGERS]=[$manager=>['state'=>'registered']];
$GLOBALS['db_options'][$C::MEMBERS]=[$manager.'|26'=>['state'=>'active']];
ck($C::has_delegation($manager,'tours-central','operations_manage',26)===false,'host is not manager delegation');
$GLOBALS['db_options'][$C::ASSIGNMENTS]=[$manager.'|tours-central'=>[
 'state'=>'active','rights'=>['view_company','operations_manage'],
 'company_site_uuid'=>$b['company_site_uuid'],'binding_sha256'=>$b['binding_sha256']]];
ck($C::has_delegation($manager,'tours-central','operations_manage',26)===true,'existing approved manager delegation is honored');
$GLOBALS['db_options'][$C::ASSIGNMENTS][$manager.'|tours-central']['binding_sha256']=str_repeat('a',64);
ck($C::has_delegation($manager,'tours-central','operations_manage',26)===false,'binding drift denied');
$GLOBALS['db_options'][$C::ASSIGNMENTS]=[];
$GLOBALS['valid_ledger']=false;
ck(is_wp_error($C::company_binding('tours-central')),'invalid ledger denied');
$GLOBALS['valid_ledger']=true;
$GLOBALS['demo_target']['write_authorized']=true;
ck(is_wp_error($C::company_binding('tours-central')),'forged write capability denied');
$GLOBALS['demo_target']['write_authorized']=false;
$GLOBALS['demo_scope']['environment']='production';
ck(is_wp_error($C::company_binding('tours-central')),'host production scope denied');
$GLOBALS['demo_scope']['environment']='staging';
$GLOBALS['db_options'][$C::REGISTRY]['tours-central']=['company_code'=>'tours-central','host_binding_verified'=>false];
ck(is_wp_error($C::company_binding('tours-central')),'legacy drift not replaced');
unset($GLOBALS['db_options'][$C::REGISTRY]['tours-central']);
ck(is_wp_error($C::company_binding('arbitrary-company')),'unknown tenant denied');
echo "PASS signed Demo manager binding with separate assignment approval, no implicit rights, revoked and cross-scope denials".PHP_EOL;
