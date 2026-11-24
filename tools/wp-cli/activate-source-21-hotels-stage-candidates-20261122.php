<?php
/**
 * One-time source-side editorial candidate discovery parity for 21 hotels.
 * Company financial margins, selling rates and booking authority not touched.
 */
defined('ABSPATH')||exit;
$phase=(string)(getenv('MAD4B_SOURCE_ELIG_PHASE')?:'preflight');
if(!in_array($phase,['preflight','apply','verify'],true))
 throw new RuntimeException('invalid_phase');
if(home_url()!=='https://tours.dev.mad4b.com')
 throw new RuntimeException('wrong_site');
if(!current_user_can('manage_options')||
   !current_user_can('edit_others_posts')||
   !current_user_can('commerce_manage_pricing'))
 throw new RuntimeException('missing_authorized_company_pricing_admin');
$plan=json_decode((string)file_get_contents('/tmp/mad4b-source-21-hotel-unified-eligibility.json'),true,512,JSON_THROW_ON_ERROR);
if(($plan['contract']??'')!=='mad4b.source-city-tier-hotel-candidate-parity-only.v1'
  ||($plan['site']??'')!==home_url()
  ||($plan['valid_source_receipts']??0)!==21
  ||($plan['approved_nightly_hotel_tariff_basis_cells']??0)!==253
  ||($plan['source_company_margin_expected']??0)!==20
  ||($plan['wayfa_company_margin_expected']??0)!==25
  ||($plan['no_booking_activation']??false)!==true
  ||($plan['no_public_properties_catalog_change']??false)!==true
  ||($plan['no_selling_rate_created']??false)!==true
  ||count($plan['records']??[])!==21)
 throw new RuntimeException('unsafe_source_parity_contract');
$margin=MAD4B_Company_Global_Profit_V1::current();
$basis=get_option('mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1',null);
if(is_wp_error($margin)||($margin['percent']??0)!==20||
  !is_array($basis)||($basis['sheet_cell_count']??0)!==253)
 throw new RuntimeException('company_margin_or_nightly_basis_missing');
if(get_option('etg-dynamic-pricing-controls_booking_enabled')!=='false' ||
  MAD4B_Accommodation_Stay_Margin_V1::inventory()['configured_count']!==0)
 throw new RuntimeException('commercial_policy_changed');
