<?php
if(!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='staging'
  || untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
    throw new RuntimeException('CENTRAL_STAGING_WPCLI_ONLY');
$tested=0;$check=static function($v,string $label) use (&$tested):void {
    if(!$v)throw new RuntimeException('FAIL_'.$label);
    $tested++;
};
$handler='MAD4B_Company_Native_Scoped_Write_Handler_V1';
$provider='MAD4B_Company_Native_Scoped_Write_Providers_V1';
$registry='MAD4B_Original_Native_Operation_Providers_V1';
$check(class_exists($handler,false),'handler_loaded');
$check(class_exists($provider,false),'provider_loaded');
$check(has_action('mad4b_original_native_operation_providers_register',[$provider,'register'])!==false,'registration_hook');
$seen=[];
foreach($provider::OPERATIONS as $op=>$settings){
    $profile=$registry::profile($op);
    $check(is_array($profile),'profile_'.$op);
    $check($profile['provider']===$settings[0]
      && $profile['authority_family']==='original_305'
      && $profile['target_scope']==='wordpress'
      && $profile['command_format']==='wpcli.argv.json.v1'
      && $profile['readback']===[$provider,$settings[1]],'exact_profile_'.$op);
    $check(!in_array($settings[0],$seen,true),'provider_unique_'.$op);
    $seen[]=$settings[0];
    $status=$registry::discover($op);
    $check(($status['provider_count']??null)===1
      &&($status['operations'][0]['registration_verified']??false)===true,'registered_once_'.$op);
    $pre=MAD4B_Company_Native_Write_Executor_V1::preflight_status('wayfatours','production',$op);
    $check(($pre['site_uuid']??null)==='a3a7cc36-db7e-4a14-8456-b41857def05c'
      &&($pre['original_transport_verified']??false)===true
      &&($pre['operation_provider_registered']??false)===true,'real_305_and_registration_'.$op);
    $check(($pre['execution_allowed']??true)===false
      &&($pre['remote_write_permitted']??true)===false
      &&($pre['job_verified']??true)===false
      &&($pre['production_mutated']??true)===false,'no_fabricated_313_'.$op);
}
$check(is_array($registry::profile('plugin.install')),'existing_plugin_provider_unchanged');
$check(($registry::profile('maintenance.shell')['authority_family']??null)==='original_305',
  'existing_host_maintenance_provider_unchanged');
$manager='d9813e2d-d48a-52c7-ad48-8967a22656df';
foreach(array_keys($provider::OPERATIONS) as $op){
    $e=MAD4B_Manager_Auto_Write_V1::entitlement($manager,'wayfatours',$op,1);
    $check(($e['assignment_authorized']??false)===true
      &&($e['company_binding_verified']??false)===true
      &&($e['execution_target_site_uuid']??null)==='a3a7cc36-db7e-4a14-8456-b41857def05c'
      &&($e['execution_target_environment']??null)==='production','delegated_intent_'.$op);
    $check(($e['remote_write_ready']??true)===false
      &&($e['remote_execution_performed']??true)===false,'native_writes_still_independent_'.$op);
}
echo wp_json_encode([
    'contract'=>'mad4b.wo-vacation-scoped-write-regression.v1',
    'result'=>'PASS',
    'checks_passed'=>$tested,
    'site'=>home_url(),
    'scopes'=>array_keys($provider::OPERATIONS),
    'full_wpcli_transport_verified'=>true,
    'native_write_executed'=>false,
    'new_305_job_created'=>false
],JSON_UNESCAPED_SLASHES)."\n";
