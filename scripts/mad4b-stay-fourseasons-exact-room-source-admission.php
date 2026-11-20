<?php
/**
 * MAD4B exact-room Four Seasons First Residence image admission.
 * Confirms original Draft Unit identity, native Property relation, official
 * Four Seasons source-page path and press CDN source before adding a reference.
 * Never changes price, Gallery or post status. Owner-attested public use only.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)
    throw new RuntimeException('WP-CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('Invalid mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
    WP_CLI::error('Explicit owner-attested image source use required');
$path='/tmp/mad4b-fourseasons-room-approval-20261119/manifest.json';
$m=json_decode((string)@file_get_contents($path),true);
if(!is_array($m)||count($m['records']??[])!==12||!empty($m['errors']))
    WP_CLI::error('Four Seasons manifest invalid');
$approved=[70878,70879,70880,70881,70882,70883,70884,70885,70886,70887,70888,70889];
global $wpdb;$results=[];$eligible=0;
foreach($m['records'] as $r){
    $id=absint($r['unit_id']??0);
    $property=absint($r['property_id']??0);
    $image=(string)($r['image_url']??'');
    $page=(string)($r['source']??'');
    $room=(string)($r['room_name']??'');
    $title=html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8');
    $part=explode(' — ',$title,2);
    $u=wp_parse_url($image);
    $p=wp_parse_url($page);
    $error='';
    if(!in_array($id,$approved,true)||$property!==70734)
        $error='not_the_approved_room_or_property';
    elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
       ||get_post_type($property)!=='properties'
       ||!in_array(get_post_status($property),['publish','draft'],true))
        $error='unit_or_property_ineligible';
    elseif(count($part)!==2||strcasecmp(trim($part[1]),$room)!==0)
        $error='room_name_mismatch';
    elseif(($u['scheme']??'')!=='https'
       ||($u['host']??'')!=='press.fourseasons.com'
       ||!preg_match('~^/content/dam/fourseasons/images/web/CAF/CAF_[A-Za-z0-9_]+\.jpg$~D',$u['path']??''))
        $error='not_official_press_image';
    elseif(($p['scheme']??'')!=='https'
       ||($p['host']??'')!=='www.fourseasons.com'
       ||!preg_match('~^/cairofr/accommodations/(?:specialty-suites|suites|specialty-rooms|guest_rooms)/[A-Za-z0-9_-]+/$~D',$p['path']??''))
        $error='not_original_hotel_room_page';
    elseif((int)$wpdb->get_var($wpdb->prepare(
       "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
        WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
        223,$property,$id))!==1)
        $error='jetengine_property_relation_mismatch';
    elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true))
        $error='existing_room_media_must_be_preserved';
    elseif(get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true))
        $error='existing_source_record_must_be_preserved';
    else{
        $ref=[
          'property_id'=>$property,
          'room_name'=>$room,
          'image_url'=>$image,
          'source_room_page'=>$page,
          'rights_status'=>'external_source_reference_only_not_licensed_for_copy'
        ];
        if($mode==='apply'){
            if(!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$ref,true))
                $error='atomic_source_insert_failed';
            else{
                add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
                    'schema'=>'mad4b.official-room-image-observation.v1',
                    'hotel_room_page'=>$page,
                    'official_press_image'=>$image,
                    'owner_attested_public_use'=>true,
                    'rights_independently_verified'=>false,
                    'recorded_at'=>gmdate('c')
                ],true);
            }
        }
        if(!$error)$eligible++;
    }
    $results[]=['unit_id'=>$id,'status'=>$error?:($mode==='apply'?'source_registered':'source_eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'eligible_or_registered'=>$eligible,
    'items'=>$results],JSON_UNESCAPED_SLASHES));
