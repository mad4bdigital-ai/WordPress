<?php
/**
 * QA-only fixture on existing isolated Local Woo lab.
 * No original commercial product, rate, order, payment or user mutation.
 * Idempotent via a namespaced fixture manifest; fail closed outside Local.
 */
if (PHP_SAPI!=='cli') exit(90);
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'
    || !function_exists('partner_rights_local_write_hold_active')
    || !partner_rights_local_write_hold_active()) exit(91);
global $wpdb;
$manifestKey='commerce_cart_lab_fixture_20261026_v1';
$manifest=get_option($manifestKey,[]);
if(!is_array($manifest))exit(92);
$items=$manifest['items']??[];
function qa_build($key,$type,$title,$meta=[]){
 global $items;
 $id=(int)($items[$key]??0);
 if($id && (get_post_type($id)!==$type
    || get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='cart-lab-20261026:'.$key))
    throw new RuntimeException("Existing fixture identity mismatch: ".$key);
 if(!$id){
   $id=wp_insert_post(['post_type'=>$type,'post_title'=>'QA ONLY · '.$title,
      'post_status'=>'draft','post_content'=>'Local-only demonstration. No commercial availability, supplier confirmation or checkout.'],true);
   if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
   update_post_meta($id,'_booking_experience_qa_pricing_fixture','cart-lab-20261026:'.$key);
   update_post_meta($id,'_cart_lab_key',$key);
   $items[$key]=$id;
 } else wp_update_post(['ID'=>$id,'post_title'=>'QA ONLY · '.$title]);
 foreach($meta as $name=>$value)update_post_meta($id,$name,$value);
 // The original booking module excludes QA-marked items from public access.
 wp_update_post(['ID'=>$id,'post_status'=>'publish','post_password'=>'qa-only-unlisted-fixture']);
 return (int)$id;
}
$rates=[];$properties=[];$units=[];$unitRates=[];$extras=[];
$seasons=[['key'=>'winter','start'=>'2026-11-01','end'=>'2027-04-30','term'=>2736],
          ['key'=>'summer','start'=>'2027-05-01','end'=>'2027-10-15','term'=>2731]];
$tour=qa_build('tour','tours-and-activities','Interactive Booking Variations · Cairo, Nile, Red Sea · 10 Days',[
 'tour_mode'=>'tour','tour_public_status'=>'0','tour_rates_status'=>'1',
 'tour_extras_status'=>'1','tour_base_currency'=>'USD','tour_price_unit'=>'per_person',
 'tour_sub_title'=>'Three destinations · staged hotels · selectable meals and extras · testing only',
 'tour_origin_address'=>'Cairo, Egypt','tour_vehicles_transport_status'=>'0',
 'tour_air_transport_status'=>'0','tour_train_transport_status'=>'0'
]);
wp_set_object_terms($tour,[194],'tour-types',false);
// The Tour is the QA source of the per-person unit; validate against JetEngine's Rate glossary.
$tourUnit=(string)get_post_meta($tour,'tour_price_unit',true);
$unitGlossary=shared_product_unit_glossary('tour-rates','unit_type');
if(is_wp_error($unitGlossary)||!isset($unitGlossary['values'][$tourUnit]))
 throw new RuntimeException('QA parent unit must match Tour Rate glossary');
