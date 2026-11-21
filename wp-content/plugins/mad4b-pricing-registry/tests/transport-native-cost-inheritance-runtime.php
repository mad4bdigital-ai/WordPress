<?php
/** Runtime regression: original JetEngine inheritance, no persistent writes. */
defined('ABSPATH')||exit(2);
$native='MAD4B_Transport_Native_Cost_Inheritance_V1';
$model='MAD4B_Transport_Distance_Time_V1';
$tests=[];
$check=static function(string $name,bool $value)use(&$tests){$tests[$name]=$value;};
$check('native_bridge_class_present',class_exists($native));
$policy=$model::current();
$check('owner_company_cost_source',$policy['currency']==='EGP'&&$policy['role']==='self_cost');
$sourceIds=get_posts(['post_type'=>'transportations','post_status'=>['publish','draft'],
 'posts_per_page'=>1,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_source_key','meta_value'=>'Cairo:1']);
$source=(int)($sourceIds[0]??0);
$check('source_exists',$source>0);
$baseline=$native::resolve(['transportation_id'=>$source,'vehicle'=>'hiace'],$policy);
$check('native_source_inherits_company_per_km',is_array($baseline)&&
 $baseline['profile']['per_km']===$policy['profiles']['hiace']['per_km']);
$check('native_source_dispatch_default_off',is_array($baseline)&&
 $baseline['dispatch_enabled']===false);
$check('reference_pdf_cost_is_1400',is_array($baseline)&&
 $baseline['source_actual_cost_egp']===1400.0);
$check('missing_vehicle_pdf_rejected',
 is_wp_error($native::resolve(['transportation_id'=>$source,'vehicle'=>'h1'],$policy)));
$check('invalid_source_rejected',
 is_wp_error($native::resolve(['transportation_id'=>1,'vehicle'=>'hiace'],$policy)));
$mock=[
 'vehicle_key'=>'hiace','mode'=>'override','dispatch_mode'=>'enabled',
 'fixed'=>'150','per_km'=>'20','per_drive_min'=>'','per_wait_min'=>'',
 'overnight'=>'','min_fare'=>'','deadhead_pct'=>''
];
$metadata=function($value,$id,$key,$single)use($source,$mock){
 if((int)$id===$source&&$key==='trans_vehicle_cost_overrides_v1')
  return [[$mock]];
 return $value;
};
add_filter('get_post_metadata',$metadata,10,4);
$custom=$native::resolve(['transportation_id'=>$source,'vehicle'=>'hiace'],$policy);
$check('transportation_cost_override_amount',is_array($custom)&&$custom['profile']['per_km']===20.0);
$check('transportation_dispatch_override_enabled',is_array($custom)&&
 $custom['dispatch_enabled']===true&&$custom['dispatch_policy_source']==='transportation_vehicle_row');
$check('blank_cost_inherits_nonzero',is_array($custom)&&
 $custom['profile']['per_drive_min']===$policy['profiles']['hiace']['per_drive_min']);
$check('zero_cost_dispatch_base_valid',is_array($custom)&&$custom['profile']['fixed']===150.0);
remove_filter('get_post_metadata',$metadata,10);
$badMock=$mock;$badMock['per_km']=-1;
$badFilter=function($value,$id,$key,$single)use($source,$badMock){
 if((int)$id===$source&&$key==='trans_vehicle_cost_overrides_v1')
  return [[$badMock]];
 return $value;
};
add_filter('get_post_metadata',$badFilter,10,4);
$invalid=$native::resolve(['transportation_id'=>$source,'vehicle'=>'hiace'],$policy);
$check('negative_native_override_rejected',is_wp_error($invalid));
remove_filter('get_post_metadata',$badFilter,10);
$rates=get_posts(['post_type'=>'transport-rates',
 'post_status'=>['publish','pending','draft'],
 'posts_per_page'=>1,'fields'=>'ids','suppress_filters'=>true]);
$rate=(int)($rates[0]??0);
$check('transport_rate_object_found',$rate>0);
$rateMeta=[
 'source_post_id'=>$source,'trans_vehicle_category_key'=>'hiace',
 'trans_cost_model_mode'=>'override','trans_dispatch_mode'=>'disabled',
 'trans_cost_fixed_override'=>'170','trans_cost_per_km_override'=>'25',
 'trans_cost_per_drive_min_override'=>'','trans_cost_per_wait_min_override'=>'',
 'trans_cost_overnight_override'=>'','trans_cost_min_fare_override'=>'',
 'trans_cost_deadhead_pct_override'=>'','trans_cost_markup_override_pct'=>'15',
 'trans_price_role'=>'self_cost','currency'=>'EGP','trans_cost_amount'=>'1400'
];
$filterRate=function($value,$id,$key,$single)use($rate,$rateMeta){
 if((int)$id===$rate&&array_key_exists($key,$rateMeta))
  return $rateMeta[$key];
 return $value;
};
add_filter('get_post_metadata',$filterRate,10,4);
$res=$native::resolve(['transport_rate_id'=>$rate,'vehicle'=>'hiace'],$policy);
$check('native_rate_binds_source',is_array($res)&&$res['transportation_id']===$source);
$check('rate_override_wins_company_and_transportation',is_array($res)&&$res['profile']['per_km']===25.0);
$check('rate_base_numeric_override',is_array($res)&&$res['profile']['fixed']===170.0);
$check('rate_dispatch_disable_wins',is_array($res)&&
 $res['dispatch_enabled']===false&&$res['dispatch_policy_source']==='native_transport_rate');
$check('rate_customer_price_fields_not_used',is_array($res)&&
 isset($res['existing_native_operational_fields']));
$bad=$native::resolve(['transport_rate_id'=>$rate,
 'transportation_id'=>$source+1,'vehicle'=>'hiace'],$policy);
$check('rate_source_mismatch_rejected',is_wp_error($bad));
remove_filter('get_post_metadata',$filterRate,10);
$check('public_checkout_never_authorized',
 $policy['mode']==='simulation_only'&&!service_channel_allowed('transportations','standalone'));
$failed=array_keys(array_filter($tests,static fn($v)=>!$v));
echo wp_json_encode(['site'=>home_url(),'tested'=>count($tests),
 'passed'=>count($tests)-count($failed),'failed'=>$failed,
 'real_records_mutated'=>0,'commercial_prices_approved'=>false])."\n";
if($failed)exit(1);
