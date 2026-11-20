<?php
/**
 * Exact, reviewed Hilton Cairo hotel galleries. WP-CLI-only reference admission.
 * The existing JetEngine Unit/property relation and Draft status are mandatory.
 * One Twin Nile photograph is provisional and flagged for room-variant review.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('invalid_mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')WP_CLI::error('explicit_approval_required');
$manifest=json_decode((string)@file_get_contents('/tmp/mad4b-hilton-cairo-rooms-20261119/manifest.json'),true);
if(!is_array($manifest)||count($manifest['records']??[])!==4||!empty($manifest['errors']))WP_CLI::error('manifest_invalid');
$allow=[
 70853=>[36477,'Duplex Suite','CAIRHTW','15122425/duplex-suite-living-area-.jpg'],
 70851=>[36477,'Executive King Room with Nile View','CAIRHTW','15122444/executive-king-room-nile-view-.jpg'],
 70855=>[36479,'Royal Suite Nile View','CAIGNHI','20598759/royal-suite-bedroom-1.jpg'],
 70854=>[36479,'Twin Guest Room Nile View','CAIGNHI','22705405/guest-room-high-floor-nile-view.jpg']
];
global $wpdb;$out=[];
foreach($manifest['records'] as $r){
 $id=(int)($r['unit_id']??0);$pid=(int)($r['property_id']??0);
 $room=(string)($r['room_name']??'');$title=(string)($r['post_title']??'');
 $url=(string)($r['image_url']??'');$page=(string)($r['source']??'');
 $why='';
 if(!isset($allow[$id])||$pid!==$allow[$id][0]||$room!==$allow[$id][1])
     $why='not_approved_exact_entity';
 elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
     ||get_post_type($pid)!=='properties'||get_post_status($pid)!=='publish')
     $why='draft_unit_published_property_required';
 elseif(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8')!==$title)
     $why='existing_editorial_title_changed';
 elseif((int)$wpdb->get_var($wpdb->prepare(
     "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
     $pid,$id))!==1)
     $why='original_jetengine_relation_mismatch';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true)
     ||get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true))
     $why='existing_image_or_source_record';
 elseif(strpos($url,'https://www.hilton.com/im/en/'.$allow[$id][2].'/'.$allow[$id][3])!==0
     ||$page!==('https://www.hilton.com/en/hotels/'.($pid===36477?'cairhtw-ramses-hilton':'caignhi-cairo-grand-nile').'/gallery/'))
     $why='untrusted_official_gallery';
 else {
     $ref=[
      'property_id'=>$pid,'room_name'=>$room,'image_url'=>$url,'source_room_page'=>$page,
      'rights_status'=>'external_source_reference_only_not_licensed_for_copy'
     ];
     if($mode==='apply'){
       if(!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$ref,true))
         $why='atomic_source_insert_failed';
       else add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
         'schema'=>'mad4b.hilton-cairo-gallery-source.v1',
         'original_property_title'=>get_the_title($pid),
         'original_unit_title'=>get_the_title($id),
         'editorial_title_correction_pending'=>$pid===36477,
         'room_variant_confirmation_pending'=>$id===70854,
         'source_photo_confidence'=>$r['match_confidence']??'unknown',
         'owner_attested_public_source_use'=>true,
         'rights_independently_verified'=>false,
         'recorded_at'=>gmdate('c')
       ],true);
     }
 }
 $out[]=['unit_id'=>$id,'status'=>$why?:($mode==='apply'?'registered':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$out],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
