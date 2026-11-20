<?php
/**
 * First-party local FAORE REST read-only discovery: enrolled-site/tenant
 * boundary. No event dispatch, external service, Skill grant or WP mutation.
 */
define('ABSPATH',__DIR__.'/');
define('ICL_SITEPRESS_VERSION','4.9.0');
$GLOBALS['fcp_admin']=false;
$GLOBALS['fcp_operations']=false;
$GLOBALS['fcp_enrolled']=false;
$GLOBALS['fcp_url_matches']=true;
$GLOBALS['fcp_env']='production';
$GLOBALS['fcp_site']='https://company.example.invalid';
$GLOBALS['fcp_uuid']='12345678-1234-4123-8123-123456789abc';
$GLOBALS['fcp_writes']=0;
$GLOBALS['fcp_hooks']=array();
$GLOBALS['fcp_routes']=array();
function add_action($hook,$cb,$priority=10){$GLOBALS['fcp_hooks'][$hook][]=$cb;}
function register_rest_route($namespace,$route,$config){
 $GLOBALS['fcp_routes'][$namespace.$route]=$config;
}
function current_user_can($cap){
 return ($cap==='manage_options' && $GLOBALS['fcp_admin'])
 || ($cap==='mad4b_manage_company_operations' && $GLOBALS['fcp_operations']);
}
function rest_ensure_response($data){return new FakeFCPReply($data);}
function is_wp_error($value){return $value instanceof WP_Error;}
class WP_Error {
 private $code;public function __construct($code,$message='',$details=array()){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
class FakeFCPReply {
 public $data;public $headers=array();
 public function __construct($data){$this->data=$data;}
 public function header($key,$value){$this->headers[$key]=$value;}
}
class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled(){return $GLOBALS['fcp_enrolled'];}
 public static function site_urls_match_enrollment(){return $GLOBALS['fcp_url_matches'];}
 public static function site_uuid(){return $GLOBALS['fcp_uuid'];}
 public static function current_environment(){return $GLOBALS['fcp_env'];}
 public static function site_origin(){return $GLOBALS['fcp_site'];}
 public static function profile_digest(){return str_repeat('a',64);}
}
class MAD4B_SCP_Site_Capability_Discovery {
 public static function observe($origin){return array(
  'read_inventory_state'=>'complete','snapshot_sha256'=>str_repeat('b',64),
  'discovery_complete'=>true);
 }
}
function fcp_check($cond,$why){
 if(!$cond)throw new RuntimeException('FAIL NATIVE FCP LOCAL: '.$why);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-fcp-local-observability.php';
$c='MAD4B_SCP_FCP_Local_Observability';
$c::boot();
fcp_check(count($GLOBALS['fcp_hooks']['rest_api_init'])===1,
 'Original local FAORE must register a single provider route');
call_user_func($GLOBALS['fcp_hooks']['rest_api_init'][0]);
$route=$GLOBALS['fcp_routes']['mad4b/v1/fcp/local-capabilities']??null;
fcp_check(count($GLOBALS['fcp_routes'])===1
 && $route['methods']==='GET'
 && $route['permission_callback']===array($c,'permitted')
 && call_user_func($route['permission_callback'])===false,
 'Unauthenticated local capability listing must not be public');
$GLOBALS['fcp_operations']=true;
fcp_check($c::permitted()===true,'Company operations permission should allow observation');
$notEnrolled=$c::observe();
fcp_check($notEnrolled instanceof WP_Error
 && $notEnrolled->get_error_code()==='mad4b_fcp_site_identity_unverified',
 'Unenrolled site cannot advertise skills');
$GLOBALS['fcp_enrolled']=true;
$GLOBALS['fcp_url_matches']=false;
fcp_check($c::observe()->get_error_code()==='mad4b_fcp_site_identity_unverified',
 'Cloned company with wrong origin must fail');
$GLOBALS['fcp_url_matches']=true;
$GLOBALS['fcp_env']='unknown';
fcp_check($c::observe()->get_error_code()==='mad4b_fcp_site_binding_invalid',
 'Unknown environment may not be announced as healthy');
$GLOBALS['fcp_env']='production';
$GLOBALS['fcp_site']='http://company.example.invalid';
fcp_check($c::observe()->get_error_code()==='mad4b_fcp_site_binding_invalid',
 'Insecure or noncanonical local origin must not be attested');
$GLOBALS['fcp_site']='https://company.example.invalid';
$accepted=$c::observe();
fcp_check(is_array($accepted)&&$accepted['contract']===$c::CONTRACT
 && $accepted['read_only']===true
 && $accepted['authorizing']===false
 && $accepted['native_write_authorized']===false
 && $accepted['company_id_resolution']==='central_registry_exact_site_binding_required'
 && count($accepted['capabilities'])===2
 && $accepted['capabilities'][0]['supported_operations']===array('read')
 && $accepted['capabilities'][1]['supported_operations']===array()
 && $accepted['capabilities'][1]['health']==='degraded'
 && $accepted['observed']['discovery_complete']===true,
 'Exact site observation cannot treat merely installed WPML as executable or issue authority');
$reply=$c::respond();
fcp_check($reply instanceof FakeFCPReply
 && $reply->headers['Cache-Control']==='private, no-store'
 && $reply->data['site_uuid']===$GLOBALS['fcp_uuid']
 && $GLOBALS['fcp_writes']===0,
 'Retained site capability evidence must be private, site-owned and nonmutating');
echo "PASS: FCP_LOCAL_SITE_NEUTRAL enrolled scope, private GET, WPML observational only\n";
