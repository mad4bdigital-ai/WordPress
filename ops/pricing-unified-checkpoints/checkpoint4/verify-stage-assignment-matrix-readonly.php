<?php
if(!defined('WP_CLI')||!WP_CLI||!current_user_can('commerce_manage_pricing'))throw new Exception('AUTHORIZED_PRICING_CLI_USER_REQUIRED');
$ids=[57562,60437,60439,60441,60443,60623,60627,60629];
$tiers=[2353,2354,2356];
$matrix=[];$stats=['tours'=>0,'stages'=>0,'hotel_resort_stages'=>0,'hotel_stage_tier_combinations'=>0,'hotel_no_eligible_properties'=>0,'hotel_unique_property_candidate'=>0,'hotel_multiple_property_candidates'=>0,'hotel_candidate_with_published_units'=>0,'train_stages'=>0,'train_single_route_candidate'=>0,'cruise_stages'=>0,'cruise_native_program_present'=>0];
foreach($ids as $id){
 $plan=get_post_meta($id,'_mad4b_package_accommodation_source_plan_v1',true);
 $bindings=get_post_meta($id,'_mad4b_package_stay_verified_room_program_links_v1',true);
 if(!is_array($plan)||!is_array($bindings))throw new Exception('MISSING_SOURCE_'.$id);
 $stats['tours']++;$per=[];
 foreach((array)$plan['stages'] as $stage){
  $key=(string)($stage['stage_key']??'');$kind=(string)($stage['lodging_kind']??'');
  $status=['tour'=>$id,'stage_key'=>$key,'kind'=>$kind,'day'=>$stage['source_day_start']??null,'nights'=>$stage['nights']??null];
  $match=null;foreach($bindings['bindings'] as $b)if(($b['stage_key']??'')===$key){$match=$b;break;}
  if(!is_array($match))throw new Exception('STAGE_UNMATCHED_'.$id.'_'.$key);
  $status['assignment_complete']=false;
  if(in_array($kind,['hotel','resort'],true)){
    $stats['hotel_resort_stages']++;$candidateByTier=[];
    foreach($tiers as $tier){
      $s=MAD4B_Package_Stage_Sourcing_V1::inspect($id,$stage,$tier);
      $props=array_values(array_filter((array)($s['properties']??[]),fn($p)=>!empty($p['tier_eligible'])&&!empty($p['stage_city_location_verified'])));
      $stats['hotel_stage_tier_combinations']++;
      if(!$props)$stats['hotel_no_eligible_properties']++;
      elseif(count($props)===1)$stats['hotel_unique_property_candidate']++;
      else $stats['hotel_multiple_property_candidates']++;
      $withUnits=count(array_filter($props,fn($p)=>(int)($p['native_published_unit_count']??0)>0));
      if($withUnits)$stats['hotel_candidate_with_published_units']++;
      $candidateByTier[$tier]=['properties'=>count($props),'with_published_units'=>$withUnits,
        'candidate_ids'=>array_map(fn($p)=>(int)$p['id'],$props)];
    }
    $status['by_tier']=$candidateByTier;
  }elseif($kind==='sleeper_train'){
    $stats['train_stages']++;
    $s=MAD4B_Package_Stage_Sourcing_V1::inspect($id,$stage,2353);
    $trains=(array)($s['train_candidates']??[]);
    if(count($trains)===1)$stats['train_single_route_candidate']++;
    $status['route']=$s['route']??null;
    $status['candidate_trains']=array_map(fn($p)=>['id'=>$p['id'],'number'=>$p['number']],$trains);
  }elseif($kind==='cruise'){
    $stats['cruise_stages']++;
    $s=MAD4B_Package_Stage_Sourcing_V1::inspect($id,$stage,2353);
    $status['program_id']=(int)($s['program_id']??0);
    if($status['program_id']>0)$stats['cruise_native_program_present']++;
    $status['vessel_id']=(int)($s['vessel_id']??0);
    $status['cabin_id']=(int)($s['cabin_unit_id']??0);
  }
  $stats['stages']++;$per[]=$status;
 }
 $matrix[$id]=$per;
}
echo wp_json_encode(['site'=>home_url(),'generated_at'=>gmdate('c'),'read_only'=>true,'stats'=>$stats,'matrix'=>$matrix],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
