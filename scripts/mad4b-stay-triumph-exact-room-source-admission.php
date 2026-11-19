<?php
/**
 * MAD4B — exact-room official source reference admission.
 * Additive to existing Unit CCT/CPT; never touches images, prices, status or inventory.
 * Only WP-CLI, only owner-attested source URLs on verified matching hotel room pages.
 *
 * Dry-run: MAD4B_STAY_ROOM_REFERENCE_MODE=dry-run wp eval-file ...
 * Apply: MAD4B_STAY_ROOM_REFERENCE_MODE=apply
 *        MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED=1 wp eval-file ...
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)
    throw new RuntimeException('WP CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('Invalid reference mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
    WP_CLI::error('Owner-attested photo source admission required');
$manifest='/tmp/mad4b-triumph-room-preflight/manifest.json';
$json=json_decode((string)@file_get_contents($manifest),true);
if(!is_array($json)||!is_array($json['records']??null)
    ||count($json['records'])!==18
    ||!empty($json['errors']))WP_CLI::error('Exact Triumph batch manifest invalid');
global $wpdb;
$out=[];$approved=0;
foreach($json['records'] as $r){
    $id=absint($r['unit_id']??0);
    $pid=absint($r['property_id']??0);
    $page=(string)($r['source']??'');
    $image=(string)($r['image_url']??'');
    $room=(string)($r['room_name']??'');
    $reason='';
    $type=($pid===36209)?'plaza':(($pid===36140)?'luxury':'');
    $u=wp_parse_url($page);
    $im=wp_parse_url($image);
    $name=html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8');
    $subject=explode(' — ',$name,2);
    if(!in_array($id,[70806,70807,70808,70809,70810,70811,70812,70813,
        70792,70793,70794,70795,70796,70797,70798,70799,70800,70801],true))
        $reason='unit_not_in_approved_batch';
    elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
        ||get_post_type($pid)!=='properties'||get_post_status($pid)!=='publish')
        $reason='draft_unit_or_published_property_required';
    elseif(!$type||count($subject)!==2||strcasecmp(trim($subject[1]),trim($room))!==0)
        $reason='room_identity_mismatch';
    elseif(($u['scheme']??'')!=='https'||($u['host']??'')!=='triumphhotel.com'
        ||!preg_match('~^/'.$type.'/rooms/[a-z0-9-]+/$~D',$u['path']??''))
        $reason='untrusted_official_page';
    elseif(($im['scheme']??'')!=='https'||($im['host']??'')!=='triumphhotel.com'
        ||!preg_match('~^/media/room_images/[a-zA-Z0-9-]+\.jpg$~D',$im['path']??''))
        $reason='untrusted_image';
    elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true))
        $reason='existing_unit_media_preserved';
    elseif(get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true))
        $reason='original_reference_preserved';
    elseif((int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
         WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
        $pid,$id))!==1)
        $reason='native_relation_mismatch';
    else{
        $v=[
          'property_id'=>$pid,'room_name'=>$room,
          'image_url'=>$image,'source_room_page'=>$page,
          'rights_status'=>'external_source_reference_only_not_licensed_for_copy',
        ];
        if($mode==='apply'){
            if(!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$v,true)){
                $reason='atomic_original_reference_insert_failed';
            }else{
                add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
                    'schema'=>'mad4b.official-room-image-observation.v1',
                    'hotel_room_page'=>$page,'room_name'=>$room,
                    'source_image_count'=>count((array)($r['all_images']??[])),
                    'owner_attested_public_use'=>true,
                    'rights_independently_verified'=>false,
                    'recorded_at'=>gmdate('c')
                ],true);
                $approved++;
            }
        }else $approved++;
    }
    $out[]=['unit_id'=>$id,'room'=>$room,'status'=>$reason?:($mode==='apply'?'source_registered':'source_eligible'),
        'source_page'=>$page];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'eligible_or_registered'=>$approved,
    'items'=>$out],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
