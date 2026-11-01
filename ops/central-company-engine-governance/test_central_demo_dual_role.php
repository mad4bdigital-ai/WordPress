<?php
/** Isolated dual-role Central Demo: Original Host + independent Company Target. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['host_ledger_valid']=true;
$GLOBALS['host_scope']=[
 'contract'=>'mad4b.central-host-scope.v1',
 'scope_kind'=>'central_native_host','company_target'=>false,
 'owner_enrolled'=>true,'environment'=>'staging',
 'state'=>'CENTRAL_HOST_IDENTITY_VERIFIED',
 'workload'=>'tours-central','host_id'=>'srv2030908',
 'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 'canonical_origin'=>'https://tours.dev.mad4b.com'];
function add_action(...$args){}
function add_filter(...$args){}
function wp_get_environment_type(){return 'staging';}
function home_url($p=''){return 'https://tours.dev.mad4b.com'.($p?'/':'');}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_parse_url($s){return parse_url($s);}
function wp_is_uuid($s){return is_string($s)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$s);}
function get_option($key,$default=false){return [];}
function check($b,$msg){if(!$b){fwrite(STDERR,"FAIL ".$msg.PHP_EOL);exit(1);}}
class MAD4B_Central_Company_REST_V1 {
 public static function directory(){return ['wayfatours'=>[
 'url'=>'https://wayfatours.mad4b.com','name'=>'Wayfa Tours']];}
}
class MAD4B_Native_VPS_Principal_V1 {
 public static function scope(){return $GLOBALS['host_scope'];}
 public static function existing(){return ['principal'=>'signed-native'];}
 public static function ledger($slot,$scope){return $GLOBALS['host_ledger_valid'];}
}
require __DIR__.'/original-broker-source/297-mad4b-company-environment-registry-v2.php';
require __DIR__.'/original-broker-source/298-mad4b-company-execution-resolver.php';
$target=MAD4B_Company_Environment_Registry_V2::target('tours-central','staging');
check(is_array($target),'Demo target from original signed ledger');
check(($target['role']??'')==='self_hosted_demo_target','self hosted Demo role');
check($target['site_uuid']===$GLOBALS['host_scope']['site_uuid'],'UUID binding');
check($target['canonical_origin']===$GLOBALS['host_scope']['canonical_origin'],'origin');
check($target['write_authorized']===false,'no company write from host identity');
check($target['host_authority_reused_for_write']===false,'no host authority reuse');
check($target['identity_evidence']['original_native_host_ledger_verified']===true,'native ledger evidence');
check($target['capabilities']['identity.read']['candidate_available']===true,'identity candidate');
check($target['capabilities']['content.write']['execute_authorized']===false,'write denied');
$p=MAD4B_Company_Execution_Resolver_V1::plan('tours-central','staging','identity.read');
check($p['selected_channel']==='original_native_host_identity','local identity channel');
check($p['execution_allowed']===true && $p['remote_write_permitted']===false,'identity only');
$inspect=MAD4B_Company_Execution_Resolver_V1::plan('tours-central','staging','environment.inspect');
check($inspect['state']==='READY_FOR_LOCAL_INSPECTION','Demo inspection available');
$write=MAD4B_Company_Execution_Resolver_V1::plan('tours-central','staging','content.write');
check($write['execution_allowed']===false && $write['remote_write_permitted']===false,'no automatic write');
$wayfa=MAD4B_Company_Environment_Registry_V2::target('wayfatours','production');
check(is_array($wayfa) && $wayfa['environment']==='production','Production separation');
check(MAD4B_Company_Environment_Registry_V2::target('tours-central','production')===null,'no invented Central Production target');
$GLOBALS['host_ledger_valid']=false;
check(MAD4B_Company_Environment_Registry_V2::target('tours-central','staging')===null,'revoked native proof removes virtual Demo target');
check(MAD4B_Company_Execution_Resolver_V1::plan('tours-central','staging','identity.read')['state']==='TARGET_NOT_ENROLLED','denied if ledger invalid');
$GLOBALS['host_ledger_valid']=true;
$GLOBALS['host_scope']['canonical_origin']='https://foreign.example.com';
check(MAD4B_Company_Environment_Registry_V2::target('tours-central','staging')===null,'cross-origin rejected');
$GLOBALS['host_scope']['canonical_origin']='https://tours.dev.mad4b.com';
$GLOBALS['host_scope']['environment']='production';
check(MAD4B_Company_Environment_Registry_V2::target('tours-central','staging')===null,'cross-environment rejected');
echo "PASS dual-role signed Native Central Host + independently scoped Demo Company identity, no write or Production effect".PHP_EOL;
