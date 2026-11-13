<?php
declare(strict_types=1);
define('ABSPATH','/');
define('WP_ENVIRONMENT_TYPE','staging');
$GLOBALS['native']='staging';
$GLOBALS['authoritative']=true;
$GLOBALS['uuid']='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$GLOBALS['stored']='https://tours.dev.mad4b.com';
$GLOBALS['current']='https://tours.dev.mad4b.com';
function wp_get_environment_type(){return $GLOBALS['native'];}
function add_filter($name,$fn,$priority=10,$args=1){}
class WP_Error {
 public $code; public $data;
 function __construct($code,$msg,$data=[]){$this->code=$code;$this->data=$data;}
}
class MAD4B_SCP_Site_Profile {
 public static function environment_resolution(){
  return ['profile_environment_authoritative'=>$GLOBALS['authoritative'],
          'wordpress_profile_mismatch'=>false,'effective_environment'=>'staging'];}
 public static function current_environment(){return 'staging';}
 public static function site_uuid(){return $GLOBALS['uuid'];}
 public static function site_origin(){return $GLOBALS['stored'];}
 public static function current_origin(){return $GLOBALS['current'];}
}
require dirname(__DIR__).'/015-mad4b-runtime-admission.php';
function check($condition,$name){
 if(!$condition){fwrite(STDERR,"FAILED ".$name."\n");exit(2);}
 echo "PASS ".$name."\n";
}
class TestRequest{
 function get_method(){return 'POST';}
 function get_route(){return '/mad4b-central/v1/company-environments/all-royal-egypt/staging/manager-assignment';}
}
check(!class_exists('MAD4B_SCP_Environment',false),'Facade unloaded as in FPM');
check(MAD4B_Runtime_Admission::environment_status()['verified']===true,
  'Authoritative Site Profile fallback permits environment readiness');
check(MAD4B_Runtime_Admission::rest_write(null,null,new TestRequest())===null,
  'Original REST handler remains responsible for owner authorization');
$GLOBALS['authoritative']=false;
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
  'Non-authoritative profile rejected');
check(MAD4B_Runtime_Admission::rest_write(null,null,new TestRequest()) instanceof WP_Error,
  'Unverified host still receives 423');
$GLOBALS['authoritative']=true;
$GLOBALS['uuid']='';
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
  'Missing Site UUID rejected');
$GLOBALS['uuid']='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$GLOBALS['current']='https://wrong.example';
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
  'Mismatched origin rejected');
$GLOBALS['current']='https://tours.dev.mad4b.com';
$GLOBALS['native']='production';
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
  'Native production mismatch rejected');
$GLOBALS['native']='staging';
// No facade in this FPM variant.

echo "RUNTIME_ADMISSION_FPM_SOURCE_TESTS_PASS\n";
