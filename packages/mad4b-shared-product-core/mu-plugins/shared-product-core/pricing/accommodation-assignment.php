<?php
/** Deterministic draft-only discovery. Never infers overnight geometry or inventory. */
if(!defined('ABSPATH'))return;
// Runtime authorization is independent of site environment (local/staging/production).
// Never use WP_ENVIRONMENT_TYPE as a feature gate for Rate Generator discovery.
$mad4bRateAssignmentEnabled=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
 && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
if(!$mad4bRateAssignmentEnabled)return;
if(is_admin())require_once __DIR__.'/package-stars-tier-admin.php';
/** Convert translated taxonomy IDs to canonical WPML IDs for matching.
 * Missing translations retain the original term, never a guessed sibling.
 */
function shared_product_assignment_term_id($id,$taxonomy){
 $id=absint($id);
 if(!$id||!(get_term($id,$taxonomy) instanceof WP_Term))return 0;
 $language=apply_filters('wpml_default_language',null);
 if(!is_string($language)||$language==='')return $id;
 $translated=apply_filters('wpml_object_id',$id,$taxonomy,true,$language);
 $translated=absint($translated);
 return $translated&&get_term($translated,$taxonomy) instanceof WP_Term?$translated:$id;
}
function shared_product_assignment_location($id){
 $id=shared_product_assignment_term_id($id,'destinations');
 $seen=[];$location=0;$kind='';$country=0;$chain=[];
 for($depth=0;$id&&$depth<32;$depth++){
  if(isset($seen[$id]))return null;
  $seen[$id]=true;$term=get_term($id,'destinations');if(!$term instanceof WP_Term)return null;
  $type=(string)get_term_meta($id,'destination_type',true);
  $chain[]=['id'=>(int)$id,'parent'=>(int)$term->parent,'type'=>$type];
  if(!$location&&in_array($type,['zone_area','city','state_governorate'],true)){$location=(int)$id;$kind=$type;}
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
/** Resolve CPT identity by WPML translation group; never guess a translated ID. */
function shared_product_assignment_tour_siblings($tour){
 $tour=absint($tour);if(get_post_type($tour)!=='tours-and-activities')return [];
 $ids=[$tour];$trid=apply_filters('wpml_element_trid',null,$tour,'post_tours-and-activities');
 if(!$trid)return $ids;
 $records=apply_filters('wpml_get_element_translations',null,$trid,'post_tours-and-activities');
 if(is_array($records))foreach($records as $row){
  $id=absint($row->element_id??0);
  if($id&&get_post_type($id)==='tours-and-activities'&&get_post_status($id)==='publish')$ids[]=$id;
 }
 $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
 return count($ids)<=30?$ids:[];
}
function shared_product_assignment_property_for_tour($property,$tour){
 $property=absint($property);
 if(get_post_type($property)!=='properties')return 0;
 $language=apply_filters('wpml_post_language_details',null,absint($tour));
 $language=is_array($language)?($language['language_code']??''):'';
 if(!is_string($language)||$language==='')return $property;
 $translated=absint(apply_filters('wpml_object_id',$property,'properties',true,$language));
 return $translated&&get_post_type($translated)==='properties'?$translated:$property;
}
/** Strictly read Property star taxonomy rather than reviews or rate amounts. */
function shared_product_assignment_star_number($term){
 $id=shared_product_assignment_term_id((int)$term,'stars');
 $obj=$id?get_term($id,'stars'):null;
 if(!$obj instanceof WP_Term)return null;
 $slug=(string)$obj->slug;
 return preg_match('/^([1-7])(?:-stars?)?$/D',$slug,$m)?(int)$m[1]:null;
}
/** Optional package_levels term meta: 3,4,5 or [3,4,5].
 * WPML-translated Star term IDs also work; no default package-to-star mapping.
 */
function shared_product_assignment_stars_tier($package){
 $package=shared_product_assignment_term_id((int)$package,'package_levels');
 if(!$package)return [];
 $raw=get_term_meta($package,'stars_tier',true);
 if($raw===null||$raw===''||$raw===[])return [];
 if(is_string($raw))$raw=preg_split('/\s*,\s*/',trim($raw));
 if(!is_array($raw)||!$raw||count($raw)>7)return new WP_Error('accommodation_assignment_stars_tier_invalid');
 $values=[];
 foreach($raw as $item){
  if(!is_scalar($item)||is_bool($item)||!ctype_digit(trim((string)$item)))return new WP_Error('accommodation_assignment_stars_tier_invalid');
  $num=(int)$item;
  if($num>7)$num=shared_product_assignment_star_number($num);
  if(!$num||$num>7)return new WP_Error('accommodation_assignment_stars_tier_invalid');
  $values[$num]=$num;
 }
 sort($values,SORT_NUMERIC);
 return array_values($values);
}
function shared_product_assignment_property_stars($property){
 $terms=wp_get_object_terms($property,'stars',['fields'=>'ids']);
 if(is_wp_error($terms))return $terms;
 $numbers=[];
 foreach($terms as $id){
  $star=shared_product_assignment_star_number($id);
  if($star===null)return new WP_Error('accommodation_assignment_property_stars_invalid');
  $numbers[$star]=$star;
 }
 sort($numbers,SORT_NUMERIC);
 return array_values($numbers);
}
/** Legacy Rate->Property references supply eligibility, not a Property classification. */
function shared_product_assignment_legacy_links($tour,$package=0,$source=0){
 $tourIds=shared_product_assignment_tour_siblings($tour);
 if(!$tourIds)return new WP_Error('accommodation_assignment_tour_identity_invalid');
 $source=absint($source);
 if($source){
  if(get_post_type($source)!=='tour-rates'||
    !in_array(get_post_status($source),['publish','draft'],true)||
    !in_array(absint(get_post_meta($source,'related_tour_id',true)),$tourIds,true))
   return new WP_Error('accommodation_assignment_source_rate_invalid');
  $rates=[$source];
 }else{
  $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
   'posts_per_page'=>301,'fields'=>'ids','suppress_filters'=>true,
   'meta_query'=>[['key'=>'related_tour_id','value'=>array_map('strval',$tourIds),'compare'=>'IN']]]);
  if(count($rates)>=301)return new WP_Error('accommodation_assignment_rate_source_limit');
 }
 $packageCanonical=$package?shared_product_assignment_term_id($package,'package_levels'):0;
 if($package&&!$packageCanonical)return new WP_Error('accommodation_assignment_package_invalid');
 $linked=[];
 foreach($rates as $rate){
  $rate=absint($rate);
  if(!in_array(absint(get_post_meta($rate,'related_tour_id',true)),$tourIds,true))continue;
  $tier=absint(get_post_meta($rate,'related_package_term_id',true));
  $tierCanonical=$tier?shared_product_assignment_term_id($tier,'package_levels'):0;
  if($packageCanonical && $tierCanonical && $tierCanonical!==$packageCanonical)continue;
  $raw=get_post_meta($rate,'related_properties_id',true);
  if(is_scalar($raw)&&!is_bool($raw)&&preg_match('/^\s*\d+(?:\s*,\s*\d+)*\s*$/D',(string)$raw))
   $raw=explode(',',(string)$raw);
  if(!is_array($raw)||count($raw)>80)continue;
  foreach($raw as $candidate){
   if(!is_scalar($candidate)||!ctype_digit(trim((string)$candidate)))continue;
   $id=shared_product_assignment_property_for_tour((int)$candidate,$tour);
   if(!$id||get_post_status($id)!=='publish')continue;
   $linked[$id]['source_rate_ids'][$rate]=$rate;
   $linked[$id]['source_property_ids'][(int)$candidate]=(int)$candidate;
   if($tierCanonical)$linked[$id]['source_tier_ids'][$tierCanonical]=$tierCanonical;
  }
 }
 foreach($linked as &$row)foreach($row as &$ids){$ids=array_values($ids);sort($ids,SORT_NUMERIC);}unset($row,$ids);
 ksort($linked,SORT_NUMERIC);
 return $linked;
}
function shared_product_accommodation_assignment($tour,$source=0,$package=0){
 $tour=absint($tour);$package=absint($package);$source=absint($source);
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
  return new WP_Error('accommodation_assignment_tour_invalid');
 $packageCanonical=$package?shared_product_assignment_term_id($package,'package_levels'):0;
 if($package&&!$packageCanonical)return new WP_Error('accommodation_assignment_package_invalid');
 $locations=shared_product_assignment_cities($tour);if(is_wp_error($locations))return $locations;
 $starsTier=$packageCanonical?shared_product_assignment_stars_tier($packageCanonical):[];
 if(is_wp_error($starsTier))return $starsTier;
 $groups=[];
 foreach($locations as $location=>$loc)$groups[$location]=[
  'destination_term_id'=>$location,'destination_type'=>$loc['location_type'],
  'country_id'=>$loc['country_id'],'tour_ancestry'=>$loc['ancestry'],
  'property_ids'=>[],'property_receipts'=>[]];
 $legacy=shared_product_assignment_legacy_links($tour,$package,$source);
 if(is_wp_error($legacy))return $legacy;
 // Explicit source Rate locks discovery to its linked properties. Otherwise
 // global dynamic opt-in and inherited same-tour-family links are alternatives.
 $auto=[];
 if(!$source){
  $found=get_posts(['post_type'=>'properties','post_status'=>'publish',
   'posts_per_page'=>1001,'fields'=>'ids','orderby'=>'ID','order'=>'ASC',
   'suppress_filters'=>true,'meta_query'=>[
    ['key'=>'prop_use_in_tour_rate_generation','value'=>'1','compare'=>'=']]]);
  if(count($found)>1000)return new WP_Error('accommodation_assignment_candidate_limit');
  foreach($found as $id){
   $localized=shared_product_assignment_property_for_tour((int)$id,$tour);
   if($localized)$auto[$localized]=true;
  }
 }
 $ids=array_values(array_unique(array_merge(array_keys($legacy),array_keys($auto))));
 sort($ids,SORT_NUMERIC);$excluded=[];$needsClassification=false;
 foreach($ids as $id){
  if(get_post_type($id)!=='properties'||get_post_status($id)!=='publish'||
     get_post_field('post_password',$id)!==''||
     get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
  $packages=wp_get_object_terms($id,'package_levels',['fields'=>'ids']);
  if(is_wp_error($packages))return $packages;
  $packages=array_values(array_unique(array_filter(array_map(
   static fn($term)=>shared_product_assignment_term_id($term,'package_levels'),$packages))));
  sort($packages,SORT_NUMERIC);
  if($packageCanonical&&$packages&&!in_array($packageCanonical,$packages,true)){
   if(isset($legacy[$id]))$excluded[]=['property_id'=>$id,'reason'=>'explicit_package_level_mismatch'];
   continue;
  }
  $types=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
  if(is_wp_error($types))return $types;
  $types=array_values(array_unique(array_filter(array_map('sanitize_key',$types))));sort($types,SORT_STRING);
  $stars=shared_product_assignment_property_stars($id);
  if(is_wp_error($stars))return $stars;
  $starFit='not_configured';$classificationSource=$packages?'property_package_levels':'unclassified';
  if($starsTier){
   if(count($stars)!==1)$starFit='needs_review';
   else $starFit=in_array($stars[0],$starsTier,true)?'matched':'mismatch';
   if($starFit==='mismatch'){
    if(isset($legacy[$id]))$excluded[]=['property_id'=>$id,
      'reason'=>'package_stars_tier_mismatch','source_rate_ids'=>$legacy[$id]['source_rate_ids']??[]];
    continue;
   }
   if($starFit==='matched'&&!$packages)$classificationSource='package_stars_tier_policy';
  }
  $classification=$packages?'classified':($starFit==='matched'?'matched_by_stars_tier':'unclassified');
  $locs=shared_product_assignment_cities($id);if(is_wp_error($locs))return $locs;
  $matched=false;
  foreach($locations as $location=>$scope)foreach($locs as $loc){
   if(!shared_product_assignment_matches($loc,$scope))continue;
   $group=&$groups[$location];
   $group['property_ids'][]=$id;
   $group['property_receipts'][]=[
    'id'=>$id,'eligibility_source'=>isset($legacy[$id])?'explicit_rate_relation':'property_opt_in',
    'opt_in'=>isset($auto[$id])?'1':'0',
    'source_rate_ids'=>$legacy[$id]['source_rate_ids']??[],
    'source_property_ids'=>$legacy[$id]['source_property_ids']??[],
    'source_rate_package_context'=>$legacy[$id]['source_tier_ids']??[],
    'property_types'=>$types,'package_levels'=>$packages,'stars'=>$stars,
    'required_stars_tier'=>$starsTier,'stars_tier_fit'=>$starFit,
    'qualification_review_required'=>$starFit==='needs_review',
    'classification_status'=>$classification,'classification_source'=>$classificationSource,
    'location'=>$loc,'unit_pricing_required'=>false,
    'checkout_ready'=>false];
   unset($group);$matched=true;
   if($classification==='unclassified'||$starFit==='needs_review')$needsClassification=true;
   break;
  }
  if(!$matched&&isset($legacy[$id]))$excluded[]=[
   'property_id'=>$id,'reason'=>$locs?'outside_tour_destination':'property_destination_missing',
   'source_rate_ids'=>$legacy[$id]['source_rate_ids']??[]];
 }
 foreach($groups as &$group){
  $group['property_ids']=array_values(array_unique($group['property_ids']));
  sort($group['property_ids'],SORT_NUMERIC);
 }unset($group);
 $receipt=['schema'=>'tour-accommodation-assignment.v3','tour_id'=>$tour,
  'package_term_id'=>$package,'canonical_package_term_id'=>$packageCanonical,
  'source_rate_id'=>$source,'groups'=>array_values($groups),
  'source_geometry'=>[],'stages'=>[],'property_ids'=>[],
  'legacy_linked_property_count'=>count($legacy),'excluded_legacy'=>array_slice($excluded,0,150),
  'classification_review_required'=>$needsClassification,
  'stars_tier'=>$starsTier,'unit_price_required'=>false,
  'status'=>'needs_city','checkout_ready'=>false];
 $stages=[];$required=[];$hasStages=false;
 if($source&&function_exists('BookingExperience\\tour_stay_has_multiple'))
  $hasStages=BookingExperience\tour_stay_has_multiple($source);
 if($source&&$hasStages){
  $raw=get_post_meta($source,'_booking_experience_stay_stages_v1',true);
  if(!is_array($raw)||!$raw)return new WP_Error('accommodation_assignment_source_stages_missing');
  if(!function_exists('BookingExperience\\tour_stay_stage_validate'))
   return new WP_Error('accommodation_assignment_stage_provider_missing');
  $checked=BookingExperience\tour_stay_stage_validate($source,$raw);
  if(is_wp_error($checked))return $checked;
  foreach($checked as $stage){
   $destination=(int)$stage['destination_term_id'];
   $loc=shared_product_assignment_location($destination);$matched=null;
   if(!$loc)return new WP_Error('accommodation_assignment_stage_destination_invalid');
   foreach($locations as $scopeId=>$scope)if(shared_product_assignment_matches($loc,$scope)){
    $matched=$scopeId;break;
   }
   if($matched===null)return new WP_Error('accommodation_assignment_stage_outside_tour_city');
   $geometry=$stage;unset($geometry['properties']);$receipt['source_geometry'][]=$geometry;
   $choices=[];
   foreach($groups[$matched]['property_receipts'] as $candidate)
    if(shared_product_assignment_matches($candidate['location'],$loc))$choices[]=$candidate['id'];
   $choices=array_values(array_unique($choices));sort($choices,SORT_NUMERIC);
   $stage['properties']=$choices;$stages[]=$stage;$required[]=$choices;
  }
 }
 if($groups){
  if(!$hasStages&&count($groups)>1)$receipt['status']='needs_stage_plan';
  else{
   if(!$hasStages)$required=[reset($groups)['property_ids']];
   $missing=false;foreach($required as $choices)if(!$choices)$missing=true;
   $receipt['status']=$missing?'needs_eligible_properties':'ready';
   if(!$missing){
    // Existing staged Rate geometry was validated above; discovery never
    // creates nights or assigns a fabricated package classification.
    $receipt['stages']=$stages;
    foreach($required as $choices)$receipt['property_ids']=array_merge($receipt['property_ids'],$choices);
    $receipt['property_ids']=array_values(array_unique($receipt['property_ids']));
    sort($receipt['property_ids'],SORT_NUMERIC);
   }
  }
 }
 return $receipt;
}
