<?php
/** Isolated request-path test. Invoke before_native_save directly, never vendor Save. */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
wp_set_current_user(1);
set_current_screen('dashboard');
$cls='MAD4B_JetEngine_Native_Pricing_CAS_V1';
$mode=getenv('MAD4B_CAS_TEST_MODE')?:'get';
$revision=$cls::source_revision();
if(is_wp_error($revision))WP_CLI::error($revision->get_error_code());
$_GET['page']=$cls::PAGE;
$_REQUEST['action']=$cls::ACTION;
$_SERVER['REQUEST_METHOD']=$mode==='get'?'GET':'POST';
$_POST=[];
if($mode==='stale'){
 $_POST['mad4b_native_cas_nonce']=wp_create_nonce($cls::NONCE_ACTION);
 $_POST['mad4b_native_cas_revision']=str_repeat('f',64);
}
if($mode==='valid'){
 $_POST['mad4b_native_cas_nonce']=wp_create_nonce($cls::NONCE_ACTION);
 $_POST['mad4b_native_cas_revision']=$revision;
}
$cls::before_native_save();
if($mode!=='valid')WP_CLI::error('SECURITY_FAILURE_NATIVE_GUARD_RETURNED_FOR_'.$mode);
$cls::unlock();
if($cls::source_revision()!==$revision)WP_CLI::error('CAS_ADMISSION_CHANGED_ORIGINAL_SETTINGS');
WP_CLI::success('SIGNED_NATIVE_CAS_ADMISSION_NO_WRITE');