$rows=[];$before=[];$changed=[];
$receiptKey='_mad4b_source_stage_city_tier_hotel_candidate_parity_v1';
foreach($plan['records'] as $r){
 $id=(int)$r['source_property_id'];
 if($id<1||isset($rows[$id])||get_post_type($id)!=='properties'||
   get_post_status($id)!=='publish'||
   (apply_filters('wpml_post_language_details',null,$id)['language_code']??null)!=='en'||
   html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES,'UTF-8')!==$r['title_source'])
 throw new RuntimeException('source_post_drift:'.$id);
 $meta=get_post_meta($id,'_mad4b_company_sheet_property_cost_sources_v1',true);
 if(!is_array($meta)||($meta['contract']??'')!=='mad4b.company-property-cost-source-matrix.v1'
   ||($meta['checkout_authorized']??true)!==false)
 throw new RuntimeException('source_cost_receipt_unauthorized:'.$id);
 $cells=[];
 foreach((array)($meta['sources']??[]) as $s)
   if(($s['source_tab']??'')==='Hotels')$cells[]=(string)($s['source_cell']??'');
 sort($cells);
 if($cells!==$r['expected_source_cost_cells'])throw new RuntimeException('source_sheet_cells_not_identical:'.$id);
 $old=get_post_meta($id,'prop_use_in_tour_rate_generation',false);
 $original=$r['before_optin'];
 if($phase!=='verify' && $old!==$original)
 throw new RuntimeException('source_optin_preimage_changed:'.$id);
 if($phase==='verify'&&$old!==['1'])
 throw new RuntimeException('source_optin_postimage_invalid:'.$id);
 $tax=[];
 foreach(['package_levels','destinations','stars','property-types'] as $name){
   $t=wp_get_object_terms($id,$name,['fields'=>'ids']);
   if(is_wp_error($t))throw new RuntimeException('taxonomy_error:'.$id.':'.$name);
   $tax[$name]=array_map('intval',$t);sort($tax[$name]);
 }
 $existing=array_map('intval',$r['existing_package_tier_ids']);sort($existing);
 $approved=array_map('intval',$r['approved_package_tier_ids']);sort($approved);
 if(!$approved||array_diff($approved,[2353,2354,2356]))
 throw new RuntimeException('target_tier_unexpected:'.$id);
 if($existing && $approved!==$existing)
 throw new RuntimeException('would_override_explicit_tiers:'.$id);
 if($phase==='verify'){
    if($tax['package_levels']!==$approved)throw new RuntimeException('source_tiers_not_saved:'.$id);
 }elseif($tax['package_levels']!==$existing)
    throw new RuntimeException('source_tier_preimage_changed:'.$id);
 foreach(['destinations'=>'existing_destination_term_ids',
   'stars'=>'existing_stars_term_ids','property-types'=>'existing_type_term_ids'] as $name=>$field){
    $v=array_map('intval',$r[$field]);sort($v);
    if($tax[$name]!==$v)throw new RuntimeException('source_non_tier_taxonomy_changed:'.$id.':'.$name);
 }
 $types=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
 if(is_wp_error($types)||!array_intersect((array)$types,['hotel','resort']))
   throw new RuntimeException('not_hotel_or_resort:'.$id);
 $expectedReceipt=[
   'contract'=>'mad4b.company-source-hotel-stage-candidate-parity.v1',
   'company_site'=>home_url(),'source_property_id'=>$id,
   'matched_wayfa_property_id'=>(int)$r['wayfa_property_id'],
   'basis'=>'exact_owner_approved_hotel_cost_source_cell_set',
   'source_cost_cell_count'=>count($cells),
   'destination_term_ids'=>$tax['destinations'],
   'package_tier_ids'=>$approved,
   'previous_explicit_package_tier_ids'=>$existing,
   'classification_action'=>$r['approval_type'],
   'candidate_only'=>true,'room_and_unit_not_selected'=>true,
   'availability_not_confirmed'=>true,'checkout_authorized'=>false
 ];
 $receipt=get_post_meta($id,$receiptKey,true);
 if($phase==='verify'){
   if($receipt!==$expectedReceipt)throw new RuntimeException('missing_source_parity_receipt:'.$id);
 }elseif($receipt!=='')throw new RuntimeException('existing_parity_receipt_conflict:'.$id);
 $rows[$id]=['receipt'=>$expectedReceipt,'tiers'=>$approved,'before_tiers'=>$existing,'before_optin'=>$original];
}
if(count($rows)!==21)throw new RuntimeException('unmatched_hotel_count');
if($phase==='apply'){
 try{
  foreach($rows as $id=>$row){
   $changed[$id]=['tier'=>false,'flag'=>false,'receipt'=>false,'old'=>$row['before_optin']];
   if(!$row['before_tiers']){
      $terms=wp_set_object_terms($id,$row['tiers'],'package_levels',true);
      if(is_wp_error($terms))throw new RuntimeException('tier_write_failed:'.$id);
      $changed[$id]['tier']=true;
   }
   if($row['before_optin']===[]){
       if(!add_post_meta($id,'prop_use_in_tour_rate_generation','1',true))
        throw new RuntimeException('new_eligibility_flag_failed:'.$id);
   }elseif($row['before_optin']===['0']){
       if(!update_post_meta($id,'prop_use_in_tour_rate_generation','1'))
        throw new RuntimeException('eligibility_flag_update_failed:'.$id);
   }else throw new RuntimeException('unsafe_original_eligibility_flag:'.$id);
   $changed[$id]['flag']=true;
   if(!add_post_meta($id,$receiptKey,wp_slash($row['receipt']),true))
     throw new RuntimeException('candidate_receipt_failed:'.$id);
   $changed[$id]['receipt']=true;
   if(get_post_meta($id,'prop_use_in_tour_rate_generation',false)!==['1']||
      get_post_meta($id,$receiptKey,true)!==$row['receipt'])
     throw new RuntimeException('candidate_readback_failed:'.$id);
  }
 }catch(Throwable $e){
  foreach(array_reverse(array_keys($changed)) as $id){
   $c=$changed[$id];
   if($c['receipt'])delete_post_meta($id,$receiptKey);
   if($c['flag']){
      if($c['old']===[])delete_post_meta($id,'prop_use_in_tour_rate_generation','1');
      else update_post_meta($id,'prop_use_in_tour_rate_generation','0');
   }
   if($c['tier'])wp_set_object_terms($id,[],'package_levels',false);
  }
  throw $e;
 }
}
$checks=[];
foreach($rows as $id=>$row){
 $tiers=wp_get_object_terms($id,'package_levels',['fields'=>'ids']);
 sort($tiers,SORT_NUMERIC);
 $checks[]=[
   'source_property_id'=>$id,
   'title'=>get_the_title($id),
   'expected_tiers'=>$row['tiers'],
   'actual_tiers'=>$tiers,
   'current_optin'=>get_post_meta($id,'prop_use_in_tour_rate_generation',true),
   'receipt_verified'=>get_post_meta($id,$receiptKey,true)===$row['receipt']
 ];
}
if($phase!=='preflight'){
 foreach($checks as $check)
 if($check['actual_tiers']!==$check['expected_tiers']||
    $check['current_optin']!=='1'||!$check['receipt_verified'])
    throw new RuntimeException('after_write_invariant_failure:'.$check['source_property_id']);
}
echo wp_json_encode(['site'=>home_url(),'phase'=>$phase,
 'approved_source_sheet_matched_hotels'=>count($rows),
 'newly_activated_candidates'=>count($changed),
 'source_tier_classifications_added'=>count(array_filter($rows,fn($x)=>!$x['before_tiers'])),
 'source_existing_explicit_tiers_preserved'=>count(array_filter($rows,fn($x)=>!!$x['before_tiers'])),
 'company_margin_percent'=>$margin['percent'],
 'full_checkout_still_disabled'=>get_option('etg-dynamic-pricing-controls_booking_enabled')==='false',
 'no_commercial_rate_posts_modified'=>true,
 'per_hotel_results'=>$checks
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;

