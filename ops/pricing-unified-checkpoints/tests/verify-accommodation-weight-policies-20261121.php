<?php
/**
 * Safe, repeatable read-only live QA for original JetEngine accommodation weights.
 * Invoke: wp --user=1 eval-file /tmp/mad4b-accommodation-weight-acceptance.php
 *
 * No mutation, no deletion, no publication, no rate/checkout writes.
 * The one-time real Save/CAS/Restore QA was executed separately on the
 * draft-only 67063 original Property and recorded in the companion report.
 */
defined('ABSPATH')||exit;
$cls='MAD4B_Accommodation_Weight_Policies_V1';
$checks=0;$fail=[];
$ok=static function($condition,$name)use(&$checks,&$fail){
 $checks++;if(!$condition)$fail[]=$name;
};
$error=static function($value,$code)use($ok){
 $ok(is_wp_error($value)&&$value->get_error_code()===$code,$code);
};
$ok(class_exists($cls,false),'plugin_loaded_from_original_mu');
$global=$cls::read('global');
$ok(is_array($global)&&$global['configured']===true,'global_neutral_saved');
$ok(($global['policy']['reference']??'')==='double','global_reference_double');
foreach(['single_pct','double_pct','triple_pct','standard_pct','deluxe_pct','suite_pct'] as $field)
 $ok(($global['policy']['weights'][$field]??null)==='0.00','neutral_'.$field);
$example=['schema'=>1,'mode'=>'shadow_only','reference'=>'double',
 'weights'=>['single_pct'=>'30','double_pct'=>'0','triple_pct'=>'-10',
     'standard_pct'=>'0','deluxe_pct'=>'20','suite_pct'=>null]];
$ok(!is_wp_error($cls::validate($example)),'valid_custom_weight_schema');
$error($cls::validate(array_replace($example,['reference'=>'invalid'])),
 'accommodation_weight_reference_invalid');
$error($cls::validate(array_replace($example,['weights'=>array_replace($example['weights'],['double_pct'=>'5'])])),
 'accommodation_weight_reference_must_be_zero');
$error($cls::validate(array_replace($example,['weights'=>array_replace($example['weights'],['single_pct'=>'301'])])),
 'accommodation_weight_invalid_single_pct');
$error($cls::validate(array_replace($example,['weights'=>array_replace($example['weights'],['triple_pct'=>'-81'])])),
 'accommodation_weight_invalid_triple_pct');
$error($cls::authorize('invalid',0),'accommodation_weight_scope_invalid');
$rateCountsBefore=wp_count_posts('property-rates');
$tourRateCountsBefore=wp_count_posts('tour-rates');
$isway=home_url()==='https://wayfatours.com';
$property=36181;$suite=$isway?71874:71394;
$superior=$isway?71880:71400;$unclass=$isway?71875:71395;
$linked=jet_engine()->relations->get_active_relations()[223]->get_children($property);
$ok(in_array($suite,array_map(static fn($row)=>(int)($row['child_object_id']??0),$linked),true),
 'original_relation_223_site_identity');
$effective=$cls::resolve($property,$suite);
$ok(is_array($effective)&&$effective['commercial_authority']===false,'noncommercial_resolution');
$ok(is_array($effective)&&$effective['reference']==='double','effective_reference');
$ok(is_array($effective)&&$effective['weights']['single_pct']==='0.00','effective_neutral');
$input=['property_id'=>$property,'unit_id'=>$suite,'base_amount'=>'35',
 'currency'=>'USD','price_unit'=>'per_person_night','occupancy'=>'double',
 'room_grade'=>'suite','origin'=>'sheet_unapproved'];
$preview=$cls::preview($input);
$ok(is_array($preview)&&$preview['estimated_unit_amount']==='35.0000','neutral_sheet_preview');
$ok(is_array($preview)&&($preview['room_grade_evidence']['recognized_grade']??'')==='suite',
 'verified_official_suite_class');
