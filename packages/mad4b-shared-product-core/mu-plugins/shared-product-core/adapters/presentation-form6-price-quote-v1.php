<?php
/** Local lab; read-only price preview for the existing Fluent Form #6. */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_presentation_form6_price_quote($raw) {
 if(!is_array($raw)||array_diff(array_keys($raw),
 ['tour_id','source','rate_id','travel_date','adults','children','infants','occupancy','extras']))
  return new WP_Error('form6_quote_invalid_fields');
 $tour=absint($raw['tour_id']??0);$source=$raw['source']??'';
 $rate=absint($raw['rate_id']??0);
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
  return new WP_Error('form6_quote_invalid_tour');
 $p=get_post($tour);
 $pricingTour=$tour;
 if($source==='rate'){
  $pricingTour=absint(get_post_meta($rate,'related_tour_id',true));
  $siblings=class_exists('ETG_Tour_Rate_Display')
   ?ETG_Tour_Rate_Display::language_ids($tour):[$tour];
  if(!in_array($pricingTour,$siblings,true))return new WP_Error('form6_quote_wpml_rate_not_sibling');
 }
 if($p->post_password!==''||get_post_meta($tour,'_booking_experience_qa_pricing_fixture',true)!=='')
  return new WP_Error('form6_quote_protected_tour');
 if(!in_array($source,['direct','rate'],true))return new WP_Error('form6_quote_source_invalid');
 if($source==='rate'){
  $rp=get_post($rate);
  if(!$rp||$rp->post_type!=='tour-rates'||$rp->post_status!=='publish'||
      $rp->post_password!==''||(int)get_post_meta($rate,'related_tour_id',true)!==$pricingTour||
      in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true)||
      get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)!=='')
   return new WP_Error('form6_quote_rate_unavailable');
 }elseif($rate!==0)return new WP_Error('form6_quote_direct_rate_forbidden');
 foreach(['adults','children','infants'] as $key){
  $v=$raw[$key]??null;
  if(!is_scalar($v)||!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',(string)$v))
   return new WP_Error('form6_quote_traveler_invalid');
 }
 $adults=(int)$raw['adults'];$children=(int)$raw['children'];$infants=(int)$raw['infants'];
 if($adults<1||$adults+$children+$infants>100)return new WP_Error('form6_quote_traveler_invalid');
 // Child/infant pricing is not inferable from adult person prices.
 if($children||$infants)return new WP_Error('form6_quote_age_pricing_requires_manual_review');
 $date=$raw['travel_date']??null;
 if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))
  return new WP_Error('form6_quote_date_invalid');
 $occupancy=$raw['occupancy']??'double';
 if(!in_array($occupancy,['single','double','triple'],true))return new WP_Error('form6_quote_occupancy_invalid');
 $extras=$raw['extras']??[];
 if(!is_array($extras)||!array_is_list($extras)||count($extras)>15)return new WP_Error('form6_quote_extras_invalid');
 $prepared=[];
 foreach($extras as $extra){
  if(!is_array($extra)||array_diff(array_keys($extra),['tour_id','persons']))return new WP_Error('form6_quote_extra_fields');
  $id=absint($extra['tour_id']??0);$count=absint($extra['persons']??$adults);
  if(!$id||$count<1||$count>$adults)return new WP_Error('form6_quote_extra_invalid');
  $prepared[]=['tour_id'=>$id,'rate_id'=>null,'quantities'=>['persons'=>$count]];
 }
 $payload=['schema_version'=>2,'tour_id'=>$pricingTour,'source'=>$source,
  'rate_id'=>$source==='rate'?$rate:null,'travel_date'=>$date,
  'audience'=>'general','occupancy'=>$occupancy,'booking_intent'=>'quote',
  'quantities'=>['persons'=>$adults],'selections'=>[],'extras'=>$prepared];
 $result=shared_product_booking_v2_preview($payload);
 if(is_wp_error($result))return new WP_Error($result->get_error_code(),'No eligible price preview');
 $total=$result['total']??null;$currency=$result['currency']??'';
 if(!is_numeric($total)||!is_finite((float)$total)||(float)$total<=0||
   !preg_match('/^[A-Z]{3}$/D',(string)$currency))return new WP_Error('form6_quote_amount_invalid');
 $lines=[];$extraPrices=[];
 foreach($result['lines']??[] as $line){
  $amount=$line['amount']??null;
  if(!is_numeric($amount)||!is_finite((float)$amount))return new WP_Error('form6_quote_line_invalid');
  $lines[]=['kind'=>$line['kind']??'additional','amount'=>round((float)$amount,2)];
  // Verified server quote only; no CPT price or currency copied into UI as a quote.
  if(($line['kind']??'')==='extra'){
   $extraId=absint($line['tour_id']??0);
   $unit=(string)($line['unit']??'');
   $quantity=$line['quantity']??null;
   if($extraId&&is_numeric($quantity)&&(float)$quantity>0&&
      in_array($unit,['per_person','per_trip','per_day','per_room','per_vehicle','per_cabin'],true)){
    $unitAmount=$unit==='per_person'?round((float)$amount/(float)$quantity,2):round((float)$amount,2);
    $extraPrices[(string)$extraId]=[
     'amount'=>$unitAmount,'currency'=>$currency,'unit'=>$unit,
     'status'=>'server_price_preview_only'];
   }
  }
 }
 return ['status'=>'price_preview_only','tour_id'=>$tour,
  'pricing_tour_id'=>$pricingTour,'source'=>$source,
  'rate_id'=>$rate?:null,'currency'=>$currency,'subtotal'=>round(array_sum(array_column($lines,'amount')),2),
  'total'=>round((float)$total,2),'lines'=>$lines,'extra_prices'=>$extraPrices,
  'pricing_scope'=>'tour_base_and_eligible_optional_activities_only',
  'component_adjustments_certified'=>false,'availability'=>'not_checked',
  'checkout_ready'=>false,'order_created'=>false];
}
/**
 * Informational, original-source price on the *marked QA fixture only*.
 * A protected QA fixture MUST NOT be transformed into a sale certificate.
 * This endpoint never adds accommodation, payments, supplier confirmations,
 * child pricing, or arbitrary cross-currency amounts.
 */
