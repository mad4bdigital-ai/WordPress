<?php
/** Exact canonical stage key join; never convert a reference cost into commercial readiness. */
define('ABSPATH','/');
function add_filter($hook,$cb,$pri=10,$args=1){}
function verify($value,$why){$GLOBALS['passed']++;if(!$value)throw new RuntimeException($why);}
$GLOBALS['passed']=0;
require __DIR__.'/../wp-content/mu-plugins/mad4b-package-commercial-cost-preflight-v1.php';
$c='MAD4B_Package_Commercial_Cost_Preflight_V1';
$stages=[
 ['stage_key'=>'hotel-cairo-01','lodging_kind'=>'hotel','destination_key'=>'cairo','nights'=>2],
 ['stage_key'=>'hotel-luxor-02','lodging_kind'=>'hotel','destination_key'=>'luxor','nights'=>1],
 ['stage_key'=>'hotel-cairo-03','lodging_kind'=>'hotel','destination_key'=>'cairo','nights'=>1],
];
$preview=['stages'=>[
 ['stage_key'=>'hotel-cairo-01','kind'=>'hotel','nights'=>2,'pricing_property_id'=>12,'rate_id'=>31,'preliminary_cost'=>400.0,'currency'=>'USD','commercially_approved'=>false],
 ['stage_key'=>'hotel-luxor-02','kind'=>'hotel','nights'=>1,'pricing_property_id'=>13,'rate_id'=>32,'preliminary_cost'=>140.0,'currency'=>'USD','commercially_approved'=>false],
 ['stage_key'=>'hotel-cairo-03','kind'=>'hotel','nights'=>1,'pricing_property_id'=>12,'rate_id'=>31,'preliminary_cost'=>200.0,'currency'=>'USD','commercially_approved'=>false]]];
$b=array_map(static fn($s)=>['stage_key'=>$s['stage_key'],'supplier_cost_receipt'=>null],$stages);
$r=$c::join($stages,$preview,$b);
verify($r['all_stage_keys_covered']===true,'exact plan stage key binding');
verify($r['stage_count']===3,'all 3 hotel stages');
verify($r['hotel_costed_stage_count']===3,'hotel costs referenced 3 times');
verify($r['preliminary_hotel_reference_subtotal']===740.0,'3 independent nights and 3 stage instances');
verify($r['complete_qualified_stage_cost_minor']===null,'no commercial total');
verify($r['all_supplier_receipts_qualified']===false,'unapproved reference');
verify($r['stages'][0]['pricing_property_id']===12,'auto selected property only in report');
verify($r['stages'][1]['cost_rate_id']===32,'stage 2 rate retained');
verify($r['stages'][2]['reference_cost']===200.0,'same Cairo hotel not deduped across separate stages');
verify($r['stages'][0]['supplier_receipt_qualified']===false,'no supplier verified');
verify($r['stages'][0]['commercial_sale_authorized']===false,'no checkout');
$cruise=[['stage_key'=>'cruise-02','lodging_kind'=>'cruise','destination_key'=>'nile_cruise','nights'=>3]];
$r=$c::join(array_merge($stages,$cruise),$preview,$b);
verify($r['stage_count']===4,'cruise included');
verify($r['stages'][3]['reference_cost']===null,'cruise missing cost not zero');
verify(in_array('cruise_vessel_sailing_program_and_cost_receipt_required',$r['stages'][3]['missing'],true),'cruise blocker');
$train=[['stage_key'=>'train-02','lodging_kind'=>'sleeper_train','nights'=>1]];
$r=$c::join(array_merge($stages,$train),$preview,$b);
verify(in_array('sleeper_train_dated_route_class_and_cost_receipt_required',$r['stages'][3]['missing'],true),'train blocker');
$bad=$preview;$bad['stages'][0]['preliminary_cost']=-20;
$r=$c::join($stages,$bad,$b);
verify($r['hotel_costed_stage_count']===2&&$r['preliminary_hotel_reference_subtotal']===340.0,'invalid negative costs rejected');
$dup=$preview;$dup['stages'][]=$dup['stages'][1];
$r=$c::join($stages,$dup,$b);
verify(in_array('duplicate_or_missing_hotel_preview_key',$r['source_errors'],true),'duplicate hotel preview detected');
$r=$c::join([$stages[0],$stages[0]],$preview,$b);
verify(in_array('duplicate_or_missing_stage_key',$r['source_errors'],true),'duplicate canonical key blocked');
$r=$c::join([],[],[]);
verify($r['preliminary_hotel_reference_subtotal']===null,'no zero total when empty');
echo 'PASS '.$GLOBALS['passed'].'/'.$GLOBALS['passed'].' package commercial cost ledger and fail-closed stage coverage'.PHP_EOL;