$ok(is_array($preview)&&$preview['commercial_ready']===false,'commercial_always_false');
$ok(is_array($preview)&&$preview['rate_written']===false,'no_rate_write');
$ok(is_array($preview)&&$preview['checkout_ready']===false,'no_checkout_write');
$ok(is_array($preview)&&$preview['priced_season']===null,'season_not_fabricated');
$ok(is_array($preview)&&$preview['supplier_contract_approved']===false,'supplier_not_fabricated');
$ok(is_array($preview)&&$preview['base_board_included_verified']===false,'board_not_fabricated');
$ok(is_array($preview)&&($preview['meal_base_policy']['base_board']??'')==='bed-breakfast','aswan_base_bb');
$coast=$cls::preview(array_replace($input,['property_id'=>36084,'unit_id'=>0,'room_grade'=>'standard']));
$ok(is_array($coast)&&($coast['meal_base_policy']['base_board']??'')==='all-inclusive','sharm_base_ai');
$cruise=$cls::preview(array_replace($input,['property_id'=>35935,'unit_id'=>0,'room_grade'=>'standard']));
$ok(is_array($cruise)&&($cruise['meal_base_policy']['base_board']??'')==='full-board','cruise_base_fb');
$error($cls::preview(array_replace($input,['room_grade'=>'standard'])),
 'accommodation_weight_unit_room_grade_mismatch');
$error($cls::preview(array_replace($input,['property_id'=>36084,'unit_id'=>$superior,
 'occupancy'=>'triple','room_grade'=>'standard'])),
 'accommodation_weight_occupancy_exceeds_native_unit_capacity');
$unknown=$cls::preview(array_replace($input,['unit_id'=>$unclass,'room_grade'=>'standard']));
$ok(is_array($unknown)&&($unknown['room_grade_evidence']['verified']??true)===false,
 'no_guessed_standard_room_class');
$error($cls::preview(array_replace($input,['origin'=>'explicit_rate'])),
 'accommodation_weight_explicit_rate_must_be_resolved_by_native_rate');
$error($cls::preview(array_replace($input,['currency'=>'USD/EUR'])),
 'accommodation_weight_currency_invalid');
$error($cls::preview(array_replace($input,['price_unit'=>'per_week'])),
 'accommodation_weight_preview_basis_invalid');
$error($cls::preview(array_replace($input,['base_amount'=>'0'])),
 'accommodation_weight_preview_reference_amount_invalid');
$error($cls::preview(array_replace($input,['occupancy'=>'quadruple'])),
 'accommodation_weight_preview_dimensions_invalid');
$badRev=$cls::save('global',0,$example,str_repeat('0',64));
$error($badRev,'accommodation_weight_stale_editor_revision');
$globalNow=$cls::read('global');
$ok(is_array($globalNow)&&$globalNow['revision']===$global['revision'],
 'stale_write_did_not_change_global');
$qa=$cls::read('property',67063);
$ok(is_array($qa)&&($qa['policy']['reference']??null)==='inherit',
 'qa_reference_reverted');
$ok(is_array($qa)&&array_key_exists('single_pct',$qa['policy']['weights'])&&
    $qa['policy']['weights']['single_pct']===null,'qa_weights_reverted');
$rateCountsAfter=wp_count_posts('property-rates');
$tourRateCountsAfter=wp_count_posts('tour-rates');
foreach(['publish','draft','pending'] as $status){
 $ok((int)($rateCountsBefore->$status??0)===(int)($rateCountsAfter->$status??0),
     'no_property_rate_'.$status);
 $ok((int)($tourRateCountsBefore->$status??0)===(int)($tourRateCountsAfter->$status??0),
     'no_tour_rate_'.$status);
}
echo wp_json_encode(['status'=>$fail?'FAIL':'PASS','site'=>home_url(),
 'checks'=>$checks,'failed'=>$fail,'suite_unit_id'=>$suite,
 'global_configuration_status'=>'neutral_shadow_only',
 'qa_sandbox_restored_to_inherit'=>true,'commercial_rate_writes'=>0,
 'checkout_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
