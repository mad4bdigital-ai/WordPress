<?php
/**
 * Exact Sofitel C3J2 Executive Club Millesime first-gallery room photo.
 * Additive source v2, never overwrites legacy v1 or edits published content.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
 WP_CLI::error('explicit owner attestation required');
$path='/tmp/mad4b-sofitel-executive-room-20261119/manifest.json';
$m=json_decode((string)@file_get_contents($path),true);
if(!is_array($m)||count($m['records']??[])!==1||!empty($m['errors']))
 WP_CLI::error('exact-source manifest missing/invalid');
$r=$m['records'][0];
$id=(int)($r['unit_id']??0);$pid=(int)($r['property_id']??0);
$source=(string)($r['source']??'');$url=(string)($r['image_url']??'');
$room=(string)($r['room_name']??'');$sha=(string)($r['sha256']??'');
$expected='Executive Room, 1 King Bed, Club Millesime Access';
$old=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
$reason='';
if($id!==70820||$pid!==36355||$room!==$expected)
 $reason='unapproved_unit';
elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
    ||get_post_type($pid)!=='properties'||get_post_status($pid)!=='publish')
 $reason='unit_property_ineligible';
elseif(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8')!=='Sofitel Cairo Downtown Nile — '.$expected)
 $reason='unit_title_mismatch';
else {
 global $wpdb;
 if((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
    $pid,$id))!==1)
    $reason='native_jetengine_relation_changed';
 elseif(!is_array($old)||($old['room_name']??'')!==$room
    ||(int)($old['property_id']??0)!==$pid
    ||($old['rights_status']??'')!=='external_source_reference_only_not_licensed_for_copy')
    $reason='legacy_source_record_missing';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true)
    ||get_post_meta($id,'_mad4b_unit_photo_source_review_v2',true))
    $reason='media_or_source_v2_already_present';
 elseif($source!=='https://sofitel.accor.com/en/hotels/C3J2/KGE.room.html'
    ||$url!=='https://m.ahstatic.com/is/image/accorhotels/HCM_P_1942495:3by2?fmt=jpg&wid=1200&hei=800&qlt=85'
    ||!preg_match('/^[0-9a-f]{64}$/D',$sha)||($r['first_slide']??false)!==true)
    $reason='not_first_gallery_slide';
 else {
  $ref=[
     'schema'=>'mad4b.stay-photo-source-reviewed-v2',
     'property_id'=>$pid,'room_name'=>$room,
     'image_url'=>$url,'source_room_page'=>$source,
     'rights_status'=>'external_source_reference_only_not_licensed_for_copy',
     'owner_attested_public_source_use'=>true,
     'rights_independently_verified'=>false,
     'original_v1_reference_preserved'=>true,
     'verified_room_detail_h1'=>true,
     'official_room_gallery_slide'=>1,
     'image_sha256'=>$sha,
     'recorded_at'=>gmdate('c')
  ];
  if($mode==='apply'&&!add_post_meta($id,'_mad4b_unit_photo_source_review_v2',$ref,true))
    $reason='atomic_source_record_add_failed';
 }
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'id'=>$id,
  'status'=>$reason?:($mode==='apply'?'reviewed_v2':'eligible')],JSON_UNESCAPED_SLASHES));