function shared_presentation_form6_indicative_qa_rate($raw) {
 if(!is_array($raw)||array_diff(array_keys($raw),
 ['tour_id','source','rate_id','travel_date','adults','children','infants','occupancy','extras','stay_stages']))
  return new WP_Error('indicative_fields_invalid');
 $tour=absint($raw['tour_id']??0);$rate=absint($raw['rate_id']??0);
 $post=get_post($tour);$rp=get_post($rate);
 if(($raw['source']??'')!=='rate'||!$post||!$rp||
   $post->post_type!=='tours-and-activities'||$post->post_status!=='publish'||
   $rp->post_type!=='tour-rates'||$rp->post_status!=='publish'||
   $post->post_password!==''||$rp->post_password!==''||
   (int)get_post_meta($rate,'related_tour_id',true)!==$tour||
   !str_starts_with((string)get_post_meta($tour,'_booking_experience_qa_pricing_fixture',true),'cart-lab-')||
   !str_starts_with((string)get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true),'cart-lab-'))
  return new WP_Error('indicative_fixture_unavailable');
 foreach(['adults','children','infants'] as $key)
  if(!is_scalar($raw[$key]??null)||!preg_match('/^(0|[1-9][0-9]{0,2})$/D',(string)$raw[$key]))
   return new WP_Error('indicative_guest_invalid');
 $adults=(int)$raw['adults'];
 if($adults<1||$adults>50||(int)$raw['children']!==0||(int)$raw['infants']!==0)
  return new WP_Error('indicative_guest_pricing_pending');
 $occ=$raw['occupancy']??'';
 $slots=['single'=>1,'double'=>2,'triple'=>3];
 if(!isset($slots[$occ])||$adults%$slots[$occ]!==0)
  return new WP_Error('indicative_room_split_pending');
 $date=$raw['travel_date']??null;
 if(!is_string($date)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date))
  return new WP_Error('indicative_date_invalid');
 $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
 if(!$dt||$dt->format('Y-m-d')!==$date)return new WP_Error('indicative_date_invalid');
 $start=(int)get_post_meta($rate,'tour_rate_start_date',true);
 $end=(int)get_post_meta($rate,'tour_rate_end_date',true);
 if($start<1||$end<$start||$dt->getTimestamp()<$start||$dt->getTimestamp()>$end||
   in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true)||
   (string)get_post_meta($rate,'avilabile_status',true)==='0')
  return new WP_Error('indicative_date_unavailable');
 if(!function_exists('pricing_rate_presentation')||!function_exists('pricing_currency_convert')||
   !function_exists('pricing_display_currency'))return new WP_Error('indicative_pricing_unavailable');
 $presented=pricing_rate_presentation($rate);
 if(($presented['status']??'')!=='available')return new WP_Error('indicative_pricing_unavailable');
 $choice=null;
 foreach($presented['options']??[] as $option)
  if(($option['occupancy']??'')===$occ){$choice=$option;break;}
 if(!$choice||!is_numeric($choice['final']??null)||$choice['final']<=0)
  return new WP_Error('indicative_occupancy_unpriced');
 $source=(string)$presented['currency'];$display=pricing_display_currency();
 $base=round((float)$choice['final']*$adults,2);
 $selected=$raw['extras']??[];
 if(!is_array($selected)||!array_is_list($selected)||count($selected)>15)
  return new WP_Error('indicative_extras_invalid');
 $associated=get_post_meta($tour,'tour_optional_extras',true);
 $ids=array_map('intval',is_array($associated)?$associated:[]);
 $seen=[];$pricedExtras=0.0;$extraPending=0;
 foreach($selected as $extra){
  if(!is_array($extra)||array_diff(array_keys($extra),['tour_id','persons']))
   return new WP_Error('indicative_extra_fields_invalid');
  $extraId=absint($extra['tour_id']??0);$count=$extra['persons']??null;
  if(!$extraId||!in_array($extraId,$ids,true)||isset($seen[$extraId])||
     !is_scalar($count)||!preg_match('/^[1-9][0-9]{0,2}$/D',(string)$count)||
     (int)$count>$adults)
   return new WP_Error('indicative_extra_selection_invalid');
  $seen[$extraId]=true;
  $ep=get_post($extraId);
  if(!$ep||$ep->post_type!=='tours-and-activities'||$ep->post_status!=='publish'||
     $ep->post_password!==''||get_post_meta($extraId,'tour_mode',true)!=='extra_trip'||
     in_array(strtolower((string)get_post_meta($extraId,'stop_sale',true)),['1','yes','true'],true))
   return new WP_Error('indicative_extra_unavailable');
  $rawAmount=get_post_meta($extraId,'start_price',true);
  $extraCurrency=(string)get_post_meta($extraId,'tour_base_currency',true);
  if(!str_starts_with((string)get_post_meta($extraId,'_booking_experience_qa_pricing_fixture',true),'cart-lab-')||
     get_post_meta($extraId,'tour_price_unit',true)!=='per_person'||
     $extraCurrency!==$source||!is_scalar($rawAmount)||!is_numeric($rawAmount)||
     !is_finite((float)$rawAmount)||(float)$rawAmount<=0){
    $extraPending++;continue;
  }
  $pricedExtras+=round((float)$rawAmount*(int)$count,2);
 }
 $stays=shared_form6_review_stays($tour,$rate,$date,$adults,$raw['stay_stages']??[]);
 if(is_wp_error($stays))return $stays;
 $additional=0.0;$stayCurrencyPending=0;
 foreach($stays['source_totals'] as $c=>$amount){
  if($c===$source)$additional+=(float)$amount;
  elseif(function_exists('pricing_currency_convert')){
   $fx=pricing_currency_convert((float)$amount,$c,$source);
   if($fx===null||!is_numeric($fx))$stayCurrencyPending++;
   else $additional+=(float)$fx;
  }else $stayCurrencyPending++;
 }
 $totalSource=round($base+$pricedExtras+$additional,2);
 $converted=pricing_currency_convert($totalSource,$source,$display);
 if($converted===null||$converted<=0)return new WP_Error('indicative_currency_unavailable');
 return ['status'=>'indicative_preview_only','currency'=>$display,
   'total'=>$converted,'adult_count'=>$adults,'occupancy'=>$occ,
   'extras_included'=>count($selected)>0&&$extraPending===0,
   'extras_pending_count'=>$extraPending,
   'stay_lines'=>$stays['rows'],'stay_pending_count'=>$stays['pending_count']+$stayCurrencyPending,
   'stay_additional_included'=>$additional>0,'accommodation_included'=>false,
   'source_currency'=>$source,'checkout_ready'=>false,
   'availability'=>'not_checked','order_created'=>false];
}
add_action('rest_api_init',static function(){
 register_rest_route('shared-product/v1','/tour-form-indicative-qa-preview',[
  'methods'=>WP_REST_Server::READABLE,'permission_callback'=>'__return_true',
  'callback'=>static function(WP_REST_Request $request){
   $input=$request->get_query_params();
   $input=array_intersect_key($input,array_flip([
     'tour_id','source','rate_id','travel_date','adults',
     'children','infants','occupancy']));
   $extras=$request->get_param('extras');
   $input['extras']=is_string($extras)&&strlen($extras)<=2048?json_decode($extras,true):[];
   $stays=$request->get_param('stay_stages');
   $input['stay_stages']=is_string($stays)&&strlen($stays)<=4096?json_decode($stays,true):[];
   // A price depends on party size, occupancy, current FX and selected
   // extras: NEVER cache one visitor's estimate as another's.
   do_action('litespeed_control_set_nocache','Local QA quote estimate');
   $result=shared_presentation_form6_indicative_qa_rate($input);
   $response=is_wp_error($result)
    ?new WP_Error($result->get_error_code(),'Estimated price unavailable',['status'=>422])
    :rest_ensure_response($result);
   if($response instanceof WP_REST_Response){
    $response->header('Cache-Control','private, no-store, max-age=0');
    $response->header('X-Robots-Tag','noindex, nofollow');
   }
   return $response;
  }
 ]);
 register_rest_route('shared-product/v1','/tour-form-price-preview',[
  'methods'=>WP_REST_Server::CREATABLE,'permission_callback'=>'__return_true',
  'callback'=>static function(WP_REST_Request $request){
   $result=shared_presentation_form6_price_quote($request->get_json_params());
   if(is_wp_error($result))return new WP_Error($result->get_error_code(),
     'Price preview unavailable',['status'=>422]);
   return rest_ensure_response($result);
  }
 ]);
});

