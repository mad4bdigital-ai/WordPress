<?php
/** Tests request ownership, site admission and boot closure without WordPress or DB. */
define('ABSPATH','/tmp/');
define('MAD4B_SCP_DIR',__DIR__.'/fixtures/public-kernel/');
define('WP_PLUGIN_DIR','/tmp/none');
require_once __DIR__.'/../includes/class-mad4b-scp-public-document-kernel.php';
$GLOBALS['scp_fixture_calls']=[];
$GLOBALS['scp_fixture_options']=[];
$GLOBALS['scp_fixture_environment']='production';
$GLOBALS['scp_fixture_profile']=[
  'configured'=>true,'origin_match'=>true,'environment_match'=>true,
  'deployment_binding_bound'=>true,'deployment_binding_match'=>true,
  'foreign_profile_detected'=>false,'profile_authority_quarantined'=>false,
  'mutation_pending_audit'=>false,'host_bootstrap_required'=>false
];
function get_option($k,$default=false) {
 return array_key_exists($k,$GLOBALS['scp_fixture_options'])?$GLOBALS['scp_fixture_options'][$k]:$default;
}
function wp_get_environment_type() { return $GLOBALS['scp_fixture_environment']; }
function wp_parse_url($url,$component=-1) { return parse_url($url,$component); }
function home_url($path='/') { return 'https://travel.example.invalid'.$path; }
function is_admin() { return false; }
function scp_check($ok,$name) {
 if(!$ok) {fwrite(STDERR,"FAIL ". $name ."\n");exit(1);}
}
$_SERVER=[
  'REQUEST_METHOD'=>'GET','HTTPS'=>'on','SERVER_PORT'=>'443',
  'HTTP_HOST'=>'travel.example.invalid',
  'REQUEST_URI'=>'/journeys/desert-tour/','QUERY_STRING'=>''
];
$_GET=$_POST=$_COOKIE=$_FILES=[];
$opt_mode=MAD4B_SCP_Public_Document_Kernel::MODE_OPTION;
$opt_prefix=MAD4B_SCP_Public_Document_Kernel::PREFIX_OPTION;
$opt_paths=MAD4B_SCP_Public_Document_Kernel::PATHS_OPTION;
$included=count(get_included_files());
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'default_off');
scp_check(count(get_included_files())===$included,'default_off_no_class_load');
$GLOBALS['scp_fixture_options'][$opt_mode]='on';
$GLOBALS['scp_fixture_options'][$opt_prefix]=['journeys'];
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'on_without_exact_verified_paths');
scp_check(count(get_included_files())===$included,'no_class_load_before_route_verification');
$GLOBALS['scp_fixture_options'][$opt_paths]=['/journeys/another-tour/'];
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'unverified_exact_path');
$GLOBALS['scp_fixture_options'][$opt_paths]=['/journeys/desert-tour/'];
$GLOBALS['scp_fixture_profile']['deployment_binding_match']=false;
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'identity_mismatch_fails_closed');
scp_check($GLOBALS['scp_fixture_calls']===[],'identity_failure_did_not_boot_market');
$GLOBALS['scp_fixture_profile']['deployment_binding_match']=true;
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===true,'verified_site_public_document');
scp_check(defined('MAD4B_SCP_EARLY_ZERO_TOUCH_REASON')
    && MAD4B_SCP_EARLY_ZERO_TOUCH_REASON==='public_document_kernel_v1','source_reason');
$must=['company_operations_policy_fences','mcp_scope_bootstrap','market_profiles_boot','market_context_boot','market_preferences_boot'];
foreach($must as $s)scp_check(in_array($s,$GLOBALS['scp_fixture_calls'],true),'missing_'.$s);
scp_check(!in_array('admin_calendar_boot',$GLOBALS['scp_fixture_calls'],true),'no_admin_calendar');
scp_check(count($GLOBALS['scp_fixture_calls'])===count($must),'no_unexpected_extra_boot');
$previous=count($GLOBALS['scp_fixture_calls']);
$_SERVER['HTTP_AUTHORIZATION']='Bearer danger';
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'authenticated_fallback');
scp_check(count($GLOBALS['scp_fixture_calls'])===$previous,'auth_must_not_boot_more');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['HTTP_COOKIE']='woocommerce_session=private';
scp_check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'woocommerce_fallback');
scp_check(count($GLOBALS['scp_fixture_calls'])===$previous,'commerce_private_must_not_boot_more');
unset($_SERVER['HTTP_COOKIE']);
echo 'public document kernel isolated runtime: PASS site-bound, exact-route, market hooks, policy, and private fallbacks'."\n";
