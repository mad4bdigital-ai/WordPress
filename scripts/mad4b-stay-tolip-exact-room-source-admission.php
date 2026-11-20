<?php
/**
 * MAD4B Tolip Gardens source admission: four exact room category photos.
 * Original JetEngine Unit + Property only, no publishing or price mutation.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP CLI only');
$mode=getenv('MAD4B_STAY_ROOM_REFERENCE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('mode');
if($mode==='apply'&&getenv('MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED')!=='1')
 WP_CLI::error('Owner approval required');
$base='https://tolipgroup.com/wp-content/uploads/2024/04/';
$page='https://tolipgroup.com/tolip-hotel-gardens/accommodation/';
$rooms=[
 70802=>['Standard Rooms','standard-room-3-thg-acc.jpg'],
 70803=>['Deluxe Rooms','deluxe-room-1-THG-acc.jpg'],
 70804=>['Suites','suite-4-thg-acc.jpg'],
 70805=>['Chalets','chalet-THG-acc.jpg'],
];
global $wpdb;
$out=[];
foreach($rooms as $id=>$data){
 $title=html_entity_decode((string)get_the_title($id),ENT_QUOTES,'UTF-8');
 $parent=36197;
 $reason='';
 if(get_post_type($id)!=='units'||get_post_status($id)!=='draft' ||
    get_post_type($parent)!=='properties'||get_post_status($parent)!=='publish')
    $reason='unverified_draft_unit_or_parent';
 elseif(substr($title,-strlen(' — '.$data[0]))!==' — '.$data[0] || preg_match('/QA\s+ONLY|fixture|test|demo/i',$title))
    $reason='unverified_exact_unit_label';
 elseif((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=223 AND parent_object_id=%d AND child_object_id=%d",
    $parent,$id))!==1)
    $reason='wrong_jetengine_relation';
 elseif(get_post_thumbnail_id($id)||get_post_meta($id,'unit_gellery',true))
    $reason='existing_photo_preserved';
 elseif(get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true))
    $reason='existing_reference_preserved';
 else{
  $ref=['property_id'=>$parent,'room_name'=>$data[0],
    'image_url'=>$base.$data[1],'source_room_page'=>$page,
    'rights_status'=>'external_source_reference_only_not_licensed_for_copy'];
  if($mode==='apply'){
    if(!add_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',$ref,true))
      $reason='reference_atomic_insert_failed';
    else{
      add_post_meta($id,'_mad4b_official_room_photo_source_observation_v1',[
       'schema'=>'mad4b.official-room-photo-observation.v1',
       'official_photo'=>$ref['image_url'],
       'category'=>$data[0],
       'owner_attested_public_source_use'=>true,
       'rights_independently_verified'=>false,
       'quality_review_required'=>$id===70805,
       'recorded_at'=>gmdate('c')
      ],true);
    }
  }
 }
 $out[]=['id'=>$id,'status'=>$reason?:($mode==='apply'?'registered':'eligible')];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
