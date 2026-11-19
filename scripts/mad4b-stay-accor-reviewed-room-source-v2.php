<?php
/**
 * MAD4B Accor reviewed room-image source admission v2.
 * Preserves the old, rejected/duplicate official reference unchanged.
 * Requires exact existing room title, native JetEngine relation, official
 * per-room detail page H1 corroborated by preflight manifest, and owner approval.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('invalid_mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
 WP_CLI::error('owner_attestation_required');
$path='/tmp/mad4b-accor-room-v2-20261119/manifest.json';
$m=json_decode((string)@file_get_contents($path),true);
if(!is_array($m)||count($m['records']??[])!==6||!empty($m['errors']))
 WP_CLI::error('source_manifest_invalid');
$approved=[70909=>70738,70912=>70738,70891=>70735,70894=>70735,70816=>36355,70817=>36355];
global $wpdb;$result=[];$accepted=0;
foreach($m['records'] as $r){
 $id=(int)($r['unit_id']??0);
 $pid=(int)($r['property_id']??0);
 $url=(string)($r['image_url']??'');
 $page=(string)($r['source']??'');
 $room=(string)($r['room_name']??'');
 $old=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
 $existing=get_post_meta($id,'_mad4b_unit_photo_source_review_v2',true);
 $title=html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8');
 $bits=explode(' — ',$title,2);
 $u=wp_parse_url($url);$p=wp_parse_url($page);
 $why='';
 if(!isset($approved[$id])||$approved[$id]!==$pid)
    $why='not_approved_room';
 elseif(get_post_type($id)!=='units'||get_post_status($id)!=='draft'
    ||get_post_type($pid)!=='properties'
    ||!in_array(get_post_status($pid),['draft','publish'],true))
    $why='unit_or_property_ineligible';
 elseif(count($bits)!==2||strcasecmp(trim($bits[1]),$room)!==0)
    $why='unit_identity_mismatch';
 elseif((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
    $pid,$id))!==1)
    $why='wrong_original_relation';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true))
    $why='media_already_present';
 elseif($existing)
    $why='review_v2_exists_preserve';
 elseif(!is_array($old)||($old['room_name']??'')!==$room
    ||(int)($old['property_id']??0)!==$pid
    ||($old['rights_status']??'')!=='external_source_reference_only_not_licensed_for_copy'
    ||($old['image_url']??'')===$url)
    $why='original_reference_mismatch_or_not_distinct';
 elseif(($u['scheme']??'')!=='https'||($u['host']??'')!=='m.ahstatic.com'
    ||!preg_match('~^/is/image/accorhotels/[A-Za-z0-9_-]+:3by2$~D',$u['path']??'')
    ||($p['scheme']??'')!=='https'
    ||!in_array($p['host']??'',['www.fairmont.com','sofitel.accor.com'],true))
    $why='non_official_source_or_page';
 else{
   $v=[
      'schema'=>'mad4b.stay-photo-source-reviewed-v2',
      'property_id'=>$pid,
      'room_name'=>$room,
      'image_url'=>$url,
      'source_room_page'=>$page,
      'rights_status'=>'external_source_reference_only_not_licensed_for_copy',
      'owner_attested_public_source_use'=>true,
      'rights_independently_verified'=>false,
      'original_v1_reference_preserved'=>true,
      'verified_room_detail_h1'=>true,
      'image_sha256'=>(string)($r['sha256']??''),
      'recorded_at'=>gmdate('c')
   ];
   if($mode==='apply'&&!add_post_meta($id,'_mad4b_unit_photo_source_review_v2',$v,true))
       $why='atomic_review_add_failed';
   if(!$why)$accepted++;
 }
 $result[]=['id'=>$id,'status'=>$why?:($mode==='apply'?'v2_reviewed':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'accepted'=>$accepted,'items'=>$result],JSON_UNESCAPED_UNICODE));