/**
 * Retain source-verified stage/property preferences for staff review only.
 * This intentionally does NOT turn a selected hotel into a priced stay.
 */
function shared_presentation_form6_validate_stay_choices($tour,$rate,$rows){
 if(!is_array($rows)||!array_is_list($rows)||count($rows)>20)
  return new WP_Error('form6_stay_selection_invalid');
 if(!$rows)return [];
 if(get_post_type($tour)!=='tours-and-activities'||
    get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||
    (int)get_post_meta($rate,'related_tour_id',true)!==(int)$tour)
  return new WP_Error('form6_stay_rate_unverified');
 $defined=get_post_meta($rate,'_booking_experience_stay_stages_v1',true);
 if(!is_array($defined)||count($defined)>20)return new WP_Error('form6_stay_stage_source_missing');
 $allowed=[];
 foreach($defined as $stage){
  $key=$stage['key']??null;
  if(!is_string($key)||!preg_match('/^[a-z0-9_-]{2,45}$/D',$key)||
     !is_array($stage['properties']??null))return new WP_Error('form6_stay_stage_source_invalid');
  $allowed[$key]=array_map('intval',$stage['properties']);
 }
 $out=[];$seen=[];
 foreach($rows as $row){
  if(!is_array($row)||array_diff(array_keys($row),['stage_key','property_id'])||
     !isset($row['stage_key'],$row['property_id'])||
     !is_string($row['stage_key'])||
     !preg_match('/^[a-z0-9_-]{2,45}$/D',$row['stage_key'])||
     !is_int($row['property_id'])||
     isset($seen[$row['stage_key']])||
     !in_array($row['property_id'],$allowed[$row['stage_key']]??[],true)||
     get_post_type($row['property_id'])!=='properties'||
     get_post_status($row['property_id'])!=='publish')
   return new WP_Error('form6_stay_choice_not_linked');
  $seen[$row['stage_key']]=true;
  $out[]=['stage_key'=>$row['stage_key'],'property_id'=>$row['property_id']];
 }
 return $out;
}

