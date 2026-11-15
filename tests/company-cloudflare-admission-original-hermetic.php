<?php
/** Original Cloudflare company domain: no HTTP, no real WordPress changes. */
define('ABSPATH', __DIR__.'/');
define('LOGGED_IN_COOKIE','fake-original-cookie-name');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=array()){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['privileges'][$cap]??false;}
function get_current_user_id(){return 17;}
function wp_validate_auth_cookie($cookie,$kind){return $GLOBALS['cookie_user'];}
function wp_get_session_token(){return $GLOBALS['session'];}
function wp_verify_nonce($nonce,$purpose){return $GLOBALS['nonce'];}
function domain_assert($yes,$reason){
 if(!$yes){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['assertions']=($GLOBALS['assertions']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-domain-cloudflare.php';
$c='MAD4B_SCP_Company_Domain_Cloudflare';
$GLOBALS['privileges']=['manage_options'=>false,'activate_plugins'=>false];
$GLOBALS['cookie_user']=17;$GLOBALS['session']='original-session';$GLOBALS['nonce']=1;
$_COOKIE=[LOGGED_IN_COOKIE=>'session-cookie'];$_SERVER=['HTTP_X_WP_NONCE'=>'original-rest-nonce'];
domain_assert(is_wp_error($c::can_write()),'No owner privileges must deny original Cloudflare mutation');
$GLOBALS['privileges']['manage_options']=true;
domain_assert(is_wp_error($c::can_write()),'Manage Options without plugin admin cannot approve Cloudflare mutations');
$GLOBALS['privileges']['activate_plugins']=true;
domain_assert($c::can_write()===true,'Original admin authenticated browser session can enter plan-bound validation');
foreach([new WP_Error('nonce_unknown'),null,false,'1',true,0,3] as $invalid){
 $GLOBALS['nonce']=$invalid;
 domain_assert(is_wp_error($c::can_write()),'Unexpected WordPress nonce result must fail closed');
}
$GLOBALS['nonce']=2;
domain_assert($c::can_write()===true,'Core WordPress second valid nonce window must remain supported');
foreach([new WP_Error('unknown'),null,false,'17',0,18] as $invalid){
 $GLOBALS['cookie_user']=$invalid;
 domain_assert(is_wp_error($c::can_write()),'WordPress cookie cannot impersonate original admin by object/string coercion');
}
$GLOBALS['cookie_user']=17;$GLOBALS['nonce']=1;
foreach(['https://example.com','127.0.0.1','example.com.','A.EXAMPLE.COM','a..example.com','localhost','-bad.example.com'] as $invalid){
 domain_assert(!$c::valid_host($invalid),'Invalid or ambiguous FQDN must be denied');
}
domain_assert($c::valid_host('company.mad4b.com')===true,'Valid managed company FQDN admitted as a candidate');
$settings=[
 'registry'=>['wayfa'=>[
  'production_admitted'=>true,
  'origin_host'=>'origin.wayfatours.com',
  'approved_custom_domains'=>['wayfatours.com']]],
 'origin_ipv4'=>'8.8.8.8'
];
$ok=$c::validate_target(['company_code'=>'wayfa','mode'=>'custom_domain','hostname'=>'wayfatours.com'],$settings);
domain_assert(is_array($ok)&&$ok['hostname']==='wayfatours.com',
 'Exact known enrolled custom hostname passes original Plan validation');
foreach([
 ['company_code'=>'unknown','mode'=>'subdomain','hostname'=>'unknown.mad4b.com'],
 ['company_code'=>'wayfa','mode'=>'subdomain','hostname'=>'egypt.mad4b.com'],
 ['company_code'=>'wayfa','mode'=>'custom_domain','hostname'=>'anothercompany.com'],
 ['company_code'=>'wayfa','mode'=>'custom_domain','hostname'=>'origin.wayfatours.com'],
 ['company_code'=>'wayfa','mode'=>'ftp','hostname'=>'wayfatours.com']
] as $entry){
 domain_assert(is_wp_error($c::validate_target($entry,$settings)),
  'Hostname cannot be registered for wrong company or mode');
}
$settings['origin_ipv4']='127.0.0.1';
domain_assert(is_wp_error($c::validate_target(
 ['company_code'=>'wayfa','mode'=>'custom_domain','hostname'=>'wayfatours.com'],$settings)),
 'Company domain cannot point to loopback or private origin');
echo 'PASS: COMPANY_CLOUDFLARE_ADMISSION '.($GLOBALS['assertions']??0).
 ' original owner/nonce and domain-scoping cases'.PHP_EOL;
