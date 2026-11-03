<?php
if(DB_NAME!=='etg_woocommerce_lab'||wp_get_environment_type()!=='local'
  ||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)
 WP_CLI::error('Wrong environment');
global $wpdb;
$before=[
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'tasks'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fbs_tasks"),
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")
];
$checks=[];
$check=static function($name,$value)use(&$checks){$checks[$name]=(bool)$value;};
wp_set_current_user(1);
$report=\Commerce\Composition\CommerceTenantBindingAudit::inspect();
$check('diagnostic_schema',($report['contract']??null)==='commerce.tenant-binding-audit.v1');
$check('site_profile_authority_healthy',$report['site_profile_authority_healthy']===true);
$check('foreign_company_record_detected',($report['company_binding']['same_site_uuid']??true)===false);
$check('foreign_policy_record_detected',($report['policy_binding']['same_site_uuid']??true)===false);
$check('site_reenrollment_classification',$report['classification']==='foreign_or_reenrolled_site_profile');
$check('commercial_write_remains_denied',$report['commerce_write_authorized']===false);
$check('automatic_rebinding_denied',$report['automatic_rebinding_forbidden']===true);
$check('no_raw_uuid_or_digest_disclosed',!isset($report['site_uuid'],$report['profile_digest'],$report['company_site_uuid']));
$request=new WP_REST_Request('GET','/commerce-composition/v1/management/commerce-binding-audit');
$resp=rest_do_request($request);
$check('administrator_rest_200',$resp->get_status()===200);
$check('administrator_result_has_no_financial_grant',($resp->get_data()['commerce_write_authorized']??true)===false);
wp_set_current_user(0);
$unauth=rest_do_request($request);
$check('guest_rest_denied',in_array($unauth->get_status(),[401,403],true));
wp_set_current_user(1);
$after=[
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'tasks'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fbs_tasks"),
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")
];
$check('business_counts_unchanged',$before===$after);
$pass=!in_array(false,$checks,true);
echo wp_json_encode(['status'=>$pass?'PASS':'FAIL','count'=>count($checks),
 'passed'=>count(array_filter($checks)),'checks'=>$checks,
 'classification'=>$report['classification']],JSON_PRETTY_PRINT),PHP_EOL;
if(!$pass)WP_CLI::error('Binding audit certification failed');