// REV82 Local Lab: retain a server-verified quote snapshot within the existing
// Fluent Form #6 submission. This is NOT a WooCommerce order or price approval.
add_filter('fluentform/filter_insert_data',static function($insert){
 if(wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||
    !SHARED_PRODUCT_LAB_ONLY||!is_array($insert)||
    (int)($insert['form_id']??0)!==6)return $insert;
 $raw=$_POST['shared_tour_selection_v2']??null;
 // Fluent Forms AJAX sends the serialized form as POST[data], not as flat POST fields.
 if($raw===null&&is_string($_POST['data']??null)){
  parse_str(wp_unslash($_POST['data']),$submissionFields);
  $raw=$submissionFields['shared_tour_selection_v2']??null;
 }
 if(!is_string($raw)||strlen($raw)>4096||$raw==='')return $insert;
 $input=json_decode(wp_unslash($raw),true);
 $response=json_decode((string)($insert['response']??''),true);
 if(!is_array($input)||!is_array($response))return $insert;
 // AJAX has no trustworthy is_singular() main query. The original form's
 // embedded post ID is the required server-side context proof. A copied
 // Form #6 on a Property/Transport/Page must keep its ordinary submission
 // semantics even if custom Tour hidden fields are accidentally present.
 $embedded=$_POST['__fluent_form_embded_post_id']??
   ($submissionFields['__fluent_form_embded_post_id']??null);
 $tourScope=filter_var($embedded,FILTER_VALIDATE_INT,
   ['options'=>['min_range'=>1]])?:0;
 if(!$tourScope||$tourScope!==absint($input['tour_id']??0)||
    get_post_type($tourScope)!=='tours-and-activities'||
    get_post_status($tourScope)!=='publish')return $insert;
 $record=['version'=>2,'state'=>'requires_review','checkout_ready'=>false,
   'availability'=>'not_checked','order_created'=>false];
 $params=['tour_id','source','rate_id','travel_date','adults','children','infants','occupancy','extras','stay_stages'];
 if(array_diff(array_keys($input),$params))return $insert;
 foreach(['adults'=>'no_of_adults','children'=>'no_of_children','infants'=>'no_of_infants'] as $key=>$formField){
  if(!isset($response[$formField])||
     (string)$response[$formField]!== (string)($input[$key]??''))return $insert;
 }
 $rawDate=$response['datetime']??'';
 if(!is_string($rawDate))return $insert;
 $formDate=null;
 foreach(['Y-m-d','d-M-Y','d/m/Y','m/d/Y'] as $format){
  $parsed=DateTimeImmutable::createFromFormat('!'.$format,trim($rawDate));
  if($parsed&&$parsed->format($format)===trim($rawDate)){
   $formDate=$parsed->format('Y-m-d');break;
  }
 }
 if($formDate!==(string)($input['travel_date']??''))return $insert;
 // Never persist a browser-supplied total. Always re-quote on the server.
 $priceInput=$input;
 unset($priceInput['stay_stages']);
 $quote=shared_presentation_form6_price_quote($priceInput);
 $record['tour_id']=absint($input['tour_id']??0);
 $record['source']=in_array($input['source']??'', ['direct','rate'],true)?$input['source']:'unknown';
 $record['rate_id']=absint($input['rate_id']??0);
 $record['travel_date']=$formDate;
 // Pricing basis (single/double/triple Rate tier) is not proof that a
 // hotel or room is part of the booking. Ingest room occupancy only when
 // MU Presentation confirms accommodation linked to the selected Rate.
 require_once __DIR__.'/presentation-form6-occupancy-policy-v1.php';
 $stayPolicy=shared_form6_accommodation_policy_v1($record['tour_id']);
 $hasStay=$record['rate_id']>0&&
   !empty($stayPolicy['rates_with_stay'][(string)$record['rate_id']]);
 $basis=in_array($input['occupancy']??'', ['single','double','triple'],true)
  ?$input['occupancy']:null;
 $record['pricing_occupancy_basis']=$basis;
 $record['occupancy']=$hasStay?$basis:null;
 $record['accommodation_applicable']=$hasStay;
 $stayChoices=$hasStay
  ?shared_form6_review_stays(
     $record['tour_id'],$record['rate_id'],$formDate,
     (int)($input['adults']??0),$input['stay_stages']??[])
  :(!empty($input['stay_stages'])
     ?new WP_Error('stay_unavailable_for_tour')
     :['rows'=>[],'pending_count'=>0]);
 $record['stay_stages']=is_wp_error($stayChoices)?[]:$stayChoices['rows'];
 $record['stay_review_required']=$hasStay;
 $record['stay_pricing_scope']=$hasStay
  ?'priced_components_or_supplier_review':'no_accommodation';
 if(is_wp_error($stayChoices))$record['stay_validation']='source_choice_unverified';
 else $record['stay_pending_count']=$stayChoices['pending_count'];
 $record['extras']=[];
 $record['extras_review_required']=false;
 $record['extras_validation']='not_selected';
 if(is_array($input['extras']??null)){
  // Server-side consent to an optional activity is based on the original
  // linked CPT relationship, not on a presentation-only QA/JSF view token.
  // A protected fixture never becomes a sale authority by this validation.
  $ids=[];
  if((string)get_post_meta($record['tour_id'],'tour_extras_status',true)==='1'){
   $rawLinks=get_post_meta($record['tour_id'],'tour_optional_extras',true);
   if(is_string($rawLinks))$rawLinks=maybe_unserialize($rawLinks);
   if(is_string($rawLinks))$rawLinks=explode(',',$rawLinks);
   if(is_array($rawLinks)&&count($rawLinks)<=100){
    foreach($rawLinks as $linked){
     if(!is_scalar($linked)||!ctype_digit(trim((string)$linked)))continue;
     $id=absint($linked);$p=get_post($id);
     if($id===$record['tour_id']||!$p||
        $p->post_type!=='tours-and-activities'||$p->post_status!=='publish'||
        $p->post_password!==''||!in_array((string)get_post_meta($id,'tour_mode',true),
          ['activity','extra_trip'],true)||
        (string)get_post_meta($id,'tour_public_status',true)==='0'||
        in_array(strtolower((string)get_post_meta($id,'stop_sale',true)),
          ['1','yes','true'],true))continue;
     $ids[$id]=true;
    }
   }
  }
  $seen=[];$invalid=false;
  foreach($input['extras'] as $extra){
   if(!is_array($extra)||array_diff(array_keys($extra),['tour_id','persons'])){
    $invalid=true;continue;
   }
   $id=absint($extra['tour_id']??0);$persons=$extra['persons']??null;
   if(!$id||!isset($ids[$id])||isset($seen[$id])||
      !is_scalar($persons)||!preg_match('/^[1-9][0-9]{0,2}$/D',(string)$persons)||
      (int)$persons>(int)($input['adults']??0)+(int)($input['children']??0)){
    $invalid=true;continue;
   }
   $seen[$id]=true;
   $record['extras'][]=['tour_id'=>$id,'persons'=>(int)$persons];
  }
  $record['extras_review_required']=!empty($record['extras']);
  $record['extras_validation']=$invalid?'source_choice_unverified':
    ($record['extras']?'linked_preferences':'not_selected');
 }

 if(is_wp_error($quote)){
  $record['reason']=$quote->get_error_code();
 }else{
  $record['state']='server_price_preview';
  $record['currency']=$quote['currency'];
  $record['total']=$quote['total'];
  $record['price_scope']=$quote['pricing_scope'];
  $record['rate_id']=$quote['rate_id'];
 }
 // Separate, bounded preference-only input: never a price or booking authority.
 // Verify links against the parent CPT and the selected Tour Rate before
 // persisting a request-for-review transport choice.
 $transportRaw=$_POST['shared_transport_preferences_v1']??null;
 if($transportRaw===null&&is_array($submissionFields??null))
  $transportRaw=$submissionFields['shared_transport_preferences_v1']??null;
 $record['transport_options']=[];
 $record['transport_review_required']=false;
 if(is_string($transportRaw)&&strlen($transportRaw)<=4096){
  $transport=json_decode(wp_unslash($transportRaw),true);
  if(is_array($transport)&&
     !array_diff(array_keys($transport),['tour_id','rate_id','options'])&&
     (int)($transport['tour_id']??0)===$record['tour_id']&&
     (int)($transport['rate_id']??0)===$record['rate_id']&&
     $record['rate_id']>0&&
     (int)get_post_meta($record['rate_id'],'related_tour_id',true)===$record['tour_id']&&
     is_array($transport['options']??null)&&
     array_is_list($transport['options'])&&count($transport['options'])<=30&&
     class_exists('Shared_Product_Presentation_Transport_Context_v1')){
    $sources=Shared_Product_Presentation_Transport_Context_v1::categories();
    $seenTransport=[];
    foreach($transport['options'] as $choice){
     if(!is_array($choice)||array_diff(array_keys($choice),['transport_id','group']))continue;
     $group=(string)($choice['group']??'');
     $category=str_starts_with($group,'transport-')?substr($group,10):'';
     if(!isset($sources[$category])||$group!=='transport-'.$category)continue;
     $source=(string)$sources[$category]['source'];
     if((string)get_post_meta($record['tour_id'],(string)$sources[$category]['enabled'],true)!=='1')continue;
     $rawLinks=get_post_meta($record['tour_id'],$source,true);
     $allowed=array_map('absint',is_array($rawLinks)?$rawLinks:[]);
     $id=absint($choice['transport_id']??0);
     if(!$id||isset($seenTransport[$id])||!in_array($id,$allowed,true)||
       get_post_type($id)!=='transportations'||get_post_status($id)!=='publish')continue;
     $seenTransport[$id]=true;
     $record['transport_options'][]=['transport_id'=>$id,'group'=>$group,'review_required'=>true];
    }
    $record['transport_review_required']=!empty($record['transport_options']);
   }
 }
 $response['_shared_tour_booking_intent_v2']=$record;
 $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
 return $insert;
},25,1);

