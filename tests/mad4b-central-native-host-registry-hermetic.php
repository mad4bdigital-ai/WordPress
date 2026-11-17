<?php
declare(strict_types=1);
define('ABSPATH','/');
define('WP_CLI',true);
define('DB_NAME','etg_commerce_staging');
class WP_Error {
    private $code;
    function __construct($code,$message=''){ $this->code=$code; }
    function get_error_code(){ return $this->code; }
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['actor'];}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function home_url($a='/'){return $GLOBALS['origin'].'/';}
function untrailingslashit($x){return rtrim($x,'/');}
function wp_is_uuid($x){return (bool)preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',(string)$x);}
function get_option($k,$d=array()){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
class DB {
 function prepare($s,$arg){return str_replace('%s',"'" . $arg . "'",$s);}
 function get_var($q){return strpos($q,'GET_LOCK')!==false?1:1;}
}
class MAD4B_Central_Company_REST_V1 {
 public static function directory(){return [
 'egypt-tour-gates'=>['url'=>'https://egypttourgates.com'],
 'tourism-almallahgroup'=>['url'=>'https://tourism.almallahgroup-mg.com']];}
}
class MAD4B_SCP_Audit {
 public static function storage_status(){return ['ready'=>$GLOBALS['audit_ready']];}
 public static function record($event,$data,$status='ok'){
  $GLOBALS['audit_calls']++;
  $GLOBALS['last_audit']=$data;
  return $GLOBALS['audit_fail']?new WP_Error('simulated_audit_failure'):true;
 }
}
$GLOBALS['wpdb']=new DB();$GLOBALS['actor']=0;$GLOBALS['environment']='staging';
$GLOBALS['origin']='https://tours.dev.mad4b.com';$GLOBALS['audit_ready']=true;
$GLOBALS['audit_fail']=false;$GLOBALS['audit_calls']=0;$GLOBALS['options']=[];
require dirname(__DIR__).'/wp-content/mu-plugins/505-mad4b-central-native-host-identity.php';
$c='MAD4B_Central_Native_Host_Identity_V1';
$p=['company'=>'egypt-tour-gates','origin'=>'https://egypttourgates.com',
 'site_uuid'=>'0c7786ba-a025-4650-9ceb-32a51d74aaec',
 'profile_digest'=>str_repeat('a',64),'revision'=>1,
 'environment'=>'production','host_transport'=>'pinned_hostinger_ssh_wpcli',
 'source_contract'=>'mad4b.central.native-site-profile.saved.v1',
 'profile_audit_verified'=>true,'write_enabled'=>false];
$passed=0;$check=function($ok,$why)use(&$passed){if(!$ok)throw new RuntimeException($why);++$passed;};
$deny=function($row,$code)use($check,$c){
  $r=$c::register($row);$check(is_wp_error($r)&&$r->get_error_code()===$code,$code);
};
putenv('MAD4B_CENTRAL_SSH_REGISTRATION');
$deny($p,'mad4b_central_native_host_admission_denied');
putenv('MAD4B_CENTRAL_SSH_REGISTRATION=1');
$GLOBALS['actor']=3;
$deny($p,'mad4b_central_native_host_admission_denied');$GLOBALS['actor']=0;
$GLOBALS['environment']='production';
$deny($p,'mad4b_central_native_host_admission_denied');$GLOBALS['environment']='staging';
$x=$p;$x['origin']='https://other.example';$deny($x,'mad4b_central_host_company_origin_unrecognized');
$x=$p;$x['write_enabled']=true;$deny($x,'mad4b_central_host_readback_invalid');
$x=$p;$x['profile_audit_verified']=false;$deny($x,'mad4b_central_host_readback_invalid');
$x=$p;$x['profile_digest']='fake';$deny($x,'mad4b_central_host_readback_invalid');
$check(empty($GLOBALS['options']),'all denied requests are read-only');
$r=$c::register($p);
$check($r['state']==='EXACT_READ_ONLY_HOST_IDENTITY_REGISTERED','correct bounded registration');
$check($r['write_authorized']===false,'no write grant');
$check($r['original313_signed']===false,'no 313 receipt forged');
$check($GLOBALS['audit_calls']===1,'audit appended');
$check($GLOBALS['last_audit']['authority']==='central_native_pinned_ssh','audit records real authority');
$rows=$GLOBALS['options'][$c::OPTION];
$check($rows['egypt-tour-gates']['registration_mode']==='local_vps_verified_readback','source mode');
$check($rows['egypt-tour-gates']['identity_only']===true,'identity only');
$check($rows['egypt-tour-gates']['native_write_authorized']===false,'no Native grant');
$check($c::register($p)['state']==='ALREADY_REGISTERED','idempotent readback');
$check($GLOBALS['audit_calls']===1,'no duplicate audit');
$x=$p;$x['site_uuid']='b8dffda1-db99-4789-aa72-86bf63f557cc';
$deny($x,'mad4b_central_host_existing_binding_conflict');
$check($GLOBALS['options'][$c::OPTION]===$rows,'conflicting input did not alter registry');
$GLOBALS['audit_fail']=true;
$second=$p;$second['company']='tourism-almallahgroup';$second['origin']='https://tourism.almallahgroup-mg.com';$second['site_uuid']='b8dffda1-db99-4789-aa72-86bf63f557cc';
$deny($second,'mad4b_central_host_registry_audit_failed');
$check($GLOBALS['options'][$c::OPTION]===$rows,'audit failure rolled back compare and swap');
echo json_encode(['contract'=>$c::CONTRACT,'passed'=>$passed,'grants_created'=>0,'original313_signed'=>false]),"\n";
