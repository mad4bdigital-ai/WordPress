<?php
defined('ABSPATH')||exit;
$S=MAD4B_Property_Standard_Base_V1::class;
$B=MAD4B_Tour60623_Ops_Board_V1::class;
$site=home_url();
if(get_option($S::OPTION)!==$S::MODE||$S::mode()!==$S::MODE)throw new RuntimeException('property_first_not_persisted');
if(!has_filter('jet-engine/options-pages/raw-fields',[$S,'admin_fields']) ||
   !has_action('add_meta_boxes_tours-and-activities',[$B,'setup']))
 throw new RuntimeException('admin_settings_or_board_hook_missing');
$stages=$S::tour_hotel_stages(60623);
if(count($stages)!==2)throw new RuntimeException('hotel_stage_options_missing');
$alt=$S::hotel_alternatives_for_revised_cruise_itinerary();
$aswanId=$site==='https://tours.dev.mad4b.com'?71495:71980;
if(($alt['Aswan'][0]['id']??null)!==$aswanId || count($alt['Luxor']??[])!==2)
 throw new RuntimeException('non_geographic_hotel_substitute');
$cases=[];
foreach([78032,35927,35972,36197,36140,36142,36293] as $id){
 $p=$S::preview($id,'2027-01-13',3,2,0);
 if($p['amount']!==null||$p['rate_id']!==null||
    $p['base_weight']!==1.0||$p['unit_id']!==null||
    $p['checkout_ready']!==false||$p['read_only']!==true)
   throw new RuntimeException('rate_invented_or_unit_required_'.$id);
 $cases[]=['id'=>$id,'name'=>$p['name'],'type'=>$p['type'],'status'=>$p['status'],
 'retail_rate'=>$p['rate_id'],'standard_weight'=>$p['base_weight']];
}
$permitted=[];
foreach(['en'=>60623,'es'=>60661] as $lang=>$id){
 $post=get_post($id);
 if(!$B::is_target($id))throw new RuntimeException('board_target_missing_'.$lang);
 ob_start();$B::render($post);$html=ob_get_clean();
 foreach(['Property First','Standard base Rate','Tolip Gardens Hotel',
 'Triumph Luxury Hotel','Iberotel Luxor','Hilton Luxor',
 'Sleeping Train No.86','Sleeping Train No.87'] as $needle){
  if(!str_contains($html,$needle))throw new RuntimeException('board_missing_'.$lang.'_'.$needle);
 }
 if(str_contains($html,'Cabin / Unit')||str_contains($html,'71765 —')||str_contains($html,'71761 —'))
  throw new RuntimeException('board_still_requires_cabin_'.$lang);
 $permitted[$lang]=['id'=>$id,
  'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
  'type'=>get_post_type($id),'rendered'=>true,'bytes'=>strlen($html)];
}
$rateIds=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft','pending'],
 'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,
 'meta_query'=>[['key'=>'related_tour_id','value'=>60623,'compare'=>'=']]]);
$count=count($rateIds);
$expected=$site==='https://tours.dev.mad4b.com'?4:0;
if($count!==$expected)throw new RuntimeException('native_tour_rate_count_changed');
if((int)get_post_meta(60623,'tour_cruise_property_id',true)!==0||
   get_post_meta(60623,'train_transport_services',true)!=='')
 throw new RuntimeException('original_native_assignments_modified');
$quotes=[];
if(class_exists('\Commerce\Composition\SingleFluentPaymentRouter',false)){
 foreach(['2027-01-11','2027-01-13','2027-01-15'] as $date){
  $q=\Commerce\Composition\SingleFluentPaymentRouter::quoteDirect(60623,$date,'double',2,'USD');
  $quotes[$date]=is_wp_error($q)?['state'=>'rejected','code'=>$q->get_error_code()]:
    ['state'=>'preview_only','total'=>$q['total']??null,'currency'=>$q['currency']??null];
  if($site==='https://wayfatours.com'){
   if($date==='2027-01-15' && (!is_wp_error($q)||$q->get_error_code()!=='booking_schedule_not_eligible'))
    throw new RuntimeException('friday_departure_opened_by_policy');
   if($date!=='2027-01-15'&&(is_wp_error($q)||($q['total']??null)!=='2580.00'))
    throw new RuntimeException('form6_quote_modified_'.$date);
  }
 }
}
$result=[
 'contract'=>'mad4b.property-first-standard.live-acceptance.v1','site'=>$site,
 'mode'=>$S::mode(),'price_multiplier'=>1.0,
 'tour'=>['id'=>60623,'name'=>get_the_title(60623),'type'=>get_post_type(60623)],
 'editor_rendered'=>$permitted,'hotel_stages'=>count($stages),
 'aswan_fallback'=>$alt['Aswan'],'luxor_fallback'=>$alt['Luxor'],
 'standard_preview_cases'=>$cases,'original_tour_rates_count'=>$count,
 'original_tour_cruise_property'=>0,'original_native_train_select'=>'',
 'form6_direct_readonly_quotes'=>$quotes,
 'rates_or_orders_created'=>0,'supplier_costs_approved'=>false,
 'preview_requires_cabin'=>false,'revised_hotel_itinerary_auto_confirmed'=>false,
 'pass'=>true];
echo wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

