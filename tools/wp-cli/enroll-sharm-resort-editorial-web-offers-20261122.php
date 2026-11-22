<?php
defined('ABSPATH')||exit;
/**
 * Editorial-only property classification and public Offer references.
 * Source of values: Review tab of the owner's Google Sheet, inspected live.
 * No Price, Rate, Tier, Cost Receipt, Unit or Booking mutation.
 */
$site=home_url();
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 throw new RuntimeException('company_not_enrolled');
if(!current_user_can('manage_options'))
 throw new RuntimeException('master_admin_required');
$sheet='https://docs.google.com/spreadsheets/d/1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c/edit#gid=20261122';
$offers=[
  36084=>['Steigenberger Alcazar',375,'all_inclusive_operator','operator_undated','Superior Queen/Twin · Garden View','EXCLUDES taxes/fees','Date not shown','https://www.jazhotels.com/destinations/sharm-el-sheikh?nodes=10008r',4],
  36246=>['Jaz Mirabel Beach',160,'all_inclusive_operator','operator_undated','Superior Queen/Twin · Sea View','EXCLUDES taxes/fees','Date not shown','https://www.jazhotels.com/destinations/sharm-el-sheikh',5],
  36282=>['Jaz Fanara',175,'all_inclusive_operator','operator_undated','Superior Queen/Twin · Garden or Pool View','EXCLUDES taxes/fees','Date not shown','https://www.jazhotels.com/de/destinations/sharm-el-sheikh',6],
  36127=>['Sunrise Remal Resort',195,'all_inclusive_operator','ota_historical','Lowest nightly room; room type unspecified','INCLUDES taxes/fees','2026-10-27 (past)','https://www.expedia.com/Sharm-El-Sheikh-Hotels-Remal-Resort.h69379242.Hotel-Information',7],
  36226=>['White Hills Resort Sharm El Sheikh',397,'meal_plan_unverified','ota_dated','Lowest nightly room; room type unspecified','INCLUDES taxes/fees','2026-11-29','https://www.expedia.com/Sharm-El-Sheikh-Hotels-White-Hills-Resort.h85269305.Hotel-Information',8],
  36107=>['Rixos Premium Seagate Sharm',478,'ultra_ai_needs_exact_rate_plan','ota_historical','Lowest nightly room; room type unspecified','INCLUDES taxes/fees','2026-10-16 (past)','https://www.expedia.com/Sharm-El-Sheikh-Hotels-Rixos-Premium-Seagate-Sharm-El-Sheikh.h9757310.Hotel-Information',9],
  36186=>['Reef Oasis Blue Bay Resort & Spa',285,'meal_plan_unverified','ota_historical','Lowest nightly room; room type unspecified','INCLUDES taxes/fees','2026-11-01 (past)','https://www.expedia.com/Sharm-El-Sheikh-Hotels-Reef-Oasis-Blue-Bay-Resort.h1562898.Hotel-Information',10],
];
$resort=get_term_by('slug','resort','property-types');
if(!$resort instanceof WP_Term)throw new RuntimeException('resort_term_missing');
$expected=[
  'types'=>['hotel'],
  'destination'=>'sharm-el-sheikh'
];
$out=[];
foreach($offers as $id=>$spec){
 [$name,$price,$meal,$freshness,$room,$taxes,$date,$url,$row]=$spec;
 $p=get_post($id);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||
   strcasecmp($p->post_title,$name)!==0)
   throw new RuntimeException('property_identity_mismatch:'.$id);
 $dest=wp_get_object_terms($id,'destinations',['fields'=>'slugs']);
 if(is_wp_error($dest)||!in_array($expected['destination'],$dest,true))
   throw new RuntimeException('not_sharm_property:'.$id);
 $types=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
 if(is_wp_error($types)||!in_array('hotel',$types,true))
   throw new RuntimeException('original_hotel_category_missing:'.$id);
 $existing=get_post_meta($id,'_mad4b_resort_public_offer_reference_v1',true);
 if($existing!=='')throw new RuntimeException('existing_offer_reference_needs_merge:'.$id);
 if(get_post_meta($id,'prop_use_in_tour_rate_generation',true)!=='')
   throw new RuntimeException('unexpected_rate_generator_source_flag:'.$id);
 $net=get_post_meta($id,'prop_start_price',true);
 if($net!=='')throw new RuntimeException('existing_direct_price_needs_review:'.$id);
 if(!in_array('resort',$types,true)){
   $ok=wp_set_object_terms($id,[(int)$resort->term_id],'property-types',true);
   if(is_wp_error($ok))throw new RuntimeException('property_resort_taxonomy_change_failed:'.$id);
 }
 $record=[
   'contract'=>'mad4b.resort-public-web-offer.planning-reference.v1',
   'tour_id'=>60629,'property_id'=>$id,'destination'=>'sharm-el-sheikh',
   'property_kind'=>'resort','kind_proof'=>'official_brand_or_hotel_listing_web_classification',
   'role'=>'retail_web_offer_reference_only',
   'amount_usd'=>(float)$price,'currency'=>'USD',
   'time_unit'=>'night','guest_room_basis'=>$room,
   'guest_occupancy'=>'unknown_unless_ota_two_adult_sample',
   'taxes'=>$taxes,'meal_plan_evidence'=>$meal,
   'observed_rate_class'=>$freshness,'quote_stay_date'=>$date,
   'source_url'=>$url,'spreadsheet'=>$sheet,
   'spreadsheet_row'=>$row,'spreadsheet_review_status'=>'Pending Reservations Cost Review',
   'reservations_supplier_cost_usd'=>null,
   'supplier_agreement_approved'=>false,
   'night_inventory_confirmed'=>false,
   'accepted_for_estimated_offer_component'=>true,
   'accepted_for_full_package_starting_price'=>false,
   'accepted_for_cost_receipt'=>false,
   'accepted_for_commercial_rate'=>false,
   'accepted_for_checkout'=>false,
   'source_review_date'=>'2026-11-22',
 ];
 update_post_meta($id,'_mad4b_resort_public_offer_reference_v1',$record);
 $read=get_post_meta($id,'_mad4b_resort_public_offer_reference_v1',true);
 $typesAfter=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
 if($read!==$record||!in_array('resort',$typesAfter,true)||!in_array('hotel',$typesAfter,true)||
     get_post_meta($id,'prop_start_price',true)!==$net ||
     get_post_meta($id,'prop_use_in_tour_rate_generation',true)!=='')
    throw new RuntimeException('editorial_taxonomy_or_offer_readback_failed:'.$id);
 $out[]=['id'=>$id,'title'=>$name,'resort_type_added'=>true,
     'original_hotel_term_retained'=>true,
     'web_offer_usd_night'=>$price,'quote_basis_not_normalized'=>true,
     'existing_explicit_tiers_untouched'=>true,
     'supplier_cost_verified'=>false,'commercial_rate_enabled'=>false,
     'sheet_row'=>$row];
}
echo wp_json_encode(['site'=>$site,'resorts'=>$out,'count'=>count($out),
    'published_tour_rate_count_created'=>0,'direct_price_mutations'=>0,
    'commercial_price_approval'=>false,'booking_enabled'=>false],
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;

