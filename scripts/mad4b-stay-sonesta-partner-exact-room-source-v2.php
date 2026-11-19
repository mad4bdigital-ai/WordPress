<?php
/**
 * One exact Sonesta Tower Executive Unit: reviewed partner-room category
 * replacement for duplicate official Sonesta photograph.
 * Writes only additive source-review meta, without modifying legacy official
 * source, rates, status, or the previous source-gap history.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('invalid_mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
    WP_CLI::error('owner_attestation_required');
$manifest=json_decode((string)@file_get_contents('/tmp/mad4b-sonesta-partner-exact-20261119/manifest.json'),true);
if(!is_array($manifest)||count($manifest['records']??[])!==1||!empty($manifest['errors']))
    WP_CLI::error('manifest_missing_or_incomplete');
$r=$manifest['records'][0];$id=(int)$r['unit_id'];$pid=(int)$r['property_id'];
$room='Tower Executive Room King or Twin Beds';
$expectedUrl='https://roomtypestorage.imgix.net/sonseta-hotel-tower-and-casino-cairo-egypt-tower-executive-room-king-or-twin-01-1766.jpg?auto=format&fit=crop&fm=jpg&h=900&w=1400';
$page='https://www.awayholidays.co.uk/africa/egypt/cairo/sonestacairo-hotel.aspx';
$old=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
$gap=get_post_meta($id,'_mad4b_stay_room_photo_source_gap_v1',true);
$reason='';
if($id!==70873||$pid!==70733||($r['room_name']??'')!==$room)
    $reason='exact_room_mismatch';
elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
    ||get_post_type($pid)!=='properties'
    ||!in_array(get_post_status($pid),['publish','draft'],true))
    $reason='source_entity_unavailable';
elseif(html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8')
   !=='Sonesta Hotel, Tower & Casino Cairo — '.$room)
    $reason='native_unit_title_changed';
else{
    global $wpdb;
    $rel=(int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
       WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
       $pid,$id));
    if($rel!==1)$reason='native_relation_changed';
    elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true)
       ||get_post_meta($id,'_mad4b_unit_photo_source_review_v2',true))
       $reason='existing_photo_or_review_preserved';
    elseif(!is_array($old)||($old['room_name']??'')!==$room
        ||(int)($old['property_id']??0)!==$pid
        ||($old['rights_status']??'')!=='external_source_reference_only_not_licensed_for_copy')
       $reason='original_official_reference_not_verified';
    elseif(!is_array($gap)||($gap['code']??'')!=='official_sonesta_image_duplicate_sha')
       $reason='original_gap_missing';
    elseif(($r['source']??'')!==$page
        ||($r['image_url']??'')!==$expectedUrl
        ||!preg_match('/^[0-9a-f]{64}$/D',(string)($r['sha256']??''))
        ||($r['verified_partner_room_caption']??false)!==true)
        $reason='partner_source_or_hash_unverified';
    else{
       $review=[
        'schema'=>'mad4b.stay-photo-source-reviewed-v2',
        'property_id'=>$pid,'room_name'=>$room,'image_url'=>$expectedUrl,
        'source_room_page'=>$page,
        'rights_status'=>'external_source_reference_only_not_licensed_for_copy',
        'original_v1_reference_preserved'=>true,
        'owner_attested_public_source_use'=>true,
        'rights_independently_verified'=>false,
        'verified_partner_room_caption'=>true,
        'partner_provenance_not_hotel_official'=>true,
        'source_partner'=>'AwayHolidays room category page',
        'original_official_photo_gap_kept_for_audit'=>true,
        'image_sha256'=>$r['sha256'],'recorded_at'=>gmdate('c')
       ];
       if($mode==='apply'&&!add_post_meta($id,'_mad4b_unit_photo_source_review_v2',$review,true))
           $reason='atomic_review_insert_failed';
    }
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'unit'=>$id,
 'result'=>$reason?:($mode==='apply'?'partner_exact_room_source_reviewed':'eligible')],
 JSON_UNESCAPED_SLASHES));
