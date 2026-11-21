<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_JetEngine_Native_Pricing_CAS_V1';
if(!class_exists($cls,false))WP_CLI::error('Native CAS module not loaded');
wp_set_current_user(1);
$before=$cls::source_revision();
if(is_wp_error($before))WP_CLI::error('Cannot read real JetEngine source revision: '.$before->get_error_code());
$nonce=wp_create_nonce($cls::NONCE_ACTION);
$valid=$cls::check_request([
 'mad4b_native_cas_nonce'=>$nonce,'mad4b_native_cas_revision'=>$before
]);
if(is_wp_error($valid)||($valid['approved_to_call_native_writer']??false)!==true||
 ($valid['write_executed']??true)!==false)
 WP_CLI::error('Current signed source revision wrongly rejected');
$stale=$cls::check_request([
 'mad4b_native_cas_nonce'=>$nonce,'mad4b_native_cas_revision'=>str_repeat('f',64)
]);
if(!is_wp_error($stale) ||$stale->get_error_code()!=='native_pricing_cas_stale_source')
 WP_CLI::error('Stale source revision accepted');
$unsigned=$cls::check_request([
 'mad4b_native_cas_nonce'=>'','mad4b_native_cas_revision'=>$before
]);
if(!is_wp_error($unsigned) ||$unsigned->get_error_code()!=='native_pricing_cas_nonce_missing')
 WP_CLI::error('Missing nonce wrongly authorized');
$original=$cls::source_revision();
if($before!==$original)WP_CLI::error('Read-only native CAS test changed DB revision');
if(has_action('admin_init',[$cls,'before_native_save'])!==39)
 WP_CLI::error('CAS request interception is not before JetEngine priority 40');
set_current_screen('dashboard');
$_GET['page']=$cls::PAGE;
$cls::enqueue();
$script=wp_scripts()->registered['mad4b-jetengine-native-pricing-cas-v1']??null;
if(!$script||!wp_script_is('mad4b-jetengine-native-pricing-cas-v1','enqueued'))
 WP_CLI::error('Native CAS protected Save script not enqueued');
$lock=(new ReflectionClass($cls))->getMethod('lock');
$lock->setAccessible(true);
$acquired=$lock->invoke(null);
if(is_wp_error($acquired))WP_CLI::error('MySQL source-save advisory lock unavailable '.$acquired->get_error_code());
$cls::unlock();
wp_set_current_user(0);
$guest=$cls::check_request([
 'mad4b_native_cas_nonce'=>$nonce,'mad4b_native_cas_revision'=>$before
]);
if(!is_wp_error($guest)||$guest->get_error_code()!=='native_pricing_cas_forbidden')
 WP_CLI::error('Guest can authorize original JetEngine Save');
echo 'NATIVE_CAS_ACCEPTANCE '.wp_json_encode([
 'site'=>home_url(),'source_revision'=>substr($before,0,12),
 'jetengine_save_priority'=>40,'guard_priority'=>39,
 'current_signed_revision'=>true,'stale_rejected'=>true,
 'missing_nonce_rejected'=>true,'guest_rejected'=>true,
 'mysql_lock_acquired_and_released'=>true,'native_options_mutated'=>false
]).PHP_EOL;
WP_CLI::success('JETENGINE_SEPARATE_OPTIONS_SOURCE_CAS_ADMISSION_ACCEPTED');
