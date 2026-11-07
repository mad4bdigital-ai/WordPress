<?php
/** Extract the real MAD4B Presentation::bound() method into isolated classes.
 * This tests the shipped source body, not a manually copied approximation. */
define('ABSPATH','/');
define('MAD4B_TENANT_ORIGIN','https://wayfatours.com');
define('MAD4B_TENANT_ENVIRONMENT','production');
define('MAD4B_TENANT_SITE_UUID','123e4567-e89b-12d3-a456-426614174000');
$GLOBALS['site_profile_uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['opts']=[
 'home'=>'https://wayfatours.com',
 'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>MAD4B_TENANT_SITE_UUID]
];
$GLOBALS['resolved_home']='https://wayfatours.com/es/';
$GLOBALS['env']='production';$GLOBALS['home_calls']=0;
function get_option($name,$fallback=null){return $GLOBALS['opts'][$name]??$fallback;}
function home_url($path='/'){$GLOBALS['home_calls']++;return $GLOBALS['resolved_home'];}
function wp_parse_url($v,$part=-1){return parse_url($v,$part);}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_is_uuid($v){return (bool)preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD',(string)$v);}
class MAD4B_SCP_Site_Profile{public static function site_uuid(){return $GLOBALS['site_profile_uuid'];}}
function check($name,$pass){if(!$pass)throw new RuntimeException("FAIL $name");echo "PASS $name\n";}
$source=getenv('MAD4B_PRESENTATION_BOOTSTRAP');
if (!$source || !is_file($source))throw new RuntimeException('Source missing');
$text=file_get_contents($source);
$start=strpos($text,' public static function bound():bool {');
$end=strpos($text,"\n public static function site_uuid():string {",$start);
if($start===false || $end===false || $end<=$start)throw new RuntimeException('Bound method not found');
$method=substr($text,$start,$end-$start);
function run_bound($class,$expect){
 global $method;
 eval("final class $class { $method }");
 $result=$class::bound();
 check($class,$result===$expect);
}
run_bound('SpanishCanonical',true);
check('WPML home_url deliberately avoided',$GLOBALS['home_calls']===0);
$GLOBALS['resolved_home']='https://wayfatours.com/';
run_bound('EnglishCanonical',true);
$GLOBALS['opts']['home']='https://other-company.example';
run_bound('WrongCompany',false);
$GLOBALS['opts']['home']='http://wayfatours.com';
run_bound('NoHttpsOnProduction',false);
$GLOBALS['opts']['home']='https://wayfatours.com';
$GLOBALS['env']='staging';
run_bound('EnvironmentMismatch',false);
$GLOBALS['env']='production';
$GLOBALS['opts']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>'not-same'];
run_bound('ProfileUUIDMismatch',false);
$GLOBALS['opts']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>MAD4B_TENANT_SITE_UUID];
$GLOBALS['site_profile_uuid']='123e4567-e89b-12d3-a456-426614174001';
run_bound('RegistryUUIDMismatch',false);
$GLOBALS['site_profile_uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['opts']['home']='';
$GLOBALS['resolved_home']='https://wayfatours.com/es/';
run_bound('MissingCanonicalFailsClosed',false);
check('fallback URL used only when canonical missing',$GLOBALS['home_calls']===1);
echo "PASS CANONICAL_ORIGIN_WPML_BIND_CONTRACT\n";