wp_set_object_terms($tour,[2353,2354,2355,2356],'package_levels',false);
// Real taxonomy terms are reused, not altered.
$places=[
 ['Cairo','Pyramids Heritage Hotel','City Garden Boutique'],
 ['Luxor','Nile Riverside Hotel','Historic Palace Stay'],
 ['Red Sea','Coral Bay Resort','Sunset Marina Lodge']
];
$boardTerms=get_terms(['taxonomy'=>'board-type','hide_empty'=>false]);
$boardIds=!is_wp_error($boardTerms)?array_slice(array_map(fn($t)=>(int)$t->term_id,$boardTerms),0,3):[];
foreach($places as $stageNo=>$stage){
 $group=[];
 foreach(array_slice($stage,1) as $alt=>$name){
   $pkey='property-'.$stageNo.'-'.$alt;
   $property=qa_build($pkey,'properties',$name.' · '.$stage[0],[
     'prop_supports_overnight_stay'=>'supported','_cart_lab_region'=>$stage[0]]);
   $group[]=$property;$properties[]=$property;
   foreach(['Classic Twin','Family Suite'] as $uindex=>$roomName){
     $unit=qa_build('unit-'.$stageNo.'-'.$alt.'-'.$uindex,'units',$name.' · '.$roomName,[
       'unit_readiness'=>'validated','unit_max_occupancy'=>$uindex===0?2:5,
       'unit_optional_services'=>[
          ['unit_service_code'=>'airport_transfer','unit_price'=>'30','unit_currency'=>'USD','unit_charge_basis'=>'per_stay'],
          ['unit_service_code'=>'late_checkout','unit_price'=>'20','unit_currency'=>'USD','unit_charge_basis'=>'per_stay']
       ]
     ]);
     $units[]=$unit;
     $rel=$wpdb->prefix.'jet_rel_default';
     $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$rel` WHERE rel_id=%s AND parent_object_id=%d AND child_object_id=%d", '223',$property,$unit));
     if(!$existing){
       $ok=$wpdb->insert($rel,['rel_id'=>'223','parent_rel'=>0,'parent_object_id'=>$property,'child_object_id'=>$unit],['%s','%d','%d','%d']);
       if(!$ok)throw new RuntimeException('JetEngine unit relation insert failed');
     }elseif($existing!==1)throw new RuntimeException('Duplicate unit relationship detected');
     foreach([0,1] as $meal){
       $rate=qa_build('unit-rate-'.$stageNo.'-'.$alt.'-'.$uindex.'-'.$meal,
         'property-rates',$name.' · '.$roomName.' · '.($meal?'Meal Upgrade':'Breakfast'),[
         'prop_related_property_id'=>$property,'prop_related_unit_id'=>$unit,
         'prop_price_unit'=>'per_room_night','prop_min_nights'=>1,'prop_max_nights'=>30,
         'prop_max_adults'=>$uindex===0?2:3,'prop_max_children'=>$uindex===0?0:2,
         'base_amount'=>95+($stageNo*55)+($alt*32)+($uindex*68)+($meal*26),
         'currency'=>'USD','valid_from'=>'2026-11-01','valid_to'=>'2027-10-15',
         'stop_sale'=>'','prop_board_type'=>$boardIds[$meal]??($boardIds[0]??0)
       ]);
       $unitRates[]=$rate;
     }
   }
 }
 $stages[$stageNo]=['key'=>['cairo_stay','luxor_stay','sea_stay'][$stageNo],
   'label'=>$stage[0].' stay · choose hotel / room / meals',
   'day_offset'=>[0,3,6][$stageNo],'nights'=>[3,3,3][$stageNo],
   'traveler_group'=>'all','destination_term_id'=>0,'properties'=>$group,
   'price_policy'=>$stageNo===0?'included':($stageNo===1?'additional':'inquiry'),
   'transport_ids'=>[]];
}
foreach([2353=>'Silver',2354=>'Gold',2355=>'Platinum',2356=>'Diamond'] as $pkg=>$title){
 foreach($seasons as $season){
   $rate=qa_build('rate-'.$pkg.'-'.$season['key'],'tour-rates',
       $title.' · '.$season['key'].' · 10 days',[
     'related_tour_id'=>$tour,'related_package_term_id'=>$pkg,'unit_type'=>$tourUnit,
     'tour_rate_start_date'=>strtotime($season['start'].' UTC'),
     'tour_rate_end_date'=>strtotime($season['end'].' UTC'),
     'has_properties'=>'1','related_properties_id'=>array_merge(...array_map(fn($x)=>$x['properties'],$stages)),
     '_booking_experience_stay_stages_v1'=>$stages,
     'avilabile_status'=>'1','stop_sale'=>'','has_allotment_inevntory'=>'0',
     'base_currency'=>'USD','single_price'=>1900+($pkg-2353)*230+($season['key']==='summer'?160:0),
     'double_price'=>1350+($pkg-2353)*190+($season['key']==='summer'?130:0),
     'triple_price'=>1100+($pkg-2353)*165+($season['key']==='summer'?100:0),
     'tour_rate_note'=>'QA price sample only, not an approved supplier rate'
   ]);
   wp_set_object_terms($rate,[$season['term']],'season',false);
   $rates[]=$rate;
 }
}
// A partial date-specific override Rate exercises Rate and date precedence.
$partial=qa_build('rate-gold-winter-partial','tour-rates','Gold · Nov 10–20 · partial promotional Rate',[
 'related_tour_id'=>$tour,'related_package_term_id'=>2354,'unit_type'=>$tourUnit,
 'tour_rate_start_date'=>strtotime('2026-11-10 UTC'),
 'tour_rate_end_date'=>strtotime('2026-11-20 UTC'),'base_currency'=>'USD',
 'single_price'=>1960,'double_price'=>1260,'triple_price'=>1030,
 'avilabile_status'=>'1','has_properties'=>'1','stop_sale'=>'',
 'has_allotment_inevntory'=>'0','related_properties_id'=>array_merge(...array_map(fn($x)=>$x['properties'],$stages)),
 '_booking_experience_stay_stages_v1'=>$stages
]);
wp_set_object_terms($partial,[2736],'season',false);
$rates[]=$partial;
foreach(['Pyramids Sunrise Tour','Nile Felucca Evening','Temple Sound & Light','Snorkeling Excursion'] as $n=>$label){
 $child=qa_build('extra-'.$n,'tours-and-activities',$label,[
   'tour_mode'=>'tour','tour_rates_status'=>'1','tour_base_currency'=>'USD',
   'tour_price_unit'=>'per_person','tour_public_status'=>'0','_cart_lab_extra_only'=>'1']);
 $extraUnit=(string)get_post_meta($child,'tour_price_unit',true);
 if(!isset($unitGlossary['values'][$extraUnit]))throw new RuntimeException('QA extra unit missing');
 $rate=qa_build('extra-rate-'.$n,'tour-rates',$label.' · add-on per traveler',[
   'related_tour_id'=>$child,'related_package_term_id'=>2354,'unit_type'=>$extraUnit,
   'tour_rate_start_date'=>strtotime('2026-11-01 UTC'),
   'tour_rate_end_date'=>strtotime('2027-10-15 UTC'),
   'base_currency'=>'USD','single_price'=>55+($n*30),'double_price'=>55+($n*30),
   'avilabile_status'=>'1','stop_sale'=>''
 ]);
 $extras[]=['tour_id'=>$child,'rate_id'=>$rate,'label'=>$label,'sample_price'=>55+($n*30)];
}
update_post_meta($tour,'_cart_lab_extra_catalog',$extras);
// Keep QA fixtures in the preexisting hidden/public-exclusion manifest.
$qa=get_option('booking_experience_qa_pricing_fixtures_v1',[]);
if(!is_array($qa))$qa=[];
if(!isset($qa['post_ids'])||!is_array($qa['post_ids']))$qa['post_ids']=[];
foreach($items as $key=>$id)$qa['post_ids']['cart_lab_'.$key]=(int)$id;
update_option('booking_experience_qa_pricing_fixtures_v1',$qa,false);
$manifest=['schema'=>'cart-lab-fixture-v1','type'=>'qa_not_commercial','items'=>$items,
 'tour_id'=>$tour,'rate_ids'=>$rates,'property_ids'=>$properties,
 'unit_ids'=>$units,'unit_rate_ids'=>$unitRates,'extras'=>$extras,
 'default_rate_id'=>$rates[2], 'public_booking_enabled'=>false];
update_option($manifestKey,$manifest,false);
echo wp_json_encode(['result'=>'CREATED_OR_REUSED_QA_ONLY',
 'tour'=>$tour,'tour_rates'=>count($rates),'properties'=>count($properties),
 'units'=>count($units),'unit_rates'=>count($unitRates),'extras'=>count($extras),
 'default_rate_id'=>$manifest['default_rate_id'],'stage_mode'=>'multi_stage',
 'original_business_order_mutated'=>false],JSON_UNESCAPED_SLASHES);
