<?php
$check=[];$ensure=static function($name,$result)use(&$check){$check[$name]=(bool)$result;};
$src=static fn(string $kind,string $source,int $id)=>[
 'price_kind'=>$kind,'source'=>$source,'id'=>$id,'amount'=>25.0,'currency'=>'USD','price_unit'=>'per_person_night'
];
$choose=static fn(array $items)=>MAD4B_Stay_Layered_Pricing_V1::choose($items);
$ensure('source_order_exact',MAD4B_Stay_Layered_Pricing_V1::PRIORITY===
 ['unit_rate','property_rate','unit_direct','property_direct']);
$ensure('cost_order_exact',MAD4B_Stay_Layered_Pricing_V1::COST_PRIORITY===
 ['unit_rate','property_rate']);
$all=['unit_rate'=>[$src('cost','unit_rate',100)],
 'property_rate'=>[$src('cost','property_rate',200)],
 'unit_direct'=>[$src('sell','unit_direct',300)],
 'property_direct'=>[$src('sell','property_direct',400)]];
$res=$choose($all);
$ensure('unit_cost_wins',!is_wp_error($res)&&$res['cost_reference']['source']==='unit_rate');
$ensure('cost_never_becomes_sell',!is_wp_error($res)&&$res['sell']['source']==='unit_direct');
unset($all['unit_rate']);
$res=$choose($all);
$ensure('property_cost_fallback',!is_wp_error($res)&&$res['cost_reference']['source']==='property_rate');
$all=['unit_rate'=>[$src('sell','unit_rate',101)],
 'property_rate'=>[$src('sell','property_rate',201)],
 'unit_direct'=>[$src('sell','unit_direct',301)]];
$res=$choose($all);
$ensure('unit_sell_rate_over_property_rate',!is_wp_error($res)&&$res['sell']['id']===101);
unset($all['unit_rate']);$res=$choose($all);
$ensure('property_sell_rate_over_unit_direct',!is_wp_error($res)&&$res['sell']['id']===201);
unset($all['property_rate']);$res=$choose($all);
$ensure('direct_last_resort_indicative',!is_wp_error($res)&&$res['sell']['id']===301);
$all=['unit_direct'=>[$src('cost','unit_direct',301)],
 'property_direct'=>[$src('cost','property_direct',401)]];
$res=$choose($all);
$ensure('direct_cost_is_not_supplier_receipt',!is_wp_error($res)&&$res['cost_reference']===null&&$res['direct_cost_not_authoritative']===true);
$ensure('no_sell_from_cost',!is_wp_error($res)&&$res['sell']===null);
$err=$choose(['unit_rate'=>[$src('cost','unit_rate',1),$src('cost','unit_rate',2)]]);
$ensure('overlapping_rates_fail_closed',is_wp_error($err)&&$err->get_error_code()==='stay_layer_overlapping_rates');
$err=$choose(['other'=>[$src('cost','other',1)]]);
$ensure('unknown_source_fail_closed',is_wp_error($err));
$empty=MAD4B_Stay_Layered_Pricing_V1::preview([
 'property_id'=>36140,'unit_id'=>0,'checkin'=>'2026-12-05',
 'nights'=>2,'adults'=>2,'children'=>0]);
$ensure('live_no_fake_supplier_cost',is_array($empty)&&$empty['cost_reference']===null);
$ensure('live_preview_no_checkout',is_array($empty)&&$empty['checkout_ready']===false);
$ensure('live_preview_no_price_write',is_array($empty)&&$empty['mutation_performed']===false);
$ensure('cost_preview_does_not_reprice',is_array($empty)&&$empty['price_status']==='unpriced_or_cost_only');
$rate=get_posts(['post_type'=>'property-rates','post_status'=>'draft','fields'=>'ids','posts_per_page'=>1]);
if($rate){
 $id=(int)$rate[0];
 $original=(int)get_post_meta($id,'prop_related_unit_id',true);
 $scope=MAD4B_Package_Stage_Rate_Guard_V1::costRateScope($id,
  ['rate_id'=>$id,'unit_id'=>$original],[$original]);
 $ensure('legacy_unit_cost_identity',!empty($scope['valid'])&&$scope['scope']==='unit_rate');
 $invalid=MAD4B_Package_Stage_Rate_Guard_V1::costRateScope($id,
  ['rate_id'=>$id,'unit_id'=>0],[]);
 $ensure('unit_specific_cannot_masquerade_as_property',empty($invalid['valid']));
 $f=static function($value,$obj,$key,$single)use($id){
   if((int)$obj!==$id)return $value;
   if($key==='prop_related_unit_id')return $single?'0':['0'];
   if($key==='mad4b_rate_scope_v1')return $single?'property':['property'];
   return $value;
 };
 add_filter('get_post_metadata',$f,99,4);
 try {
  $scope=MAD4B_Package_Stage_Rate_Guard_V1::costRateScope($id,['rate_id'=>$id,'unit_id'=>0],[]);
  $ensure('property_rate_cost_scope_without_unit',!empty($scope['valid'])&&$scope['scope']==='property_rate');
  $ensure('property_rate_still_needs_native_quote',!empty($scope['requires_native_unit_allocation']));
  $bad=MAD4B_Package_Stage_Rate_Guard_V1::costRateScope($id,['rate_id'=>$id,'unit_id'=>999],[999]);
  $ensure('property_scope_wrong_receipt_unit_rejected',empty($bad['valid']));
 } finally {remove_filter('get_post_metadata',$f,99);}
}
$panel=MAD4B_Stay_Pricing_Controls_Panel_V1::preview([
  'property_id'=>36140,'unit_id'=>0,'checkin'=>'2026-12-05',
  'nights'=>2,'adults'=>2,'children'=>0]);
$ensure('native_panel_preview_authorized',is_array($panel));
$ensure('native_panel_cost_precedence',is_array($panel)&&
 ($panel['source_precedence']['cost']??null)===['unit_rate','property_rate']);
$ensure('native_panel_effective_double_baseline',is_array($panel)&&
 ($panel['effective_weight_policy']['reference']??null)==='double');
$ensure('native_panel_explicit_rate_not_reweighted',is_array($panel)&&
 ($panel['effective_weight_policy']['explicit_rate_reweighted']??null)===false);
$ensure('native_panel_weight_no_commercial_authority',is_array($panel)&&
 ($panel['effective_weight_policy']['commercial_authority']??null)===false);
$r=MAD4B_Package_Stage_Rate_Guard_V1::review(60623,2354,'2026-12-05','2026-12-19');
$ensure('real_package_remains_blocked',empty($r['ready'])&&!empty($r['blockers']));
$ensure('original_guard_still_requires_unit',in_array('stage_units_not_selected',array_column($r['blockers'],'code'),true)|| 
  array_any($r['stages'],static fn($s)=>in_array('stage_units_not_selected',$s['blocked']??[],true)));
$failed=array_keys(array_filter($check,static fn($x)=>!$x));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.native-property-rate-fallback.v1',
 'passed'=>count($check)-count($failed),'total'=>count($check),'failed'=>$failed,
 'new_rates_created'=>false,'new_policy_stores_created'=>false,'checkout_authorized'=>false],
 JSON_UNESCAPED_SLASHES)."\n";
if($failed&&class_exists('WP_CLI'))WP_CLI::halt(1);

