<?php
/**
 * Official provider-category shadow Unit drafts for two hotel source records.
 * Does not assign Package tier, price, inventory, availability or Tour Rate.
 */
if(!defined('ABSPATH')||!defined('WP_CLI'))return;
$sets=[
  36293=>[
    'slug'=>'hilton-luxor','brand'=>'Hilton Luxor Resort & Spa',
    'destination'=>'luxor','source'=>'https://www.hilton.com/en-gb/hotels/luxhitw-hilton-luxor-resort-and-spa/gallery/',
    'rooms'=>[
      ['Deluxe King Room','room',null,null,null]
    ],
    'address'=>['name'=>'New Karnak, Luxor, Egypt','confidence'=>'neighborhood_only',
      'source'=>'https://www.hilton.com/en/hotels/luxhitw-hilton-luxor-resort-and-spa/']
  ],
  36084=>[
    'slug'=>'steigenberger-alcazar','brand'=>'Steigenberger Alcazar',
    'destination'=>'sharm-el-sheikh','source'=>'https://hrewards.com/en/steigenberger-alcazar-el-sheikh',
    'rooms'=>[
      ['Superior room garden view','room',2,2,0],
      ['Superior room pool view','room',2,2,0],
      ['Superior Room Swim-Up','room',2,2,0],
      ['Deluxe Family Room Garden View','room',4,2,2],
      ['Deluxe Family Room Swim-Up','room',4,2,2],
      ['Executive Suite','suite',2,2,0],
      ['Royal Suite','suite',null,null,null],
      ['Deluxe Suite','suite',2,2,0],
      ['Villas','other',7,4,3],
      ['Deluxe family room with partial sea view','room',4,2,2],
      ['Deluxe Family Room Pool View','room',4,2,2],
      ['Superior room with partial sea view','room',2,2,0],
    ],
    'address'=>['name'=>'Nabq Bay, Sharm El Sheikh, South Sinai, Egypt',
      'confidence'=>'neighborhood_only','source'=>'https://hrewards.com/en/steigenberger-alcazar-el-sheikh']
  ],
];
$fail=static function($code){WP_CLI::error('MAD4B_UNIT_SOURCE_INTAKE_STOP '.$code);};
if(!current_user_can('edit_posts'))$fail('permission_denied');
$relation=jet_engine()->relations->get_active_relations()[223]??null;
if(!$relation || $relation->get_args('parent_object')!=='posts::properties' ||
    $relation->get_args('child_object')!=='posts::units')$fail('native_relation_contract_unavailable');
