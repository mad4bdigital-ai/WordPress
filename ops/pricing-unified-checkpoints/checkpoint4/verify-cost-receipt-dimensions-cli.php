<?php
if(!defined('WP_CLI')||!WP_CLI){return;}
$gate='MAD4B_Package_Cost_Receipt_Gate_V1';
if(!class_exists($gate,false))throw new Exception('GATE_NOT_LOADED');
$check=function($name,$receipt,$context,$approved,$requiredReason='')use($gate){
 $r=$gate::validate($receipt,$context);
 $good=(($r['qualified']??false)===$approved && (!$requiredReason || in_array($requiredReason,$r['blockers']??[],true)));
 echo wp_json_encode(['test'=>$name,'pass'=>$good,'qualified'=>$r['qualified'],'blockers'=>$r['blockers']],JSON_UNESCAPED_SLASHES).PHP_EOL;
 if(!$good)throw new Exception('CP4_VALIDATOR_REGRESSION_'.$name);
};
$realUnit=home_url()==='https://wayfatours.com'?70802:71358;
$context=[
 'tour_id'=>57562,'stage_key'=>'hotel-cairo-01','kind'=>'hotel','currency'=>'USD',
 'service_date'=>'2026-12-12','last_service_date'=>'2026-12-14',
 'adults'=>2,'children'=>0,'infants'=>0,'property_id'=>36197,'unit_ids'=>[$realUnit]
];
$receipt=[
 'contract'=>$gate::CONTRACT,'status'=>'approved','kind'=>'hotel','basis'=>'per_room_night',
 'tour_id'=>57562,'stage_key'=>'hotel-cairo-01','currency'=>'USD','cost_minor'=>10000,
 'amount_role'=>'supplier_cost_not_retail','supplier_mode'=>'self','supplier_ref'=>'self',
 'cost_method'=>'documented_actual','source_is_estimate'=>false,
 'source_document_sha256'=>hash('sha256','test-document'),
 'source_document_ref'=>'QA-NON-COMMERCIAL-TEST','source_document_kind'=>'actual_cost_record',
 'valid_from'=>'2026-12-01','valid_to'=>'2027-01-15',
 'occupancy'=>['adults'=>2,'children'=>0,'infants'=>0],
 'property_id'=>36197,'unit_id'=>$realUnit,'meal_plan'=>'BB',
 'availability'=>['status'=>'confirmed','service_date'=>'2026-12-12','available_quantity'=>1,
    'source_ref'=>'QA-STUB','checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)],
 'approval_ref'=>'QA-MOCK-NOT-VALID-IN-PRODUCTION',
];
$check('unsigned_always_denied',$receipt,$context,false,'receipt_signed_authority_unverified');
add_filter('mad4b/package-cost-receipt/verify-authority',static function($valid,$r,$c){return ($r['approval_ref']??'')==='QA-MOCK-NOT-VALID-IN-PRODUCTION';},10,3);
$check('complete_test_fixture',$receipt,$context,true);
$c=$context;$c['currency']='EUR';$check('currency_mismatch',$receipt,$c,false,'receipt_currency_mismatch_or_unverified_fx');
$c=$context;$c['service_date']='2027-02-01';$c['last_service_date']='2027-02-03';$check('date_outside_tariff',$receipt,$c,false,'receipt_service_date_not_covered');
$c=$context;$c['children']=1;$check('occupancy_drift',$receipt,$c,false,'receipt_occupancy_mismatch');
$r=$receipt;$r['availability']['expires_at']=gmdate('Y-m-d\TH:i:s\Z',time()-100);$check('expired_stock',$r,$context,false,'receipt_availability_not_verified_or_expired');
$r=$receipt;$r['cost_method']='estimate';$check('self_estimate',$r,$context,false,'self_supplier_actual_cost_document_missing');
$r=$receipt;$r['source_document_sha256']='';$check('no_real_document',$r,$context,false,'receipt_document_evidence_missing');
$r=$receipt;$r['unit_id']=11;$check('wrong_room_unit',$r,$context,false,'receipt_room_property_unit_mismatch');
$r=$receipt;$r['kind']='cruise';$r['basis']='per_cabin_night';$c=$context;$c['kind']='cruise';$check('cruise_program_missing',$r,$c,false,'receipt_cruise_program_cabin_or_departure_missing');
$r=$receipt;$r['kind']='sleeper_train';$r['basis']='per_cabin_trip';$r['train_id']=54552;$r['cabin_class']='Elite';$r['service_date']='2026-12-12';$r['boarding_station_id']=54231;$r['arrival_station_id']=54258;
$c=$context;$c['kind']='sleeper_train';$c['train_id']=54552;$c['train_number']='86';$check('ramses_not_86_boarding',$r,$c,false,'receipt_train_boarding_station_invalid');
remove_all_filters('mad4b/package-cost-receipt/verify-authority');
echo "CP4_COST_RECEIPT_GATE_TESTS_OK\n";
