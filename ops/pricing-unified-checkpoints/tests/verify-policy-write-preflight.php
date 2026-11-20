<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Pricing_Policy_Write_Preflight_V1';
if(!class_exists($cls,false))WP_CLI::error('Missing source-aware preflight');
$optionNames=[
 'mad4b_jetengine_pricing_lab_presets_v1',
 'mad4b_pricing_global_policy_ids_v1',
 'etg-dynamic-pricing-controls_shared_package_pricing_rows',
 'booking_experience_pricing_preset_rows_v1',
];
$before=[];
foreach($optionNames as $name)$before[$name]=get_option($name,null);
$saved=$cls::inspect('pricing_lab','save_draft',['id'=>'','revision'=>0]);
if(is_wp_error($saved) || $saved['state']!=='native_validation_required' ||
   $saved['write_executed']!==false ||
   $saved['commercial_activation_approved']!==false)
 WP_CLI::error('Draft preflight incorrectly wrote or activated');
$missing=$cls::inspect('pricing_lab','save_draft',[
 'id'=>'pl-'.str_repeat('f',32),'revision'=>1]);
if(!is_wp_error($missing)||$missing->get_error_code()!=='policy_preflight_draft_missing')
 WP_CLI::error('Missing Lab draft incorrectly passed');
$stage=$cls::inspect('pricing_lab','stage_disabled',[
 'id'=>'pl-'.str_repeat('f',32),'revision'=>1]);
if(!is_wp_error($stage)||$stage->get_error_code()!=='policy_preflight_draft_missing')
 WP_CLI::error('Unknown staging draft incorrectly authorized');
$core='MAD4B_Hybrid_Pricing_Policies_V1';
$current=$core::read('global',0);
$check=$cls::inspect('policy_binding','bind_references',[
 'scope'=>'global','target_id'=>0,'revision'=>$current['revision'],
 'ids'=>$current['ids'],
]);
if(is_wp_error($check) || $check['state']!=='unchanged' ||
   $check['write_executed']!==false)
 WP_CLI::error('Native binder unchanged result not certified');
$stale=$cls::inspect('policy_binding','bind_references',[
 'scope'=>'global','target_id'=>0,'revision'=>$current['revision']+1,
 'ids'=>$current['ids'],
]);
if(!is_wp_error($stale)||$stale->get_error_code()!=='policy_preflight_stale_revision')
 WP_CLI::error('Stale binding revision accepted');
$invalid=$cls::inspect('policy_binding','bind_references',[
 'scope'=>'global','target_id'=>0,'revision'=>$current['revision'],
 'ids'=>['pl-not-a-valid-id'],
]);
if(!is_wp_error($invalid)||$invalid->get_error_code()!=='policy_preflight_binding_refs_invalid')
 WP_CLI::error('Malformed binding ID accepted');
$native=$cls::inspect('jetengine_options','save_native',['revision'=>0]);
if(is_wp_error($native) || $native['state']!=='blocked' ||
  $native['reason']!=='native_options_writer_missing_compare_and_swap')
 WP_CLI::error('Native JetEngine save wrongly implied atomicity');
$activation=$cls::inspect('checkout_activation','activate', ['revision'=>0]);
if(is_wp_error($activation)||$activation['state']!=='blocked' ||
   $activation['commercial_activation_approved']!==false)
 WP_CLI::error('Checkout incorrectly approved');
$unauthorized=$cls::inspect('unknown','save_native',['revision'=>0]);
if(!is_wp_error($unauthorized))
 WP_CLI::error('Unrecognized provider accepted');
foreach($optionNames as $name)
 if(get_option($name,null)!==$before[$name])
   WP_CLI::error('Preflight mutated original native WordPress option '.$name);
if(has_action('wp_ajax_nopriv_'.$cls::ACTION))
 WP_CLI::error('Sensitive company policy preflight public to guests');
wp_set_current_user(0);
$guest=$cls::inspect('policy_binding','bind_references',[
 'scope'=>'global','target_id'=>0,'revision'=>0,'ids'=>[],
]);
if(!is_wp_error($guest) || $guest->get_error_code()!=='policy_preflight_forbidden')
 WP_CLI::error('Guest accessed company policy status');
echo 'POLICY_PREFLIGHT_ACCEPTANCE '.wp_json_encode([
 'site'=>home_url(),'lab_draft'=>'preflight_only',
 'binding_revision'=>$current['revision'],
 'stale_revision_rejected'=>true,'malformed_refs_rejected'=>true,
 'jetengine_native_write'=>'blocked_missing_atomic_revision',
 'checkout_activation'=>'blocked',
 'guests_denied'=>true,'writes'=>0,
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('POLICY_SOURCE_OWNED_PREFLIGHT_READ_ONLY_ACCEPTED');
