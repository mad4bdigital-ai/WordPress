<?php
/**
 * Owner-attested Marriott Sheraton Cairo exact suite photos — read-only default.
 * Preserves original Presidential SVG reference; a separately verified v2
 * record is used for the replacement photo. No status/pricing writes.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('invalid_mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
 WP_CLI::error('owner_attestation_missing');
$m=json_decode((string)@file_get_contents('/tmp/mad4b-sheraton-room-approval-20261119/manifest.json'),true);
if(!is_array($m)||count($m['records']??[])!==3||!empty($m['errors']))WP_CLI::error('manifest');
$ids=[70923=>'Corner Suite',70922=>'Executive Suite',70920=>'Presidential Suite'];
global $wpdb;$out=[];
foreach($m['records'] as $r){
 $id=(int)$r['unit_id'];$room=(string)$r['room_name'];$pid=(int)$r['property_id'];$url=(string)$r['image_url'];$page=(string)$r['source'];
 $reason='';
 $old=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
 if(!isset($ids[$id])||$ids[$id]!==$room||$pid!==70739)
    $reason='unexpected_unit';
 elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
    ||get_post_type($pid)!=='properties'||get_post_status($pid)!=='draft')
    $reason='unit_or_property_status';
 elseif(html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8')!=='Sheraton Cairo Hotel & Casino — '.$room)
    $reason='title_mismatch';
 elseif((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
    $pid,$id))!==1)
    $reason='native_relation_invalid';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true))
    $reason='unit_image_already_present';
 elseif(strpos($url,'https://cache.marriott.com/content/dam/marriott-renditions/CAISI/caisi-')!==0
   ||strpos($page,'https://www.marriott.com/en-us/hotels/caisi-sheraton-cairo-hotel-and-casino/photos/')!==0)
    $reason='official_marriott_domain_invalid';
 else{
    $ref=[
      'property_id'=>$pid,'room_name'=>$room,
      'image_url'=>$url,'source_room_page'=>$page,
      'rights_status'=>'external_source_reference_only_not_licensed_for_copy'
    ];
    if($id===70920){
        if(!is_array($old)||($old['room_name']??'')!==$room||
            (int)($old['property_id']??0)!==$pid ||
            ($old['rights_status']??'')!=='external_source_reference_only_not_licensed_for_copy' ||
            get_post_meta($id,'_mad4b_unit_photo_source_review_v2',true))
             $reason='presidential_original_ref_not_preservable';
        else{
            $ref=array_merge($ref,[
                'schema'=>'mad4b.stay-photo-source-reviewed-v2',
                'original_v1_reference_preserved'=>true,
                'owner_attested_public_source_use'=>true,
                'rights_independently_verified'=>false,
                'verified_official_gallery_caption'=>true,
                'image_sha256'=>$r['sha256'],
                'recorded_at'=>gmdate('c')
            ]);
            if($mode==='apply'&&!add_post_meta($id,'_mad4b_unit_photo_source_review_v2',$ref,true))
                $reason='review_v2_atomic_insert_failed';
        }
    }elseif($old){
        $reason='original_ref_preserved';
    }elseif($mode==='apply'&&!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$ref,true)){
        $reason='ref_atomic_insert_failed';
    }
    if(!$reason && $mode==='apply')
      add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
       'schema'=>'mad4b.official-room-gallery-caption.v1','room'=>$room,
       'official_gallery_page'=>$page,'owner_attested_public_source_use'=>true,
       'rights_independently_verified'=>false,'recorded_at'=>gmdate('c')
      ],true);
 }
 $out[]=['unit_id'=>$id,'status'=>$reason?:($mode==='apply'?'registered':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$out],JSON_UNESCAPED_UNICODE));
