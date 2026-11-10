<?php
/**
 * Feature 009 P1 read-only handover census acceptance.
 * Run with: wp eval-file wp-content/plugins/mad4b-tenant-sync/tests/tenant-handover-acceptance.php
 */
if(!defined('WP_CLI')||!WP_CLI)exit(2);
$class='MAD4B_Tenant_Handover_Inventory_V1';
$checks=['loaded'=>class_exists($class,false)];
$before=get_option('mad4b_company_network_memberships_v1',null);
$data=$class::inventory();
$checks['read_succeeded']=!is_wp_error($data);
if(is_wp_error($data)){
  echo wp_json_encode(['pass'=>false,'code'=>$data->get_error_code()]),PHP_EOL;
  exit(8);
}
$site=MAD4B_SCP_Site_Profile::status();
$checks['site_identity']=($data['site']['site_uuid']??null)===($site['site_uuid']??null);
$checks['site_bound']=($site['authority_ready']??false)===true;
$checks['checksum_shape']=preg_match('/^[0-9a-f]{64}$/D',$data['inventory_sha256']??'')===1;
$checks['no_runtime_changes']=$data['runtime_mutated']===false
  &&$data['portable_export_created']===false&&$data['detach_authorized']===false;
$checks['financial_data_not_exported']=$data['providers']['woo_financial_data']==='EXCLUDED_FROM_PORTABLE_EXPORT';
$checks['personal_data_not_exported']=$data['providers']['fluent_submissions']==='EXCLUDED_FROM_PORTABLE_EXPORT';
$checks['cross_site_remap_required']=$data['providers']['jetengine']['state']==='NOT_INSTALLED'
  ||$data['providers']['jetengine']['local_ids_portable']===false;
$checks['idempotent_readback']=$class::inventory()['inventory_sha256']===$data['inventory_sha256'];
$checks['no_membership_write']=get_option('mad4b_company_network_memberships_v1',null)===$before;
$route='/mad4b-tenant/v1/handover-inventory';
$routes=rest_get_server()->get_routes();
$checks['rest_route_exists']=isset($routes[$route]);
$anonymous=rest_do_request(new WP_REST_Request('GET',$route));
$checks['anonymous_denied']=$anonymous->get_status()===403;
$users=get_users(['role'=>'administrator','fields'=>'ids','number'=>1]);
if($users){
 wp_set_current_user((int)$users[0]);
 $authorized=rest_do_request(new WP_REST_Request('GET',$route));
 $checks['admin_readback']=$authorized->get_status()===200
     &&($authorized->get_data()['inventory_sha256']??null)===$data['inventory_sha256'];
} else $checks['admin_readback']=false;
$checks['cannot_detach_with_inventory']=count($data['blocking_gates'])>=7;
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),'checks'=>$checks,
 'identity'=>$data['site']['site_uuid'],
 'inventory_sha256'=>$data['inventory_sha256']],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