/**
 * Keep the old Fluent post-feed from creating a tour_orders CPT for the
 * new nonfinancial Form #6 request. Fluent Forms remains the original record.
 * Does not suppress real user notifications, CRM plugins, other forms or
 * legacy submissions without a verified modern booking intent.
 */
add_action('fluentform/before_form_actions_processing',
    static function($submissionId,$formData,$form) {
        if(wp_get_environment_type()!=='local'||
            !defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY||
            (int)($form->id??0)!==6||!is_numeric($submissionId))return;
        global $wpdb,$wp_filter;
        $saved=$wpdb->get_var($wpdb->prepare(
            "SELECT response FROM {$wpdb->prefix}fluentform_submissions
             WHERE id=%d AND form_id=6 LIMIT 1",(int)$submissionId));
        if(!is_string($saved))return;
        $data=json_decode($saved,true);
        $intent=$data['_shared_tour_booking_intent_v2']??null;
        if(!is_array($intent)||(int)($intent['version']??0)!==2||
           ($intent['checkout_ready']??true)!==false||
           ($intent['order_created']??true)!==false)return;
        $tour=(int)($intent['tour_id']??0);$rate=(int)($intent['rate_id']??0);
        $source=(string)($intent['source']??'');
        if(get_post_type($tour)!=='tours-and-activities'||
           get_post_status($tour)!=='publish'||
           !in_array($source,['direct','rate'],true)||
           ($intent['availability']??'')!=='not_checked')return;
        // Direct retail-price Tours intentionally do not own a Tour Rate.
        // Both valid commercial entry paths remain nonfinancial enquiries.
        if($source==='direct'){
            if($rate!==0)return;
        }else{
            $siblings=class_exists('ETG_Tour_Rate_Display')
                ?ETG_Tour_Rate_Display::language_ids($tour):[$tour];
            if(get_post_type($rate)!=='tour-rates'||
               get_post_status($rate)!=='publish'||
               !in_array((int)get_post_meta($rate,'related_tour_id',true),
                   array_map('intval',$siblings),true))return;
        }
        $hook=$wp_filter['fluentform/before_form_actions_processing']??null;
        if(!is_object($hook)||!isset($hook->callbacks))return;
        foreach((array)$hook->callbacks as $priority=>$callbacks) {
            foreach($callbacks as $candidate) {
                $fn=$candidate['function']??null;
                if(is_array($fn)&&
                   $fn[0] instanceof \FluentFormPro\Components\Post\PostFormHandler &&
                   ($fn[1]??null)==='onFormSubmissionInserted')
                    remove_action('fluentform/before_form_actions_processing',
                        $fn,$priority);
            }
        }
        do_action('commerce_nonfinancial_source_submission_persisted',
            (int)$submissionId,$intent);
    },0,3);

/**
 * A saved nonfinancial inquiry must not be described as a confirmed booking
 * or send the guest through checkout. Use the existing Fluent confirmation UI.
 */
add_filter('fluentform/submission_confirmation',
    static function($response,$form,$confirmation=null,$submissionId=null,$formData=null){
        if(wp_get_environment_type()!=='local'||!is_array($response)||
           !is_object($form)||!is_numeric($form->id??null)||
           !is_numeric($submissionId))return $response;
        global $wpdb;
        $json=$wpdb->get_var($wpdb->prepare(
            "SELECT response FROM {$wpdb->prefix}fluentform_submissions
             WHERE id=%d AND form_id=%d LIMIT 1",
            (int)$submissionId,(int)$form->id));
        if(!is_string($json))return $response;
        $data=json_decode($json,true);
        $request=$data['_shared_tour_booking_intent_v2']??null;
        if(!is_array($request)||($request['checkout_ready']??true)!==false||
           ($request['order_created']??true)!==false)return $response;
        $response['message']=__(
            'Request received. We will confirm your final price and availability.',
            'woocommerce');
        unset($response['redirectUrl']);
        return $response;
    },9999,5);
