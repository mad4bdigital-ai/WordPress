<?php
/** Official Barceló Cairo Pyramids SUP/DLX photo source admission. WP-CLI only. */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP CLI');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')WP_CLI::error('approval');
$m=json_decode((string)@file_get_contents('/tmp/mad4b-barcelo-approved-20261119/manifest.json'),true);
if(!is_array($m)||count($m['records']??[])!==2||!empty($m['errors']))WP_CLI::error('manifest');
$allowed=[70849=>['Superior Room','SUP','BCAI_ROOM_026'],70850=>['Deluxe','DLX','BCAI_ROOM_017']];
global $wpdb;$results=[];
foreach($m['records'] as $r){
 $id=(int)$r['unit_id'];$pid=(int)$r['property_id'];$room=(string)$r['room_name'];$why='';
 $src=(string)$r['image_url'];
 if(!isset($allowed[$id])||$pid!==36475||$room!==$allowed[$id][0]||$r['room_code']!==$allowed[$id][1])
     $why='unapproved_room_code';
 elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
    ||get_post_type($pid)!=='properties'||get_post_status($pid)!=='publish')
     $why='entity_not_eligible';
 elseif(html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8')!=='Barcelo Cairo pyramids — '.$room)
     $why='native_label_mismatch';
 elseif((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
    $pid,$id))!==1)
     $why='native_property_relation_mismatch';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true)
       ||get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true))
     $why='existing_media_or_source_preserved';
 elseif($src!=='https://static-dm.barcelo.com/is/image/barcelo/'.$allowed[$id][2].'?wid=1200&fmt=jpeg&qlt=85'
       ||$r['source']!=='https://www.barcelo.com/en-ww/barcelo-cairo-pyramids/')
     $why='untrusted_official_room_asset';
 else{
  $ref=[
   'property_id'=>$pid,'room_name'=>$room,
   'image_url'=>$src,'source_room_page'=>$r['source'],
   'rights_status'=>'external_source_reference_only_not_licensed_for_copy'
  ];
  if($mode==='apply'){
   if(!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$ref,true))
      $why='atomic_reference_failed';
   else add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
     'schema'=>'mad4b.barcelo-room-code-photo.v1',
     'room_code'=>$allowed[$id][1],'official_asset_id'=>$allowed[$id][2],
     'owner_attested_public_source_use'=>true,'rights_independently_verified'=>false,
     'recorded_at'=>gmdate('c')
   ],true);
  }
 }
 $results[]=['id'=>$id,'status'=>$why?:($mode==='apply'?'registered':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$results],JSON_UNESCAPED_UNICODE));
