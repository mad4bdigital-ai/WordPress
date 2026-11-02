<?php
define('ABSPATH','/');
$GLOBALS['native']='staging';
$GLOBALS['origin']='https://staging.egypttourgates.com';
$GLOBALS['uuid']='d745d81f-6fc4-5c6a-99dd-d953c92137bf';
$GLOBALS['registered']=null;
$GLOBALS['foreign']=false;
function wp_get_environment_type(){return $GLOBALS['native'];}
function home_url($p='/'){return $GLOBALS['origin'].'/';}
function untrailingslashit($x){return rtrim($x,'/');}
$GLOBALS['hooks']=[];
function add_action($name,$callback,$priority=10) {
    $GLOBALS['hooks'][$name][]=['callback'=>$callback,'priority'=>$priority];
}
function get_option($x,$d=null){return $GLOBALS['registered']??$d;}
class MAD4B_Company_Signed_Site_Profile_V4 {}
class MAD4B_SCP_Site_Profile {
 static function status(){return ['site_uuid'=>$GLOBALS['uuid'],
 'canonical_origin'=>$GLOBALS['origin'],'environment'=>$GLOBALS['native'],
 'origin_match'=>true,'environment_match'=>true,
 'deployment_binding_match'=>true,'deployment_binding_bound'=>true,
 'profile_environment_authoritative'=>true,
 'foreign_profile_detected'=>$GLOBALS['foreign'],
 'profile_digest'=>str_repeat('a',64)];}
}
require __DIR__.'/etg-staging-site-identity-owner.php';
$p='MAD4B_ETG_Site_Identity_Enrollment_V1';
if (!isset($GLOBALS['hooks']['plugins_loaded'][0]) ||
    $GLOBALS['hooks']['plugins_loaded'][0]['priority']!==30)
    {fwrite(STDERR,"FAIL Deferred plugin initialization missing\n");exit(2);}
echo "PASS MU plugin defers until ordinary plugins loaded\n";
($GLOBALS['hooks']['plugins_loaded'][0]['callback'])();
if (!isset($GLOBALS['hooks']['admin_post_'.$p::ACTION][0]) ||
    !isset($GLOBALS['hooks']['admin_menu'][0]))
    {fwrite(STDERR,"FAIL Owner-only routes not registered after load\n");exit(2);}
echo "PASS Admin menu and original owner POST hook registered after load\n";
function assert_state($expected,$label){global $p;
 $actual=$p::state();if($actual!==$expected){fwrite(STDERR,"FAIL $label: $actual\n");exit(2);}
 echo "PASS $label\n";}
assert_state('EXACT_SITE_READY_FOR_OWNER_ENROLLMENT','exact original signed site ready');
$GLOBALS['native']='production';
assert_state('SIGNED_SITE_PROFILE_OR_HOST_BINDING_NOT_VERIFIED','production rejected');
$GLOBALS['native']='staging';$GLOBALS['uuid']='00000000-0000-0000-0000-000000000000';
assert_state('SIGNED_SITE_PROFILE_OR_HOST_BINDING_NOT_VERIFIED','uuid mismatch rejected');
$GLOBALS['uuid']='d745d81f-6fc4-5c6a-99dd-d953c92137bf';
$GLOBALS['foreign']=true;
assert_state('SIGNED_SITE_PROFILE_OR_HOST_BINDING_NOT_VERIFIED','foreign profile rejected');
$GLOBALS['foreign']=false;$GLOBALS['origin']='https://different.example.com';
assert_state('SIGNED_SITE_PROFILE_OR_HOST_BINDING_NOT_VERIFIED','origin mismatch rejected');
$GLOBALS['origin']='https://staging.egypttourgates.com';
$GLOBALS['registered']=['company_code'=>'other-company'];
assert_state('EXISTING_SITE_IDENTITY_CONFLICT','existing different registration protected');
$GLOBALS['registered']=$p::original();
assert_state('ORIGINAL_SITE_IDENTITY_ENROLLED','original identity accepted unchanged');
echo "ETG_STAGING_SITE_OWNER_ENROLLMENT_TESTS_PASS\n";
