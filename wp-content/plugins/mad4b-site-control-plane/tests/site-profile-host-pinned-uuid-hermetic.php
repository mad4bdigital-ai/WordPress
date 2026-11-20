<?php
/** Pure site identity proof, no WordPress, DB, API, OAuth or host execution. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
  private $code;
  public function __construct($code,$message=''){$this->code=$code;}
  public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
require dirname(__DIR__).'/includes/class-mad4b-scp-site-profile.php';
function check($yes,$name){
 if(!$yes){fwrite(STDERR,"FAIL: {$name}\n");exit(1);}
 echo "{$name}: PASS\n";
}
$class='MAD4B_SCP_Site_Profile';
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$origin='https://wayfatours.test.invalid';
$env='staging';
$site=array('host_pinned_site_uuid'=>$uuid);
$run=static function($input,$originArg=null,$envArg=null,$wpEnvArg=null,$valid=false)use($class,$origin,$env){
  return $class::host_pinned_uuid_preflight(
    $input,$originArg===null?$origin:$originArg,$envArg===null?$env:$envArg,
    $wpEnvArg===null?$env:$wpEnvArg,$valid);
};
check($run(array())==='','missing_host_request_does_not_assume_grant');
check($run(array('reserved_site_uuid'=>$uuid))==='','existing_reserved_flow_is_unmodified');
check($run(array('host_pinned_site_uuid'=>''))==='','empty_host_request_noop');
check(is_wp_error($run(array('host_pinned_site_uuid'=>array($uuid)))),'array_uuid_rejected');
check(is_wp_error($run(array('host_pinned_site_uuid'=>'imposter'))),'malformed_uuid_rejected');
$noHost=$run($site);
check(is_wp_error($noHost)&&$noHost->get_error_code()==='mad4b_host_pinned_site_uuid_host_binding_missing','missing_host_constants_denied');
define('MAD4B_TENANT_SITE_UUID',$uuid);
define('MAD4B_TENANT_ORIGIN',$origin);
define('MAD4B_TENANT_COMPANY_CODE','wayfatours');
$noEnvironment=$run($site);
check(is_wp_error($noEnvironment)&&$noEnvironment->get_error_code()==='mad4b_host_pinned_site_uuid_host_binding_mismatch','implicit_wp_environment_denied');
define('WP_ENVIRONMENT_TYPE','staging');
check($run($site)===$uuid,'host_fixed_uuid_first_enrollment');
check($run(array('host_pinned_site_uuid'=>strtoupper($uuid)))===$uuid,'case_normalized_uuid');
check($run(array('host_pinned_site_uuid'=>$uuid,'reserved_site_uuid'=>''))===$uuid,'empty_reserved_does_not_conflict');
$deny=static function($name,$input,$originArg=null,$envArg=null,$wpEnvArg=null,$valid=false)use($run) {
 $result=$run($input,$originArg,$envArg,$wpEnvArg,$valid);
 check(is_wp_error($result),$name.'_denied');
};
$deny('existing_profile_rebind',$site,null,null,null,true);
$deny('wrong_origin',$site,'https://foreign.test.invalid');
$deny('http_origin',$site,'http://wayfatours.test.invalid');
$deny('extra_origin_path',$site,$origin.'/wp-admin');
$deny('mismatched_site_uuid',array('host_pinned_site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05d'));
$deny('external_uuid_claimed_by_reserved',array('host_pinned_site_uuid'=>$uuid,'reserved_site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05d'));
$deny('same_uuid_claimed_by_reserved',array('host_pinned_site_uuid'=>$uuid,'reserved_site_uuid'=>$uuid));
$deny('invalid_reserved_type',array('host_pinned_site_uuid'=>$uuid,'reserved_site_uuid'=>array()));
$deny('local_environment',$site,null,'local','local');
$deny('development_environment',$site,null,'development','development');
$deny('nonmatching_wordpress_environment',$site,null,'production','staging');
$deny('nonmatching_runtime_input',$site,null,'staging','production');
$deny('empty_origin',$site,'');
check(!is_wp_error($run(array('reserved_site_uuid'=>$uuid))), 'host_preflight_does_not_disrupt_existing_reserved_flow');
check(!function_exists('update_option')&&!function_exists('wp_mail')&&!function_exists('wp_schedule_event'),'no_wordpress_mutators_present');
echo 'HOST_PINNED_SITE_UUID_HERMETIC: PASS'.PHP_EOL;
