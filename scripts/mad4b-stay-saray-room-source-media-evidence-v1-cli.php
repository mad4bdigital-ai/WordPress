<?php
/** Bounded source-only gallery and room taxonomy evidence for Saray Pyramids on Wayfatours. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('CLI only'); }
global $wpdb;
$mode=getenv('MAD4B_SARAY_MEDIA_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true)) WP_CLI::error('unsupported_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com') WP_CLI::error('site_scope_mismatch');
$pid=36316;$esid=71711;$name='Saray Pyramids & Museum View Hotel';
$expected=['standard'=>71731,'deluxe'=>71733,'family'=>71735];
$pages=[
'standard'=>[
 'title'=>'Standard Room',
 'url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/standard-room/4',
 'image_url'=>'https://sarayhotels.com/storage/wLeapTUdSfTQ7rTyVgWn1mt6mkJWcYKhuz2VJIZ6.jpg',
 'sha256'=>'638891bf73d8e496ccf1bc9634aedfc26be92993f893499ae35fee3b911b5397',
 'gallery_url_count'=>8,'image_bytes'=>1281154,
 'gallery_duplicate_note'=>'The third image on the official Standard page has the same bytes as its primary image'
],
'deluxe'=>[
 'title'=>'Deluxe Room',
 'url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/deluxe-room/5',
 'image_url'=>'https://sarayhotels.com/storage/PIuBF1qmG0Znx26IISfCqev4pZfwydXlAhd1Nrjt.jpg',
 'sha256'=>'64685d7b7c68c61058e9bdc2a4fc25768a6e5f01398958a6d553b2b41b38c696',
 'gallery_url_count'=>6,'image_bytes'=>898457,
 'gallery_duplicate_note'=>''
],
'family'=>[
 'title'=>'Family Room',
 'url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/family-rooms/6',
 'image_url'=>'https://sarayhotels.com/storage/WxYbCMaI4GgOMldMPxcSHFSOPaFa1nAwR7eLecuB.jpg',
 'sha256'=>'23a5a6aec27d1295c4b4f3162c5b195050b00e4e679c780504f06128987bf709',
 'gallery_url_count'=>7,'image_bytes'=>1245331,
 'gallery_duplicate_note'=>'Official 3-card index names this Family rooms but its link points to /family-suite-with-terrace-and-pyramids-view/6, which returns a different room title; do not assert equivalence'
]];
$priorSeven=[
'Standard Double Room'=>['modern_candidate'=>'standard','match_status'=>'name_similarity_unconfirmed'],
'Deluxe Double Room'=>['modern_candidate'=>'deluxe','match_status'=>'name_similarity_unconfirmed'],
'Superior Room Pyramids View'=>['modern_candidate'=>null,'match_status'=>'not_listed_in_current_3_cards'],
'Family Suite'=>['modern_candidate'=>'family','match_status'=>'not_equivalent_without_supplier_mapping'],
'Family Suite Terrace & Pyramids View'=>['modern_candidate'=>'family','match_status'=>'conflicting_family_card_href_slug'],
'Junior Suite Pyramids View'=>['modern_candidate'=>null,'match_status'=>'not_listed_in_current_3_cards'],
'Jacuzzi Suite Pyramids View'=>['modern_candidate'=>null,'match_status'=>'not_listed_in_current_3_cards'],
];
$sourceCrosswalk=[
'schema'=>'mad4b.saray-room-crosswalk.v1',
'property_id'=>$pid,'current_official_index'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view',
'official_current_catalog_categories'=>['Standard Room','Deluxe Room','Family rooms'],
'official_current_catalog_count'=>3,
'prior_seven_seen_in_search_index'=>array_keys($priorSeven),
'prior_seven_source'=>'https://www.sarayhotels.com/rooms?hotel_id=1',
'prior_seven_live_page_now_displays_new_template'=>true,
'prior_source_catalog_is_not_current_official_inventory'=>true,
'crosswalk'=>$priorSeven,
'official_family_card_href'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/family-suite-with-terrace-and-pyramids-view/6',
'official_family_detail_alt_url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/family-rooms/6',
'family_route_name_conflict'=>true,
'fact_sheet_link'=>'https://sarayhotels.com/storage/fact-sheets/Saray%20PNG_094907.pdf',
'fact_sheet_verified_room_capacities'=>false,
'fact_sheet_note'=>'One-page file showed logo/branding only; not a room capacity specification',
'commercial_room_category_mapping_verified'=>false,
'new_legacy_only_rooms_allowed'=>false,
'property_checkout_ready'=>false,
'photo_import_allowed'=>false,
'checked_on'=>'2026-11-20',
];
$errors=[];
foreach([$pid,$esid] as $id){
 $p=get_post($id);$identity=get_post_meta($id,'_mad4b_property_identity_verified_v3',true);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||$p->post_title!==$name||!is_array($identity)||($identity['owner_confirmed']??false)!==true)$errors[]='parent_'.$id;
}
foreach($pages as $k=>$e){
 $id=$expected[$k];$p=get_post($id);$rec=get_post_meta($id,'_mad4b_room_source_receipt_v1',true);
 $c=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%s AND parent_object_id=%d AND child_object_id=%d",'223',$pid,$id));
 if(!$p||$p->post_type!=='units'||$p->post_status!=='publish'||$p->post_title!==$name.' — '.$e['title']||$c!==1||!is_array($rec)||($rec['source_url']??'')!==$e['url'])$errors[]='unit_'.$k;
 if(get_post_meta($id,'unit_available',true)!=='false'||get_post_meta($id,'unit_readiness',true)!=='pending_review'||get_post_thumbnail_id($id)>0)$errors[]='unit_commerce_or_media_'.$k;
}
if(!is_array(get_option('mad4b_stay_editorial_publish_v1'))||get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false||(int)(wp_count_posts('property-rates')->publish??0)!==0)$errors[]='commercial_scope';
if($errors)WP_CLI::error('scope_guard_'.implode(',',$errors));
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>$mode,'identity'=>'owner_confirmed_editorial_only','property'=>$pid,'es_property'=>$esid,'existing_units'=>$expected,'official_photo_ref_count'=>count($pages),'official_current_categories'=>3,'indexed_previous_categories'=>7,'family_slug_conflict'=>true,'live_images_to_import'=>0,'max_occupancy_to_set'=>0,'new_units_to_create'=>0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 return;
}
$now=gmdate('c');$records=[];
if($mode==='apply'){
 foreach([$pid,$esid] as $id){
  $was=get_post_meta($id,'_mad4b_saray_room_crosswalk_v1',true);
  if($was!=='' && $was!==$sourceCrosswalk)WP_CLI::error('preexisting_crosswalk_conflict_'.$id);
 }
 foreach($pages as $k=>$e){
  $id=$expected[$k];
  foreach(['_mad4b_unit_official_room_photo_reference_v1','_mad4b_saray_room_source_media_review_v1'] as $key) {
   if(metadata_exists('post',$id,$key)) WP_CLI::error('preexisting_evidence_conflict_'.$id.'_'.$key);
  }
 }
 foreach([$pid,$esid] as $id)update_post_meta($id,'_mad4b_saray_room_crosswalk_v1',$sourceCrosswalk);
 foreach($pages as $k=>$e){
  $id=$expected[$k];
  update_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',[
   'source_room_page'=>$e['url'],'property_id'=>$pid,'room_name'=>$e['title'],
   'image_url'=>$e['image_url'],'source_sha256'=>$e['sha256'],
   'source_http'=>200,'source_content_type'=>'image/jpeg','source_bytes'=>$e['image_bytes'],
   'source_type'=>'current_official_room_page_media',
   'gallery_url_count'=>$e['gallery_url_count'],
   'rights_status'=>'external_source_reference_only_not_licensed_for_copy',
   'gallery_import_allowed'=>false,'images_verified_for_booking'=>false,
   'reviewed_on'=>'2026-11-20'
  ]);
  update_post_meta($id,'_mad4b_saray_room_source_media_review_v1',[
   'schema'=>'mad4b.saray-room-source-media-review.v1','property_id'=>$pid,'unit_id'=>$id,
   'room_category'=>$e['title'],'official_room_detail_url'=>$e['url'],
   'official_primary_image'=>$e['image_url'],'official_primary_image_sha256'=>$e['sha256'],
   'distinct_sha_across_three_primary_images'=>true,
   'gallery_url_count'=>$e['gallery_url_count'],
   'gallery_duplicate_observation'=>$e['gallery_duplicate_note'],
   'old_to_current_category_mapping_verified'=>false,'family_route_name_conflict'=>$k==='family',
   'photo_rights_independently_verified'=>false,'images_applied'=>false,
   'capacity_from_beds_not_inferred'=>true,'commercial_approval'=>false,'reviewed_at'=>$now
  ]);
  $records[]=['unit_id'=>$id,'official_page_verified'=>true,'image_ref_set'=>true,'physical_image_imported'=>false,'primary_sha256'=>$e['sha256']];
 }
}else{
 foreach([$pid,$esid] as $id){
  $v=get_post_meta($id,'_mad4b_saray_room_crosswalk_v1',true);
  if(!is_array($v)||$v['schema']!==$sourceCrosswalk['schema']||$v['official_current_catalog_count']!==3||$v['family_route_name_conflict']!==true)$errors[]='crosswalk_missing_'.$id;
 }
 foreach($pages as $k=>$e){
  $id=$expected[$k];$p=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
  $v=get_post_meta($id,'_mad4b_saray_room_source_media_review_v1',true);
  if(!is_array($p)||($p['image_url']??null)!==$e['image_url']||($p['source_sha256']??null)!==$e['sha256']||($p['gallery_import_allowed']??null)!==false||!is_array($v)||($v['images_applied']??null)!==false)$errors[]='media_ref_'.$id;
  $records[]=['unit_id'=>$id,'image_ref_set'=>is_array($p),'physical_image_imported'=>get_post_thumbnail_id($id)>0,'reviewed'=>is_array($v)];
 }
}
$out=['mode'=>$mode,'property'=>$pid,'records'=>$records,'commercial_locked'=>true,'errors'=>$errors,'error_count'=>count($errors),'timestamp'=>$now];
WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if($errors)WP_CLI::error('media_review_verify_failed');
