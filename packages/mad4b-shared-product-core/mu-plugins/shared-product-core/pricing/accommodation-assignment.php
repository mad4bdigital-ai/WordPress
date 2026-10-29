<?php
/** Deterministic draft-only discovery. Never infers overnight geometry or inventory. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_assignment_location($id){
 $seen=[];$location=0;$kind='';$country=0;$chain=[];
 for($depth=0;$id&&$depth<32;$depth++){
  if(isset($seen[$id]))return null;
  $seen[$id]=true;$term=get_term($id,'destinations');if(!$term instanceof WP_Term)return null;
  $type=(string)get_term_meta($id,'destination_type',true);
  $chain[]=['id'=>(int)$id,'parent'=>(int)$term->parent,'type'=>$type];
  if(!$location&&in_array($type,['city','state_governorate'],true)){$location=(int)$id;$kind=$type;}
  if($type==='country'){$country=(int)$id;break;}
  $id=(int)$term->parent;
 }
 return $location&&$country?['location_id'=>$location,'location_type'=>$kind,'country_id'=>$country,'ancestry'=>$chain]:null;
}
function shared_product_assignment_cities($post){
 $terms=wp_get_object_terms($post,'destinations',['fields'=>'ids']);if(is_wp_error($terms))return $terms;
 sort($terms,SORT_NUMERIC);$locations=[];
 foreach($terms as $term){$loc=shared_product_assignment_location((int)$term);if($loc)$locations[$loc['location_id']]=$loc;}
 // An explicitly assigned city narrows its own branch; never broaden that city to its state.
 foreach($locations as $id=>$loc)foreach($locations as $other=>$child){
  if($id!==$other&&in_array($id,array_column($child['ancestry'],'id'),true)){unset($locations[$id]);break;}
 }
 ksort($locations,SORT_NUMERIC);return $locations;
}
function shared_product_assignment_matches($candidate,$scope){
 return $candidate['country_id']===$scope['country_id']&&
  in_array($scope['location_id'],array_column($candidate['ancestry'],'id'),true);
}
function shared_product_accommodation_assignment($tour,$source=0,$package=0){
 $locations=shared_product_assignment_cities($tour);if(is_wp_error($locations))return $locations;
 $groups=[];foreach($locations as $location=>$loc)$groups[$location]=['destination_term_id'=>$location,'destination_type'=>$loc['location_type'],'country_id'=>$loc['country_id'],'tour_ancestry'=>$loc['ancestry'],'property_ids'=>[],'property_receipts'=>[]];
 $packageValid=$package>0&&term_exists($package,'package_levels');
 $ids=$packageValid?get_posts(['post_type'=>'properties','post_status'=>'publish','posts_per_page'=>1001,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,'meta_query'=>[['key'=>'prop_use_in_tour_rate_generation','value'=>'1','compare'=>'=']]]):[];
 if(count($ids)>1000)return new WP_Error('accommodation_assignment_candidate_limit');
 sort($ids,SORT_NUMERIC);
 foreach($ids as $id){
  if(get_post_field('post_password',$id)!==''||get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
  $packages=wp_get_object_terms($id,'package_levels',['fields'=>'ids']);if(is_wp_error($packages))return $packages;
  $packages=array_values(array_unique(array_map('intval',$packages)));sort($packages,SORT_NUMERIC);
  if(function_exists('BookingExperience\\tour_stay_property_package_allowed')){
   if(!BookingExperience\tour_stay_property_package_allowed((int)$source,(int)$id,(int)$package))continue;
  }elseif(!in_array((int)$package,$packages,true))continue;
  $propertyLocations=shared_product_assignment_cities($id);if(is_wp_error($propertyLocations))return $propertyLocations;
  foreach($locations as $location=>$scope)foreach($propertyLocations as $loc){
   if(!shared_product_assignment_matches($loc,$scope))continue;
   $groups[$location]['property_ids'][]=(int)$id;
   $groups[$location]['property_receipts'][]=['id'=>(int)$id,'opt_in'=>'1','package_levels'=>$packages,'location'=>$loc];break;
  }
 }
 foreach($groups as &$group){$group['property_ids']=array_values(array_unique($group['property_ids']));sort($group['property_ids'],SORT_NUMERIC);}unset($group);
 $receipt=['schema'=>'tour-accommodation-assignment.v2','tour_id'=>(int)$tour,'package_term_id'=>(int)$package,'source_rate_id'=>(int)$source,'groups'=>array_values($groups),'source_geometry'=>[],'stages'=>[],'property_ids'=>[],'status'=>$packageValid?'needs_city':'needs_package_level','checkout_ready'=>false];
 $stages=[];$required=[];
 if($source){
  if(get_post_type($source)!=='tour-rates'||!in_array(get_post_status($source),['publish','draft'],true)||(int)get_post_meta($source,'related_tour_id',true)!==(int)$tour)return new WP_Error('accommodation_assignment_source_rate_invalid');
  if(function_exists('BookingExperience\\tour_stay_has_multiple')&&!BookingExperience\tour_stay_has_multiple($source))return new WP_Error('accommodation_assignment_source_stages_disabled');
  $raw=get_post_meta($source,'_booking_experience_stay_stages_v1',true);
  if(!is_array($raw)||!$raw)return new WP_Error('accommodation_assignment_source_stages_missing');
  if(!function_exists('BookingExperience\\tour_stay_stage_validate'))return new WP_Error('accommodation_assignment_stage_provider_missing');
  $checked=BookingExperience\tour_stay_stage_validate($source,$raw);if(is_wp_error($checked))return $checked;
  foreach($checked as $stage){
   $destination=(int)$stage['destination_term_id'];$loc=shared_product_assignment_location($destination);$matched=null;
   if(!$loc||$loc['location_id']!==$destination)return new WP_Error('accommodation_assignment_stage_outside_tour_city');
   foreach($locations as $scopeId=>$scope)if(shared_product_assignment_matches($loc,$scope)){$matched=$scopeId;break;}
   if($matched===null)return new WP_Error('accommodation_assignment_stage_outside_tour_city');
   $geometry=$stage;unset($geometry['properties']);$receipt['source_geometry'][]=$geometry;
   // A city stage inside an explicit state must narrow the state's candidate group.
   $choices=[];foreach($groups[$matched]['property_receipts'] as $candidate)if(shared_product_assignment_matches($candidate['location'],$loc))$choices[]=$candidate['id'];
   $choices=array_values(array_unique($choices));sort($choices,SORT_NUMERIC);
   $stage['properties']=$choices;$stages[]=$stage;$required[]=$choices;
  }
 }
 if($packageValid&&$groups){
  if(!$source&&count($groups)>1)$receipt['status']='needs_stage_plan';
  else{
   if(!$source)$required=[reset($groups)['property_ids']];
   $missing=false;foreach($required as $choices)if(!$choices)$missing=true;
   $receipt['status']=$missing?'needs_eligible_properties':'ready';
   if(!$missing){
    if($stages){$validated=BookingExperience\tour_stay_stage_validate($source,$stages,(int)$package);if(is_wp_error($validated))return $validated;$stages=$validated;}
    $receipt['stages']=$stages;foreach($required as $choices)$receipt['property_ids']=array_merge($receipt['property_ids'],$choices);
    $receipt['property_ids']=array_values(array_unique($receipt['property_ids']));sort($receipt['property_ids'],SORT_NUMERIC);}
  }
 }
 return $receipt;
}
