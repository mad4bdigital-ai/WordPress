<?php
/**
 * Owner confirmed 2026 PDF numbers are actual company costs in EGP.
 * Change SOURCE COST ROLE ONLY. Then seed low-confidence calibration
 * proposals into the separate, non-commercial simulation registry.
 */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_not_allowed');
$apply=defined('MAD4B_EGP_ACTUAL_COST_APPLY')&&MAD4B_EGP_ACTUAL_COST_APPLY;
$sourceClass='MAD4B_Transport_Self_Pricing_Controls_V1';
$modelClass='MAD4B_Transport_Distance_Time_V1';
if(!class_exists($sourceClass)||!class_exists($modelClass))
 WP_CLI::error('both_modules_required');
$prior=$sourceClass::current();
if(($prior['source_sha256']??'')!==$sourceClass::SHA ||
   ($prior['provider']??'')!=='self')
 WP_CLI::error('source_identity_mismatch');
if(!in_array($prior['currency'],['','EGP'],true)||
   !in_array($prior['price_role'],['reference_only','self_cost'],true))
 WP_CLI::error('existing_price_policy_conflict');
$raw=[];
foreach(['currency','price_role','apply_upper_egypt_five_pct',
 'global_markup_pct','city_markup_pct','vehicle_markup_pct'] as $key)
 $raw[$key]=$prior[$key];
$raw['currency']='EGP';$raw['price_role']='self_cost';
$validated=$sourceClass::validate($raw);
if(is_wp_error($validated))WP_CLI::error($validated->get_error_code());
$model=$modelClass::current();
if($model['source_sha256']!==$sourceClass::SHA ||
   $model['currency']!=='EGP'||$model['role']!=='self_cost')
 WP_CLI::error('model_owner_basis_mismatch');
$cal=$modelClass::calibrate();
if(($cal['source_role']??'')!=='actual_company_cost')
 WP_CLI::error('actual_source_cost_not_verified_in_calibration');
$proposal=array_filter($cal['profiles'],
 static fn($v)=>is_array($v['proposal']??null));
$modelInput=[];
foreach(['profiles','global_markup_pct','vehicle_markups','service_markups',
 'route_markups','road','night_factor','peak_factor',
 'discount_pct','time_share'] as $key)$modelInput[$key]=$model[$key];
$seeded=[];
if((int)$model['revision']===0){
 foreach($proposal as $vehicle=>$data){
  $modelInput['profiles'][$vehicle]=$data['proposal'];
  $seeded[]=$vehicle;
 }
}
$validModel=$modelClass::validate($modelInput);
if(is_wp_error($validModel))WP_CLI::error($validModel->get_error_code());
$backupKey='mad4b_transport_owner_cost_confirmation_backup_20261120_v1';
$backup=get_option($backupKey,false);
if(!$apply){
 echo wp_json_encode([
  'mode'=>'dry_run','site'=>$site,
  'source_revision'=>$prior['revision'],
  'previous_currency'=>$prior['currency'],
  'previous_role'=>$prior['price_role'],
  'new_currency'=>'EGP','new_role'=>'self_cost',
  'calibration_available'=>array_keys($proposal),
  'can_seed_now'=>$model['revision']===0,
  'transport_rates_published_by_this_operation'=>0,
  'checkout_enabled'=>false
 ])."\n";return;
}
if($backup===false){
 if(!add_option($backupKey,[
  'at'=>gmdate('c'),'site'=>$site,'prior_source'=>$prior,
  'prior_model'=>$model,
  'source_sha256'=>$sourceClass::SHA,
  'reason'=>'owner_confirmed_pdf_EGP_actual_company_cost',
  'checkout_authorized'=>false
 ],'',false))WP_CLI::error('site_backup_creation_failed');
}elseif(!is_array($backup)||($backup['site']??'')!==$site)
 WP_CLI::error('site_backup_conflict');
wp_set_current_user(1);
$changedSource=false;
if($prior['currency']!=='EGP'||$prior['price_role']!=='self_cost'){
 $r=$sourceClass::save($raw,(int)$prior['revision']);
 if(is_wp_error($r))WP_CLI::error('source_policy_'.$r->get_error_code());
 $changedSource=true;
}
$changedModel=false;
if($model['revision']===0 && count($seeded)){
 $r=$modelClass::save($modelInput,0);
 if(is_wp_error($r))WP_CLI::error('draft_model_'.$r->get_error_code());
 $changedModel=true;
}
$after=$sourceClass::current();$draft=$modelClass::current();
if($after['currency']!=='EGP'||$after['price_role']!=='self_cost'||
   $draft['currency']!=='EGP'||$draft['role']!=='self_cost'||
   $draft['mode']!=='simulation_only')
 WP_CLI::error('post_activation_readback_failed');
echo wp_json_encode([
 'status'=>'owner_confirmed_cost_registered','site'=>$site,
 'source_revision'=>$after['revision'],
 'draft_model_revision'=>$draft['revision'],
 'currency'=>'EGP','source_role'=>'self_cost',
 'vehicle_profiles_seeded'=>$seeded,
 'source_updated'=>$changedSource,'model_updated'=>$changedModel,
 'original_pdf_modified'=>false,'native_rates_modified'=>false,
 'checkout_authorized'=>false,'backup'=>$backupKey
 ])."\n";
