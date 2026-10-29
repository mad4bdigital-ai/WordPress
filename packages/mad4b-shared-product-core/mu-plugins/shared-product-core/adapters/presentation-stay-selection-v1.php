<?php
/** Validate read-only Tour stay preferences against the active canonical engine. */
defined('ABSPATH') || exit;
function shared_presentation_validate_stay_selection(int $rate,array $requested,array $context=[]){
 $fail=static fn($code)=>new WP_Error($code,'Stay selection is not valid for preview');
 $integer=static function($v,$min=0,$max=999){
  if(!is_int($v)&&!(is_string($v)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$v)))return null;
  $n=(int)$v;return $n>=$min&&$n<=$max?$n:null;
 };
 if(!array_is_list($requested)||count($requested)>32)return $fail('presentation_candidate_stay_shape');
 if(!function_exists('BookingExperience\\tour_stay_rate_plan')||!function_exists('BookingExperience\\tour_stay_options'))return $fail('presentation_stay_provider_missing');
 $plan=BookingExperience\tour_stay_rate_plan($rate);
 $no_stays=is_wp_error($plan)&&$plan->get_error_code()==='tour_accommodation_disabled';
 if(is_wp_error($plan)&&!$no_stays)return $plan;
 if($no_stays&&$requested)return $fail('presentation_candidate_accommodation_disabled');
 $allowed=[];$catalog=null;
 if(!$no_stays){
  if($plan['mode']==='simple_or'){
   if(!empty($plan['properties']))$allowed['simple']=['key'=>'simple','properties'=>$plan['properties'],'price_policy'=>'inquiry','nights'=>0,'traveler_group'=>'all'];
  }else foreach($plan['stages'] as $stage)$allowed[$stage['key']]=$stage;
  $catalog=BookingExperience\tour_stay_options($rate);if(is_wp_error($catalog))return $catalog;
 }
 $stages=[];$seen=[];$details=[];$requires=false;
 $counts=$context['counts']??[];
 $arrival=$context['arrival_date']??'';
 $date=is_string($arrival)?DateTimeImmutable::createFromFormat('!Y-m-d',$arrival,new DateTimeZone('UTC')):false;
 if($date&&$date->format('Y-m-d')!==$arrival)$date=false;
 foreach($requested as$entry){
  if(!is_array($entry)||array_diff(array_keys($entry),['stage_key','property_id','unit_id','unit_rate_id','board_term_id','board_type','rooms','adults','children','child_ages','checkin','checkout','room_occupancy','extras']))return $fail('presentation_candidate_stay_shape');
  $key=$entry['stage_key']??null;
  if(!is_string($key)||!isset($allowed[$key])||isset($seen[$key]))return $fail('presentation_candidate_stay_stage_unknown_or_duplicate');
  $stage=$allowed[$key];$property=$integer($entry['property_id']??null,1,PHP_INT_MAX);
  if(!$property||!in_array($property,array_map('intval',$stage['properties']),true)||!isset($catalog['properties'][(string)$property]))return $fail('presentation_candidate_stay_property_not_allowed');
  $seen[$key]=true;$policy=(string)$stage['price_policy'];if($policy==='inquiry')$requires=true;
  $row=['stage_key'=>$key,'property_id'=>$property,'price_policy'=>$policy,'nights'=>(int)$stage['nights']];
  $unit_id=array_key_exists('unit_id',$entry)?$integer($entry['unit_id'],1,PHP_INT_MAX):null;
  $rate_id=array_key_exists('unit_rate_id',$entry)?$integer($entry['unit_rate_id'],1,PHP_INT_MAX):null;
  if(array_key_exists('unit_id',$entry)&&!$unit_id||array_key_exists('unit_rate_id',$entry)&&!$rate_id)return $fail('presentation_stay_unit_id_invalid');
  $units=$catalog['properties'][(string)$property]['units'];$unit=null;$unit_rate=null;
  foreach($units as$one){
   if($unit_id&&(int)$one['unit_id']===$unit_id)$unit=$one;
   foreach($one['rates'] as$ur)if($rate_id&&(int)$ur['rate_id']===$rate_id){
    if($unit_id&&(int)$one['unit_id']!==$unit_id)return $fail('presentation_stay_rate_unit_mismatch');
    $unit=$one;$unit_id=(int)$one['unit_id'];$unit_rate=$ur;
   }
  }
  if($unit_id&&!$unit)return $fail('presentation_stay_unit_not_in_property');
  if($rate_id&&!$unit_rate)return $fail('presentation_stay_rate_not_in_unit');
  if($unit_id)$row['unit_id']=$unit_id;if($rate_id)$row['unit_rate_id']=$rate_id;
  if(array_key_exists('board_term_id',$entry)&&array_key_exists('board_type',$entry))return $fail('presentation_stay_duplicate_board');
  $board_raw=$entry['board_term_id']??$entry['board_type']??0;$board=$integer($board_raw,0,PHP_INT_MAX);
  if($board===null)return $fail('presentation_stay_board_invalid');
  if($board){
   if(!$unit_rate)return $fail('presentation_stay_board_requires_unit_rate');
   $boards=array_map('intval',array_column($unit_rate['meal_options'],'term_id'));
   if(!in_array($board,$boards,true))return $fail('presentation_stay_board_not_offered');
   $row['board_term_id']=$board;
  }
  $extras=$entry['extras']??[];
  if(!is_array($extras)||!array_is_list($extras)||count($extras)>20||count($extras)!==count(array_unique($extras,SORT_REGULAR)))return $fail('presentation_stay_extras_invalid');
  foreach($extras as$code)if(!is_string($code)||!in_array($code,array_column($unit['services']??[],'code'),true))return $fail('presentation_stay_extra_not_offered');
  if($extras)$row['extras']=$extras;
  $occupancy=[];
  foreach(['rooms'=>[1,99],'adults'=>[1,100],'children'=>[0,100]]as$field=>$bounds){
   if(!array_key_exists($field,$entry))continue;
   $n=$integer($entry[$field],$bounds[0],$bounds[1]);if($n===null)return $fail('presentation_stay_occupancy_invalid');$occupancy[$field]=$n;$row[$field]=$n;
  }
  if(isset($occupancy['adults'])&&isset($counts['adults'])&&$occupancy['adults']>(int)$counts['adults'])return $fail('presentation_stay_adults_exceeded');
  if(isset($occupancy['children'])&&isset($counts['children'])&&$occupancy['children']>(int)$counts['children']+(int)($counts['infants']??0))return $fail('presentation_stay_children_exceeded');
  if(isset($entry['child_ages'])){
   if(!isset($occupancy['children'])||!is_array($entry['child_ages'])||!array_is_list($entry['child_ages'])||count($entry['child_ages'])!==$occupancy['children'])return $fail('presentation_stay_age_count_mismatch');
   if(!class_exists('MAD4B_Age_Occupancy_Registry'))
    return $fail('presentation_stay_age_registry_missing');
   $row['child_ages']=[];
   foreach($entry['child_ages'] as $age){
    $age=MAD4B_Age_Occupancy_Registry::normalize_age($age);
    if(is_wp_error($age))return $fail('presentation_stay_age_invalid');
    $row['child_ages'][]=$age;
   }
   if($row['child_ages']){
    $actual_ages=$context['age_context']['child_ages']??null;
    if(!is_array($actual_ages)||!array_is_list($actual_ages))return $fail('presentation_stay_age_context_required');
    $available=[];
    foreach($actual_ages as $actual_age){
     $cents=MAD4B_Age_Occupancy_Registry::age_cents($actual_age);
     if(is_wp_error($cents))return $fail('presentation_stay_age_context_invalid');
     $available[$cents]=($available[$cents]??0)+1;
    }
    foreach($row['child_ages'] as $age){
     $cents=MAD4B_Age_Occupancy_Registry::age_cents($age);
     if(empty($available[$cents]))return $fail('presentation_stay_age_not_in_traveler_group');
     $available[$cents]--;
    }
   }
  }
  if(isset($entry['room_occupancy'])){
   if(!is_array($entry['room_occupancy'])||!array_is_list($entry['room_occupancy'])||count($entry['room_occupancy'])>99)return $fail('presentation_stay_room_assignment_invalid');
   $row['room_occupancy']=$entry['room_occupancy'];
  }
  $ready=$unit_id&&$rate_id&&count($occupancy)===3;
  if(($occupancy['children']??0)>0&&!isset($row['child_ages']))$ready=false;
  // A stage covering all travelers must not certify a partial room allocation.
  if(($stage['traveler_group']??'all')==='all'){
   if(!isset($counts['adults'],$counts['children'],$counts['infants']))$ready=false;
   elseif(($occupancy['adults']??-1)!==(int)$counts['adults']||($occupancy['children']??-1)!==((int)$counts['children']+(int)$counts['infants']))$ready=false;
  }
  if($plan['mode']==='multi_stage'){
   if($date){$row['checkin']=$date->modify('+'.(int)$stage['day_offset'].' days')->format('Y-m-d');$row['checkout']=$date->modify('+'.((int)$stage['day_offset']+(int)$stage['nights']).' days')->format('Y-m-d');}
   foreach(['checkin','checkout']as$field)if(isset($entry[$field])&&(!isset($row[$field])||$entry[$field]!==$row[$field]))return $fail('presentation_stay_dates_mismatch');
  }else foreach(['checkin','checkout']as$field){
   if(!isset($entry[$field]))continue;$value=$entry[$field];$parsed=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC')):false;
   if(!$parsed||$parsed->format('Y-m-d')!==$value)return $fail('presentation_stay_dates_invalid');$row[$field]=$value;
  }
  if(isset($row['checkin'],$row['checkout'])&&$row['checkout']<=$row['checkin'])return $fail('presentation_stay_dates_invalid');
  if($ready&&isset($row['checkin'],$row['checkout'])){
   if(!function_exists('BookingExperience\\unified_accommodation_preview'))return $fail('presentation_stay_quote_provider_missing');
   $input=['rate_id'=>$rate_id,'checkin'=>$row['checkin'],'checkout'=>$row['checkout'],'rooms'=>$occupancy['rooms'],'adults'=>$occupancy['adults'],'children'=>$occupancy['children']];
   foreach(['child_ages','room_occupancy']as$field)if(isset($row[$field]))$input[$field]=$row[$field];
   if($board||$extras)$input['addons']=['board_type'=>$board,'extras'=>$extras];
   $quote=BookingExperience\unified_accommodation_preview($input,'tour_component');if(is_wp_error($quote))return $quote;
   $row['selection_status']='validated_room_request_not_availability';
  }else{$details[]=$key;$row['selection_status']='incomplete_room_selection';}
  $stages[]=$row;
 }
 $transports=$context['transportations']??[];
 if(!is_array($transports)||!array_is_list($transports)||count($transports)>80)return $fail('presentation_transport_shape');
 $transport_catalog=[];
 foreach($catalog['internal_transportations']??[]as$item)$transport_catalog[(int)$item['id']]=$item;
 // The presentation adapter owns additional native Road/Train/Air eligibility.
 $tour=(int)get_post_meta($rate,'related_tour_id',true);
 if(function_exists('native_component_selection_plan')){
  $native_catalog=[];
  foreach(['vehicles','train','air']as$category){
   $manifest=native_component_selection_plan($tour,$category);
   foreach($manifest['groupsByRate'][(string)$rate]??[]as$group)foreach($group['ids']??[]as$id){$id=(int)$id;$native_catalog[$id]=$transport_catalog[$id]??['id'=>$id,'stage_keys'=>[],'price_policy'=>BookingExperience\tour_stay_transport_policy($rate,$id),'rates'=>[]];}
  }
  $transport_catalog=$native_catalog;
 }
 $tr=[];$tr_seen=[];
 foreach($transports as$entry){
  if(is_int($entry)||is_string($entry))$entry=['id'=>$entry];
  if(!is_array($entry)||array_diff(array_keys($entry),['id','stage_key','rate_id','quantities']))return $fail('presentation_transport_shape');
  $id=$integer($entry['id']??null,1,PHP_INT_MAX);
  if(!$id||!isset($transport_catalog[$id])||isset($tr_seen[$id]))return $fail('presentation_transport_not_linked_or_duplicate');
  $item=$transport_catalog[$id];$stage_key=$entry['stage_key']??'';
  if(!is_string($stage_key))return $fail('presentation_transport_stage_invalid');
  $keys=$item['stage_keys']??[];if($stage_key===''&&count($keys)===1)$stage_key=(string)$keys[0];
  if($keys&&!in_array($stage_key,$keys,true)||$stage_key!==''&&!isset($allowed[$stage_key]))return $fail('presentation_transport_stage_invalid');
  $record=['id'=>$id,'stage_key'=>$stage_key,'price_policy'=>$item['price_policy']??'inquiry','selection_status'=>'preference_not_availability'];
  $rid=isset($entry['rate_id'])?$integer($entry['rate_id'],1,PHP_INT_MAX):null;
  if(isset($entry['rate_id'])&&(!$rid||!in_array($rid,array_map('intval',array_column($item['rates']??[],'rate_id')),true)))return $fail('presentation_transport_rate_not_offered');
  if($rid)$record['rate_id']=$rid;
  $quantities=$entry['quantities']??[];if(!is_array($quantities)||array_diff(array_keys($quantities),['persons','vehicles','trips','days','cabins','rooms','tables']))return $fail('presentation_transport_quantities_invalid');
  foreach($quantities as$field=>$value){$n=$integer($value,1,999);if($n===null)return $fail('presentation_transport_quantities_invalid');$record['quantities'][$field]=$n;}
  $tr_seen[$id]=true;$tr[]=$record;if(($record['price_policy']??'inquiry')==='inquiry')$requires=true;
 }
 $missing=array_values(array_diff(array_keys($allowed),array_keys($seen)));
 return ['stages'=>$stages,'missing'=>$missing,'detail_missing'=>$details,'transportations'=>$tr,'requires_supplier_approval'=>$requires,'plan_mode'=>$no_stays?'disabled':$plan['mode'],'complete'=>!$missing&&!$details];
}
