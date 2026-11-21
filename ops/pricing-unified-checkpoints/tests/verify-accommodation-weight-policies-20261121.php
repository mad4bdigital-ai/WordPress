<?php
/** QA acceptance: run with wp --user=1 eval-file ... on each company site.
 * Temporarily changes only a dedicated DRAFT QA ONLY Property 67063 weight
 * metadata, restores the exact prior value, never touches any Rate or Price.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Accommodation_Weight_Policies_V1',false))
    require_once '/tmp/mad4b-accommodation-weight-policies-v1.php';
$cls='MAD4B_Accommodation_Weight_Policies_V1';
$count=0;$fail=[];
$check=static function(bool $test,string $name)use(&$count,&$fail){
 $count++;if(!$test)$fail[]=$name;
};
$expectError=static function($v,string $code)use($check){
 $check(is_wp_error($v) && $v->get_error_code()===$code,$code);
};
$valid=['schema'=>1,'mode'=>'shadow_only','reference'=>'double','weights'=>[
 'single_pct'=>'30','double_pct'=>'0','triple_pct'=>'-10',
 'standard_pct'=>'0','deluxe_pct'=>'20','suite_pct'=>null]];
$check(!is_wp_error($cls::validate($valid)),'valid_percentage_values');
$expectError($cls::validate(['schema'=>2,'mode'=>'shadow_only','reference'=>'double','weights'=>[]]),
 'accommodation_weight_schema_invalid');
$expectError($cls::validate(array_replace($valid,['reference'=>'anything'])),
 'accommodation_weight_reference_invalid');
$expectError($cls::validate(array_replace($valid,['weights'=>array_replace($valid['weights'],['single_pct'=>'301'])])),
 'accommodation_weight_invalid_single_pct');
$expectError($cls::validate(array_replace($valid,['weights'=>array_replace($valid['weights'],['triple_pct'=>'-81'])])),
 'accommodation_weight_invalid_triple_pct');
$expectError($cls::validate(array_replace($valid,['weights'=>array_replace($valid['weights'],['double_pct'=>'10'])])),
 'accommodation_weight_reference_must_be_zero');
$expectError($cls::validate(array_replace($valid,['weights'=>array_replace($valid['weights'],['deluxe_pct'=>'abc'])])),
 'accommodation_weight_invalid_deluxe_pct');
$expectError($cls::validate(array_replace($valid,['weights'=>array_replace($valid['weights'],['single_pct'=>true])])),
 'accommodation_weight_invalid_single_pct');
$expectError($cls::authorize('another',0),'accommodation_weight_scope_invalid');
$check($cls::authorize('global',0)===true,'admin_can_read_global');
$record=$cls::read('global');
$check(is_array($record)&&strlen($record['revision'])===64,'global_revision_exists');
$property=36181;
$relation=jet_engine()->relations->get_active_relations()[223]??null;
$children=$relation?$relation->get_children($property):[];
$unit=isset($children[0]['child_object_id'])?(int)$children[0]['child_object_id']:0;
$check($unit>0&&get_post_type($unit)==='units','site_native_relation_ids');
$neutral=$cls::resolve($property,$unit);
$check(is_array($neutral)&&$neutral['commercial_authority']===false,'neutral_no_commercial_authority');
$check(is_array($neutral)&&$neutral['reference']==='double','default_reference_double');
$check(is_array($neutral)&&$neutral['weights']['single_pct']==='0.00','no_invented_single_weight');
$input=['property_id'=>$property,'unit_id'=>$unit,'base_amount'=>'35',
 'currency'=>'USD','price_unit'=>'per_person_night','occupancy'=>'double',
 'room_grade'=>'standard','origin'=>'sheet_unapproved'];
$p=$cls::preview($input);
$check(is_array($p)&&$p['estimated_unit_amount']==='35.0000','unmodified_reference');
$check(is_array($p)&&$p['checkout_ready']===false,'preview_never_checkout');
$check(is_array($p)&&$p['rate_written']===false,'preview_never_writes_rate');
$check(is_array($p)&&$p['priced_season']===null,'no_invented_season');
$check(is_array($p)&&$p['supplier_contract_approved']===false,'no_invented_supplier');
$check(is_array($p)&&$p['base_board_included_verified']===false,'no_invented_board');
$expectError($cls::preview(array_replace($input,['origin'=>'explicit_rate'])),
 'accommodation_weight_explicit_rate_must_be_resolved_by_native_rate');
$expectError($cls::preview(array_replace($input,['unit_id'=>'1'])),
 'accommodation_weight_post_invalid');
$expectError($cls::preview(array_replace($input,['currency'=>'USD/EUR'])),
 'accommodation_weight_currency_invalid');
$expectError($cls::preview(array_replace($input,['base_amount'=>'0'])),
 'accommodation_weight_preview_reference_amount_invalid');
$expectError($cls::preview(array_replace($input,['price_unit'=>'guessed'])),
 'accommodation_weight_preview_basis_invalid');
$expectError($cls::preview(array_replace($input,['room_grade'=>'triple'])),
 'accommodation_weight_preview_dimensions_invalid');
$expectError($cls::preview(array_replace($input,['occupancy'=>'quadruple'])),
 'accommodation_weight_preview_dimensions_invalid');
$beforeRates=wp_count_posts('property-rates');
$beforeTourRates=wp_count_posts('tour-rates');
$qaId=67063;$qa=get_post($qaId);
$check($qa instanceof WP_Post&&$qa->post_type==='properties'&&$qa->post_status==='draft'
 &&str_contains($qa->post_title,'QA ONLY'),'qa_is_sandbox_original_property');
$key=$cls::META;
$prior=get_post_meta($qaId,$key,true);
$exists=metadata_exists('post',$qaId,$key);
$check(!$exists,'qa_weight_metadata_clean_before_test');
$qaOk=false;
if(!$fail && !$exists){
 try{
  $initial=$cls::read('property',$qaId);
  $saved=$cls::save('property',$qaId,$valid,$initial['revision']);
  $check(is_array($saved)&&$saved['write_performed']===true,'qa_save_cas_write');
  $qaOk=is_array($saved)&&$saved['write_performed']===true;
  $now=$cls::read('property',$qaId);
  $check(is_array($now)&&$now['policy']['weights']['single_pct']==='30.00','qa_readback_weights');
  $stale=$cls::save('property',$qaId,$valid,$initial['revision']);
  $expectError($stale,'accommodation_weight_stale_editor_revision');
  $preview=$cls::preview(array_replace($input,[
    'property_id'=>$qaId,'unit_id'=>0,'occupancy'=>'single','room_grade'=>'deluxe',
    'origin'=>'manual_preview']));
  $check(is_array($preview)&&$preview['estimated_unit_amount']==='54.6000',
    '30percent_single_then_20percent_deluxe_shadow');
  $check(is_array($preview)&&$preview['status']==='estimate_only_not_supplier_cost',
    'qa_shadow_stays_unapproved');
  $check(is_array($preview)&&$preview['effective_policy']['sources']['single_pct']==='property:67063',
    'qa_source_trace');
 } finally {
  if($qaOk){
   // This test only ever introduced its own new draft QA property metadata.
   delete_post_meta($qaId,$key);
   wp_cache_delete($qaId,'post_meta');
  }
 }
}
$check(metadata_exists('post',$qaId,$key)===$exists,'qa_clean_restored');
$afterRates=wp_count_posts('property-rates');
$afterTourRates=wp_count_posts('tour-rates');
foreach(['publish','draft','pending'] as $s)
 $check((int)($beforeRates->$s??0)===(int)($afterRates->$s??0),'unchanged_property_rates_'.$s);
foreach(['publish','draft','pending'] as $s)
 $check((int)($beforeTourRates->$s??0)===(int)($afterTourRates->$s??0),'unchanged_tour_rates_'.$s);
$output=['status'=>$fail?'FAIL':'PASS','site'=>home_url(),'checks'=>$count,
 'failed'=>$fail,'original_property'=>$property,'related_unit'=>$unit,
 'qa_test_property'=>$qaId,'restored'=>!metadata_exists('post',$qaId,$key),
 'published_property_rates_before'=>(int)($beforeRates->publish??0),
 'published_property_rates_after'=>(int)($afterRates->publish??0),
 'rate_writes'=>0,'checkout_writes'=>0];
echo wp_json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
