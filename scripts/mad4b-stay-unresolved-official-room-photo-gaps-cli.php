<?php
/**
 * MAD4B — explicit unresolved room-photo source observations for 3 exact
 * published-parent, draft-child Units. Editorial diagnostic only; preserves
 * all original photo references, post status, bookings and prices.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)
    throw new RuntimeException('WP CLI only');
$mode=getenv('MAD4B_STAY_IMAGE_GAP_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('invalid_mode');
if($mode==='apply'&&getenv('MAD4B_STAY_IMAGE_GAP_OWNER_APPROVED')!=='1')
 WP_CLI::error('owner approval required');
$records=[
 70905=>[
   'property_id'=>70737,
   'code'=>'official_hilton_image_unavailable',
   'detail'=>'Verified Hilton guest-room image URLs return HTTP 404; exact pool-view variant requires valid source',
   'official_page'=>'https://www.hilton.com/en/hotels/caihehi-hilton-cairo-heliopolis/gallery/'
 ],
 70893=>[
   'property_id'=>70735,
   'code'=>'official_fairmont_hero_reused_across_room_categories',
   'detail'=>'Official Deluxe Premier Partial Nile View hero is reused by Deluxe Partial; unique room photo not yet verified',
   'official_page'=>'https://www.fairmont.com/en/hotels/cairo/fairmont-nile-city/rooms/kgb.room.html'
 ],
 70873=>[
   'property_id'=>70733,
   'code'=>'official_sonesta_image_duplicate_sha',
   'detail'=>'Official Sonesta room API supplies a photo already assigned to another Unit; distinct Tower Executive photo needed',
   'official_page'=>'https://www.sonesta.com/hotels/egy002-sonesta-hotel-tower-casino-cairo/rooms'
 ]
];
global $wpdb;$result=[];
foreach($records as $id=>$record){
 $pid=(int)$record['property_id'];
 $old=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
 $existing=get_post_meta($id,'_mad4b_stay_room_photo_source_gap_v1',true);
 $reason='';
 if(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
   ||get_post_type($pid)!=='properties'
   ||!in_array(get_post_status($pid),['publish','draft'],true))
  $reason='entity_not_eligible';
 elseif((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
    WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
     $pid,$id))!==1)
  $reason='original_relation_changed';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true))
  $reason='media_exists';
 elseif($existing)
  $reason='gap_record_exists';
 elseif($id!==70905 && (!is_array($old) ||
     ($old['rights_status']??'')!=='external_source_reference_only_not_licensed_for_copy'))
  $reason='original_reference_unexpected';
 else {
   $entry=[
    'schema'=>'mad4b.stay-room-photo-source-gap.v1',
    'unit_id'=>$id,
    'property_id'=>$pid,
    'code'=>$record['code'],
    'source_review_status'=>'needs_distinct_verified_official_photo',
    'source_detail'=>$record['detail'],
    'official_page'=>$record['official_page'],
    'next_action'=>'find_exact_room_photo_then_validate_sha_before_import',
    'original_ref_preserved'=>true,
    'no_commercial_authorization'=>true,
    'recorded_at'=>gmdate('c')
   ];
   if($mode==='apply'&&!add_post_meta($id,'_mad4b_stay_room_photo_source_gap_v1',$entry,true))
     $reason='atomic_gap_record_write_failed';
 }
 $result[]=['unit_id'=>$id,'status'=>$reason?:($mode==='apply'?'gap_recorded':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$result],JSON_UNESCAPED_SLASHES));