$pre=[];$existing=[];$preflight=!empty($GLOBALS['mad4b_shadow_preflight']);
foreach($sets as $pid=>$config){
 $post=get_post($pid);
 if(!$post||$post->post_type!=='properties'||$post->post_status!=='publish' ||
    $post->post_name!==$config['slug']||!current_user_can('edit_post',$pid))
    $fail('property_not_verified_'.$pid);
 $destination=wp_get_object_terms($pid,'destinations',['fields'=>'slugs']);
 if(is_wp_error($destination)||!in_array($config['destination'],$destination,true))
    $fail('property_wrong_destination_'.$pid);
 if(get_post_meta($pid,'property_map',true)!=='')$pre[$pid]['map_present']=true;
 $old=get_post_meta($pid,'_mad4b_property_location_evidence_v1',true);
 if($old&&!is_array($old))$fail('conflicting_location_receipt_'.$pid);
 $rows=[];
 foreach(get_posts(['post_type'=>'units','post_status'=>'any','fields'=>'ids',
      'posts_per_page'=>-1,'suppress_filters'=>true,
      'meta_key'=>'_mad4b_room_source_receipt_v1','meta_compare'=>'EXISTS']) as $uid){
  $r=get_post_meta($uid,'_mad4b_room_source_receipt_v1',true);
  if(!is_array($r)||($r['source_url']??'')!==$config['source']||
     (int)($r['property_id']??0)!==$pid)continue;
  $key=(string)($r['room_category']??'');
  if(isset($rows[$key]))$fail('duplicate_receipt_'.$pid);
  $rows[$key]=(int)$uid;
 }
 foreach($config['rooms'] as $item)if(isset($rows[$item[0]])){
  if(get_post_status($rows[$item[0]])!=='draft')$fail('existing_room_not_draft_'.$pid);
 }
 $existing[$pid]=$rows;
 $pre[$pid]=[
   'property_id'=>$pid,'post_title'=>$post->post_title,'source'=>$config['source'],
   'room_count'=>count($config['rooms']),'already_present'=>count($rows),
   'new_units_proposed'=>count($config['rooms'])-count($rows),
   'source_name'=>$config['brand'],
   'pricing_explicitly_disallowed'=>true,'preserve_tier_taxonomy'=>true,
   'preserve_property_map'=>true
 ];
}
if($preflight){WP_CLI::line(wp_json_encode(['preflight'=>$pre],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return;}
$created=[];$out=[];
foreach($sets as $pid=>$cfg){
 if(!get_post_meta($pid,'_mad4b_property_location_evidence_v1',true)){
  $location=[
   'schema'=>'mad4b.property-location-evidence.v1',
   'address'=>$cfg['address']['name'],'locality'=>$cfg['destination'],
   'official_property_source'=>$cfg['address']['source'],
   'reviewed_on'=>'2026-11-21','precision'=>$cfg['address']['confidence'],
   'latitude'=>null,'longitude'=>null,
   'status'=>'verified_provider_region_not_geocoded',
   'commercial_rates_approved'=>false
  ];
  if(!add_post_meta($pid,'_mad4b_property_location_evidence_v1',$location,true))
    $fail('location_receipt_failed_'.$pid);
 }
 $out[$pid]=[];
 foreach($cfg['rooms'] as [$category,$kind,$max,$adults,$children]){
  $id=$existing[$pid][$category]??0;
  if(!$id){
   $id=wp_insert_post([
      'post_type'=>'units','post_status'=>'draft',
      'post_title'=>$cfg['brand'].' — '.$category,
      'post_content'=>'Verified public hotel room category. The supplier cost, room allotment, booking dates and commercial terms remain unverified.',
      'post_author'=>get_current_user_id()],true);
   if(is_wp_error($id)||!(int)$id||get_post_status($id)!=='draft')
      $fail('unit_creation_failed_'.$pid);
   $id=(int)$id;$created[]=$id;
   update_post_meta($id,'unit_kind',$kind);
   update_post_meta($id,'unit_readiness','pending_review');
   update_post_meta($id,'unit_available','false');
   if($max!==null){
     update_post_meta($id,'unit_max_occupancy',$max);
     update_post_meta($id,'no_adults',$adults);
     update_post_meta($id,'no_children',$children);
   }
   update_post_meta($id,'_mad4b_room_source_receipt_v1',[
     'schema'=>'mad4b.hotel-unit-source.v1',
     'property_id'=>$pid,'room_category'=>$category,
     'source_url'=>$cfg['source'],'source_type'=>'official_hotel_page',
     'source_confidence'=>'official_current_page','verified_on'=>'2026-11-21',
     'commercial_status'=>'pending_supplier_review',
     'occupancy_verified'=>$max!==null,
     'inventory_verified'=>false,'rates_verified'=>false,
     'images_verified'=>false,'price_and_units_intentionally_unset'=>true]);
   if(!$relation->update($pid,$id))$fail('unit_native_relation_failed_'.$pid);
  }
  if(get_post_type($id)!=='units'||get_post_status($id)!=='draft'||
     get_post_meta($id,'unit_start_price',true)!=='' ||
     in_array((string)get_post_meta($id,'unit_available',true),['true','1','yes'],true))
      $fail('room_unexpected_commercial_state_'.$pid);
  $linked=$relation->get_parents($id);$okay=false;
  foreach((array)$linked as $x)if((int)($x['parent_object_id']??0)===$pid)$okay=true;
  if(!$okay)$fail('native_link_readback_failed_'.$pid);
  $out[$pid][]=['id'=>$id,'category'=>$category,'capacity_from_official'=>$max,
     'supplier_cost'=>null,'status'=>'draft'];
 }
}
WP_CLI::line(wp_json_encode(['status'=>'SOURCE_CATEGORIES_LINKED_AS_DRAFTS',
   'site'=>home_url(),'newly_created'=>$created,
   'original_properties'=>array_keys($out),'units'=>$out,
   'rate_created'=>false,'commercial_availability_assumed'=>false,
   'tour_price_source'=>null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

