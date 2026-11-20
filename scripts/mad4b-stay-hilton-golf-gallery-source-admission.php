<?php
/** Exact Hilton Pyramids Golf gallery photo admission, no pricing/status writes. */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('wp-cli');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')WP_CLI::error('approval');
$m=json_decode((string)@file_get_contents('/tmp/mad4b-hilton-golf-approval-20261119/manifest.json'),true);
if(!is_array($m)||count($m['records']??[])!==3||!empty($m['errors']))WP_CLI::error('manifest');
$allowed=[70846=>['Family Room with Golf Course View','20115691/family-room-1.jpg'],
70847=>['King Premier Suite','22105700/king-premier-suite-1.jpg'],
70848=>['Twin Guest Room with Outside Patio','19983249/twin-premium-room-1.jpg']];
global $wpdb;$out=[];
foreach($m['records'] as $row){
 $id=(int)$row['unit_id'];$pid=(int)$row['property_id'];
 $ref=['property_id'=>$pid,'room_name'=>(string)$row['room_name'],
 'image_url'=>(string)$row['image_url'],'source_room_page'=>(string)$row['source'],
 'rights_status'=>'external_source_reference_only_not_licensed_for_copy'];
 $why='';
 if(!isset($allowed[$id])||$pid!==36474||$ref['room_name']!==$allowed[$id][0])
   $why='unapproved_room';
 elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
   ||get_post_type($pid)!=='properties'||get_post_status($pid)!=='publish')
   $why='entity_status_not_verified';
 elseif(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8')!=='Hilton Golf 6 oct — '.$ref['room_name'])
   $why='original_room_title_mismatch';
 elseif((int)$wpdb->get_var($wpdb->prepare(
     "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
     $pid,$id))!==1)
   $why='original_relation_mismatch';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true)
   ||get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true))
   $why='existing_media_or_source_preserved';
 elseif(strpos($ref['image_url'],'https://www.hilton.com/im/en/CAIPGHI/'.$allowed[$id][1])!==0
   ||$ref['source_room_page']!=='https://www.hilton.com/en/hotels/caipghi-hilton-pyramids-golf/gallery/')
   $why='untrusted_hilton_source';
 elseif($mode==='apply'){
    if(!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$ref,true))
        $why='atomic_source_insert_failed';
    else add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
        'schema'=>'mad4b.hilton-official-gallery-photo.v1',
        'official_gallery'=>$ref['source_room_page'],
        'room_caption'=>$ref['room_name'],
        'owner_attested_public_source_use'=>true,
        'rights_independently_verified'=>false,
        'recorded_at'=>gmdate('c')
    ],true);
 }
 $out[]=['unit_id'=>$id,'status'=>$why?:($mode==='apply'?'registered':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$out],JSON_UNESCAPED_UNICODE));
