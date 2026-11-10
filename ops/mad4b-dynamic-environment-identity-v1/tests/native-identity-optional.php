<?php
defined('WP_CLI') && WP_CLI || exit(2);
$pass=0;
$check=static function($value,$label)use(&$pass){
 if(!$value)throw new RuntimeException('NATIVE_V4_REGRESSION_'.$label);
 ++$pass;
 echo 'PASS '.$label.PHP_EOL;
};
$class='MAD4B_Environment_Signed_Identity_V4';
$check(class_exists($class,false),'signed_v4_class_loaded');
$check(wp_get_environment_type()==='staging' &&
 untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com','exact_staging');
$check(get_current_user_id()===0,'anonymous_wpcli_actor');
$check($class::native_permission()===true,'existing_host_principal_verified');
$check($class::permission()===false,'rest_owner_still_required');
$notOwner=$class::verify('egypt-tour-gates','staging');
$check(is_wp_error($notOwner) &&
 $notOwner->get_error_code()==='mad4b_environment_admin_required',
 'rest_verify_cannot_use_anonymous_actor');
wp_set_current_user(1);
try {
 $check($class::native_permission()===false,'wp_actor_override_cannot_claim_native');
} finally {
 wp_set_current_user(0);
}
$check($class::native_permission()===true,'native_principal_restored');
$check($class::native_refresh_needed([
 'centrally_verified'=>false])===true,'stale_proof_requires_renewal');
$check($class::native_refresh_needed([
 'centrally_verified'=>true,'verified_at'=>gmdate('c')])===false,
 'fresh_proof_does_not_renew');
$check($class::native_refresh_needed([
 'centrally_verified'=>true,'verified_at'=>gmdate('c',time()-19*3600)])===true,
 'aging_proof_renews_before_expiry');
$before=$class::status('egypt-tour-gates','staging');
$check(is_array($before)&&isset($before['centrally_verified']),
 'readback_without_mutation');
echo 'VERDICT '.$pass.'/'.$pass.' PASS'.PHP_EOL;
