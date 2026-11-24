<?php
defined('ABSPATH') || exit;
$mode=getenv('MAD4B_ITINERARY_SCHEMA_PHASE') ?: 'preflight';
if(!in_array($mode,['preflight','apply','verify'],true))throw new RuntimeException('invalid_schema_phase');
if(!current_user_can('manage_options')) throw new RuntimeException('owner_administrator_required');
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$row=$wpdb->get_row($wpdb->prepare("SELECT id,slug,meta_fields FROM {$table} WHERE slug=%s",'tours-and-activities'),ARRAY_A);
if(!$row || (int)$row['id']!==15)throw new RuntimeException('CPT_not_found_or_unexpected');
$schema=maybe_unserialize($row['meta_fields']);
if(!is_array($schema)||count($schema)<90)throw new RuntimeException('unexpected_CPT_schema');
$index=null;
foreach($schema as $i=>$f)if(is_array($f)&&($f['name']??'')==='itinerary_item')$index=$i;
if($index===null)throw new RuntimeException('itinerary_repeater_not_found');
$original=$schema[$index];
if(($original['type']??'')!=='repeater'||count($original['repeater-fields']??[])<4)
 throw new RuntimeException('itinerary_repeater_unexpected_shape');
$fields=$original['repeater-fields'];
$expectedNames=['mad4b_overnight_kind','mad4b_overnight_destination'];
$present=[];
foreach($fields as $f)if(in_array($f['name']??'',$expectedNames,true))$present[$f['name']]=$f;
$create = [
 [
  'title'=>'Overnight accommodation type · Night after this day',
  'name'=>'mad4b_overnight_kind','type'=>'select','options_source'=>'manual',
  'options'=>[
   ['key'=>'','value'=>'Choose accommodation type'],
   ['key'=>'hotel','value'=>'Hotel'],
   ['key'=>'resort','value'=>'Resort'],
   ['key'=>'cruise','value'=>'Nile cruise'],
   ['key'=>'sleeper_train','value'=>'Sleeper train'],
   ['key'=>'none','value'=>'No overnight / Departure day'],
  ],
  'id'=>2026112201,'width'=>'50%',
  'description'=>'Authoritative type for the night AFTER this itinerary day. Choose None on departure day. Updates the accommodation stage projection; never enables booking.',
 ],
 [
  'title'=>'Overnight destination · Night after this day',
  'name'=>'mad4b_overnight_destination','type'=>'select','options_source'=>'manual',
  'options'=>[
    ['key'=>'','value'=>'Choose overnight destination'],
    ['key'=>'cairo','value'=>'Cairo / Giza metro'],
    ['key'=>'luxor','value'=>'Luxor'],
    ['key'=>'aswan','value'=>'Aswan City'],
    ['key'=>'sharm','value'=>'Sharm El Sheikh'],
    ['key'=>'hurghada','value'=>'Hurghada'],
    ['key'=>'nile_cruise','value'=>'Nile cruise sailing'],
    ['key'=>'train','value'=>'Sleeper train'],
    ['key'=>'none','value'=>'None on departure day'],
  ],
  'id'=>2026112202,'width'=>'50%',
  'description'=>'Night-time stay city, not merely a sightseeing stop. Select None together with No overnight. Preserve original itinerary titles and descriptions.',
 ]
];
if(count($present)===1)throw new RuntimeException('partial_overnight_schema_conflict');
if(count($present)===2){
 foreach($create as $f)if($present[$f['name']]!==$f)throw new RuntimeException('overnight_schema_existing_definitions_drift');
}
if($mode==='apply' && !$present){
 foreach($create as $f)$schema[$index]['repeater-fields'][]=$f;
 $before=$row['meta_fields'];$after=serialize($schema);
 $affected=$wpdb->query($wpdb->prepare("UPDATE {$table} SET meta_fields=%s WHERE id=%d AND meta_fields=%s",$after,(int)$row['id'],$before));
 if($affected!==1)throw new RuntimeException('jet_post_type_atomic_schema_write_failed');
}else $affected=0;
$actual=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$table} WHERE id=%d",(int)$row['id']));
$parsed=maybe_unserialize($actual);
$added=[];
foreach($parsed[$index]['repeater-fields'] as $f)if(in_array($f['name']??'',$expectedNames,true))$added[$f['name']]=$f;
if(($mode==='apply'||$mode==='verify') && count($added)!==2)throw new RuntimeException('new_repeater_fields_not_visible');
if(count($added)===2)foreach($create as $f)if($added[$f['name']]!==$f)
 throw new RuntimeException('repeater_schema_readback_mismatch');
echo wp_json_encode(['site'=>home_url(),'phase'=>$mode,'jetengine_tour_cpt_id'=>(int)$row['id'],
 'repeater_name'=>'itinerary_item','previous_nested_field_count'=>count($original['repeater-fields']),
 'new_nested_field_count'=>count($parsed[$index]['repeater-fields']),
 'field_names'=>array_keys($added),'fields_added'=>$affected===1?2:0,
 'original_fields_preserved'=>array_slice($parsed[$index]['repeater-fields'],0,count($original['repeater-fields']))===$original['repeater-fields'],
 'schema_hash'=>hash('sha256',(string)$actual)],JSON_UNESCAPED_SLASHES).PHP_EOL;

