<?php
defined('ABSPATH') || exit;
$checks=[];$check=static function(string $name,bool $condition)use(&$checks):void{$checks[$name]=$condition;};
$base=['tour_id'=>60623,'tier_id'=>2354,'stage_key'=>'hotel-cairo-01',
 'property_id'=>36140,'travel_date'=>'2026-12-10','adults'=>2,'children'=>0,
 'assume_per_person_per_night'=>false];
$run=static fn(array $input)=>MAD4B_Package_Component_Readiness_V1::scenario($input);
$a=$run($base);
$check('source_reference_found',is_array($a)&&is_array($a['source_rate'])&&
 $a['source_rate']['status']==='draft_stop_sale_reference');
$check('original_window_inherited',is_array($a)&&$a['source_rate']['calendar_from']==='2026-10-01'&&
 $a['source_rate']['calendar_to']==='2027-04-30');
$check('base_per_person_not_per_night',is_array($a)&&
 $a['source_rate']['price_basis_recorded']==='per_person'&&
 $a['source_rate']['per_night_basis_verified']===false);
$check('no_total_without_opt_in',is_array($a)&&$a['hypothetical_total_usd']===null);
$check('never_customer_quote_or_checkout',is_array($a)&&
 $a['checkout_authorized']===false&&$a['customer_quote_authorized']===false&&
 $a['write_performed']===false&&$a['supplier_cost_receipt_approved']===false);
$check('source_cell_present',is_array($a)&&preg_match('/^[A-Z][0-9]+$/',$a['source_rate']['source_cell'])===1);
$b=$run(array_replace($base,['assume_per_person_per_night'=>true]));
$expected=$a['source_rate']['source_amount']*$a['adults']*$a['nights'];
$check('what_if_arithmetic_opt_in_only',is_array($b)&&$b['hypothetical_total_usd']===$expected);
$check('what_if_not_commercial',is_array($b)&&$b['checkout_authorized']===false&&
 $b['source_rate']['status']==='draft_stop_sale_reference');
$check('supplier_notes_review_included',is_array($a)&&!empty($a['supplier_note_evidence']));
$check('hotel_selection_not_fabricated',is_array($a)&&
 in_array('hotel_candidate_not_selected_in_package',$a['missing_commercial_evidence'],true));
$testError=static function($label,array $mutate,string $code)use($run,$base,$check):void{
 $res=$run(array_replace($base,$mutate));
 $check($label,is_wp_error($res)&&$res->get_error_code()===$code);
};
$testError('unknown_field_rejected',['magic_price'=>1],'package_scenario_unknown_input');
$testError('children_rejected',['children'=>1],'package_scenario_selection_invalid');
$testError('single_rejected',['adults'=>1],'package_scenario_selection_invalid');
$testError('wrong_tour_rejected',['tour_id'=>123],'package_scenario_selection_invalid');
$testError('invalid_level_rejected',['tier_id'=>999],'package_scenario_selection_invalid');
$testError('wrong_hotel_rejected',['property_id'=>12345],'package_scenario_property_not_eligible');
$future=$run(array_replace($base,['travel_date'=>'2028-12-10']));
$check('future_year_excluded',is_array($future)&&$future['source_rate']===null&&
 $future['hypothetical_total_usd']===null);
$testError('invalid_assumption_rejected',['assume_per_person_per_night'=>'approved'],'package_scenario_assumption_invalid');
$testError('unknown_stage_rejected',['stage_key'=>'missing-stage-01'],'package_scenario_hotel_stage_missing');
$testError('invalid_date_rejected',['travel_date'=>'2026-13-42'],'package_readiness_date_window_invalid');
$check('nightly_basis_not_signed',is_array($b)&&
 $b['customer_quote_authorized']===false&&$b['source_rate']['per_night_basis_verified']===false);
$before=get_current_user_id();wp_set_current_user(0);
$denied=$run($base);wp_set_current_user($before);
$check('unauthorized_denied',is_wp_error($denied)&&$denied->get_error_code()==='package_scenario_access_denied');
$rate=(int)$a['source_rate']['rate_id'];
$filter=static function($value,$id,$key,$single)use($rate){
 if((int)$id!==$rate||$key!=='base_amount')return $value;
 return $single?'999999':['999999'];
};
add_filter('get_post_metadata',$filter,99,4);
try {$tampered=$run($base);
 $check('edited_draft_amount_fails_original_cell_match',
  is_wp_error($tampered)&&$tampered->get_error_code()==='package_scenario_saved_quote_source_drift');
} finally {remove_filter('get_post_metadata',$filter,99);}
$check('live_original_rate_still_draft',get_post_status($rate)==='draft'&&
 get_post_meta($rate,'stop_sale',true)==='1');
$report=MAD4B_Package_Component_Readiness_V1::report('2026-12-10','2026-12-10');
$eligible=[];
foreach($report['tours'] as $tour)if((int)$tour['tour_id']===60623)
 foreach($tour['levels']['Gold']['stages'] as $stage)
  if($stage['key']==='hotel-cairo-01')$eligible=array_column($stage['hotel_candidate_options']??[],'id');
$check('native_board_offers_eligible_hotel',in_array(36140,$eligible,true));
$failed=array_keys(array_filter($checks,static fn($x)=>!$x));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.package-stay-internal-scenario-regression.v1',
 'passed'=>count($checks)-count($failed),'total'=>count($checks),'failures'=>$failed,
 'example'=>['source_cell'=>$a['source_rate']['source_cell']??'',
 'source_amount'=>$a['source_rate']['source_amount']??0,
 'hypothetical_total'=>$b['hypothetical_total_usd']??null],
 'rates_published'=>0,'checkout_authorized'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($failed&&class_exists('WP_CLI'))WP_CLI::halt(1);

