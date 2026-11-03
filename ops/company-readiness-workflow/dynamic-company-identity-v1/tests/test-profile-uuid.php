<?php
define('ABSPATH','/');function add_action(...$x) {}
function wp_is_uuid($x){return is_string($x)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$x);}
class MAD4B_Environment_Signed_Identity_V4 {
 static $valid=true;static $uuid='49c562d1-8f2f-456f-b454-26816c6ba4cb';
 static function status($c,$e){return ['centrally_verified'=>self::$valid,'company'=>$c,
 'environment'=>$e,'canonical_origin'=>'https://staging.allroyalegypt.com','site_uuid'=>self::$uuid];}
}
require dirname(__DIR__).'/297-staging-profile-uuid-candidate.php';
$ref=new ReflectionMethod('MAD4B_Company_Environment_Registry_V2','staging_entry');
$input=['company_id'=>'all-royal-egypt','environment'=>'staging',
 'canonical_origin'=>'https://staging.allroyalegypt.com',
 'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb','native_profile_digest'=>str_repeat('a',64),
 'source'=>'authenticated_mcp_site_profile_status'];
function check($b,$m){if(!$b){fwrite(STDERR,'FAIL '.$m."\n");exit(2);}echo 'PASS '.$m."\n";}
$v=$ref->invoke(null,'all-royal-egypt',$input);
check($v['profile_uuid']===$input['site_uuid'],'signed exact staging profile UUID projected');
check($v['write_authorized']===false,'profile proof never grants write');
check($v['capabilities']['plugin.install']['execute_authorized']===false,'plugin authorization still false');
MAD4B_Environment_Signed_Identity_V4::$valid=false;
$v=$ref->invoke(null,'all-royal-egypt',$input);
check($v['profile_uuid']===null,'unverified signed profile not promoted');
MAD4B_Environment_Signed_Identity_V4::$valid=true;
MAD4B_Environment_Signed_Identity_V4::$uuid='11111111-1111-1111-1111-111111111111';
$v=$ref->invoke(null,'all-royal-egypt',$input);
check($v['profile_uuid']===null,'site UUID mismatch not promoted');
echo "READONLY_PROFILE_UUID_CONTRACT_PASS\n";
