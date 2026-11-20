<?php
/**
 * Record a supplier-document-request audit receipt for Saray Pyramids.
 * This does not validate room capacities, authorize media usage or activate commerce.
 * Modes: plan | apply (one-time) | verify
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP CLI only');
global $wpdb;
$mode=getenv('MAD4B_SARAY_REQUEST_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('site_scope_mismatch');
$hotelName='Saray Pyramids & Museum View Hotel';
$enProperty=36316;$esProperty=71711;$units=[71731,71733,71735];
$metaKey='_mad4b_saray_supplier_document_request_v1';
$contact=['to'=>'info@sarayhotels.com','cc'=>'res.pyramids@sarayhotels.com','email_id'=>'1a1f814f43a054f5','thread_id'=>'1a1f814f43a054f5'];
$receipt=[
 'schema'=>'mad4b.saray-supplier-evidence-request.v1',
 'scope'=>'wayfatours-36316-en-es-three-units',
 'hotel_property_id'=>$enProperty,'es_property_id'=>$esProperty,
 'unit_ids'=>$units,'hotel_name'=>$hotelName,
 'request_status'=>'sent_awaiting_supplier_response',
 'sent_with'=>'connected_gmail','gmail_message_id'=>$contact['email_id'],'gmail_thread_id'=>$contact['thread_id'],
 'recipient_to'=>$contact['to'],'recipient_cc'=>$contact['cc'],
 'subject'=>'Wayfatours | Saray Pyramids & Museum View — room classifications, occupancy & photo permission',
 'source_of_contact_current'=>'https://sarayhotels.com/contact-us',
 'source_of_contact_previous'=>'https://sarayhotels.com/public/rooms',
 'asked_for'=>[
  'official_current_room_taxonomy_and_room_codes',
  'legacy_7_to_current_3_category_mapping',
  'family_room_id_6_slug_vs_category_name_resolution',
  'per_category_max_adults_children_infants_and_total_guests',
  'children_age_bands_and_beds_cots_extra_bed_policies',
  'room_sqm_views_and_terrace_guarantees',
  'original_room_specific_photos_and_publication_use_license',
  'image_rights_owner_credit_crops_languages_promotional_use_duration'
 ],
 'current_official_page'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view',
 'source_category_count'=>3,
 'current_photo_references_staged_only'=>true,
 'supplier_response_received'=>false,
 'room_category_mapping_verified'=>false,
 'supplier_max_occupancy_verified'=>false,
 'media_publication_license_granted'=>false,
 'media_assets_import_allowed'=>false,
 'commercial_ready'=>false,'catalog_activated'=>false,
 'original_email_source_timestamp'=>'2026-11-20T08:31:25',
 'no_price_inventory_media_or_status_mutations'=>true,
];
$errors=[];
foreach([$enProperty,$esProperty] as $pid) {
 $p=get_post($pid);
 $id=get_post_meta($pid,'_mad4b_property_identity_verified_v3',true);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||$p->post_title!==$hotelName||!is_array($id)||($id['owner_confirmed']??false)!==true)$errors[]='property_preflight_'.$pid;
}
foreach($units as $uid){
 $p=get_post($uid);$ref=get_post_meta($uid,'_mad4b_unit_official_room_photo_reference_v1',true);
 if(!$p||$p->post_type!=='units'||$p->post_status!=='publish'
  ||!is_array($ref)||($ref['gallery_import_allowed']??null)!==false
  ||get_post_meta($uid,'unit_available',true)!=='false'
  ||get_post_meta($uid,'unit_readiness',true)!=='pending_review'
  ||(int)get_post_thumbnail_id($uid)>0
  ||(int)get_post_meta($uid,'unit_max_occupancy',true)!==0)$errors[]='unit_preflight_'.$uid;
 $rel=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%s AND parent_object_id=%d AND child_object_id=%d",'223',$enProperty,$uid));
 if($rel!==1)$errors[]='unit_relation_'.$uid;
}
$plan=get_option('mad4b_stay_editorial_publish_v1');
if(!is_array($plan)||($plan['catalog_activated']??true)!==false||(int)(wp_count_posts('property-rates')->publish??0)!==0
 ||(string)get_post_meta($enProperty,'prop_use_in_tour_rate_generation',true)!=='0')$errors[]='commercial_guard';
if($errors)WP_CLI::error('preflight_failed:'.implode(',',$errors));
if($mode==='plan'){
 $existing=[];
 foreach([$enProperty,$esProperty,...$units] as $id) $existing[$id]=metadata_exists('post',$id,$metaKey);
 WP_CLI::line(wp_json_encode(['mode'=>'plan','scope'=>[$enProperty,$esProperty,...$units],
 'message_id'=>$contact['email_id'],'recipient_to'=>$contact['to'],'recipient_cc'=>$contact['cc'],
 'existing'=>$existing,'only_meta_receipt_write'=>true,'commercial_activation'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 return;
}
if($mode==='apply'){
 foreach([$enProperty,$esProperty,...$units] as $id)
  if(metadata_exists('post',$id,$metaKey))WP_CLI::error('existing_request_refuse_overwrite_'.$id);
 $receipt['recorded_at']=gmdate('c');
 foreach([$enProperty,$esProperty,...$units] as $id) {
  $written=add_post_meta($id,$metaKey,$receipt,true);
  if(!$written)WP_CLI::error('request_receipt_write_failed_'.$id);
 }
}
$found=[];$violations=[];
foreach([$enProperty,$esProperty,...$units] as $id){
 $r=get_post_meta($id,$metaKey,true);
 if(!is_array($r)||($r['gmail_message_id']??null)!==$contact['email_id']
   ||($r['request_status']??null)!=='sent_awaiting_supplier_response'
   ||($r['supplier_response_received']??true)!==false
   ||($r['supplier_max_occupancy_verified']??true)!==false
   ||($r['media_publication_license_granted']??true)!==false
   ||($r['commercial_ready']??true)!==false) $violations[]='invalid_receipt_'.$id;
 $found[]=$id;
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'request_status'=>'sent_awaiting_supplier_response','records'=>$found,
 'errors'=>$violations,'error_count'=>count($violations),
 'readiness_unchanged'=>true,'catalog_activated'=>false,'property_rates_published'=>(int)(wp_count_posts('property-rates')->publish??0)],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if($violations)WP_CLI::error('supplier_request_receipt_not_verified');
