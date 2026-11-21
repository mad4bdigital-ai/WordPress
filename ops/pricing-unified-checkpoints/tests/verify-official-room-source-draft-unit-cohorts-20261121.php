<?php
/** Post-provisioning READ ONLY official room source smoke on both companies. */
defined('ABSPATH')||exit;
if(!defined('WP_CLI')||!WP_CLI)return;
$fail=[];$checks=0;
$check=static function(bool $ok,string $what)use(&$checks,&$fail):void{
  $checks++;if(!$ok)$fail[]=$what;
};
$want=[
36181=>['source'=>'https://obeliskhotels.com/rooms/','drafts'=>4,
 'city'=>'aswan-city','room_occupancy_recorded'=>0,'tour'=>60437],
36293=>['source'=>'https://www.hilton.com/en-gb/hotels/luxhitw-hilton-luxor-resort-and-spa/gallery/',
 'drafts'=>1,'city'=>'luxor','room_occupancy_recorded'=>0,'tour'=>60627],
36084=>['source'=>'https://hrewards.com/en/steigenberger-alcazar-el-sheikh',
 'drafts'=>12,'city'=>'sharm-el-sheikh','room_occupancy_recorded'=>11,'tour'=>60629],
];
global $wpdb;
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour-rates'");
$check(class_exists('MAD4B_Package_Stage_Sourcing_V1',false),'native source inspector loaded');
$check(class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false),'commercial guard loaded');
$check(current_user_can('commerce_manage_pricing'),'pricing authorization');
$rel=jet_engine()->relations->get_active_relations()[223]??null;
$check(is_object($rel),'original Relation 223 exists');
$summary=['site'=>home_url(),'properties'=>[],'draft_count'=>0,'source_capacity_count'=>0,'source_stages'=>[]];
foreach($want as $property=>$config){
 $p=get_post($property);
 $check($p instanceof WP_Post && $p->post_type==='properties' &&
   $p->post_status==='publish','typed source Property '.$property);
 $terms=wp_get_object_terms($property,'destinations',['fields'=>'slugs']);
 $check(is_array($terms) && in_array($config['city'],$terms,true),
   'destination proof '.$property);
 $location=get_post_meta($property,'_mad4b_property_location_evidence_v1',true);
 $check(is_array($location)&&!empty($location['official_property_source']) &&
   $location['latitude']===null && $location['longitude']===null,
   'no guessed geocode '.$property);
 $drafts=[];$capacityCount=0;
 foreach((array)$rel->get_children($property) as $line){
   $uid=(int)($line['child_object_id']??0);
   if(!$uid)continue;
   $r=get_post_meta($uid,'_mad4b_room_source_receipt_v1',true);
   if(!is_array($r)||($r['source_url']??'')!==$config['source'])continue;
   $drafts[]=$uid;
   $check(get_post_type($uid)==='units' &&
     get_post_status($uid)==='draft',
     'Unit source is draft '.$uid);
   $check((int)($r['property_id']??0)===$property &&
     ($r['rates_verified']??null)===false &&
     ($r['inventory_verified']??null)===false &&
     ($r['price_and_units_intentionally_unset']??null)===true,
     'official receipt never certifies commercial booking '.$uid);
   $check(get_post_meta($uid,'unit_start_price',true)==='' &&
     get_post_meta($uid,'unit_inventory_quantity',true)==='' &&
     !in_array(get_post_meta($uid,'unit_available',true),['true','yes','1'],true),
     'no retail price, stock or available flag '.$uid);
   $cap=get_post_meta($uid,'unit_max_occupancy',true);
   if(is_numeric($cap)&&(int)$cap>0){$capacityCount++;
     $check(($r['occupancy_verified']??false)===true,
       'positive occupancy only where official source evidence '.$uid);}
   else $check(($r['occupancy_verified']??false)===false,
       'unknown occupancy intentionally left empty '.$uid);
 }
 $check(count($drafts)===$config['drafts'],
   'exact official category unit draft count '.$property);
 $check(count(array_unique($drafts))===count($drafts),
   'no duplicated relations or unit category IDs '.$property);
 $check($capacityCount===$config['room_occupancy_recorded'],
   'occupancy evidence count '.$property);
 $summary['draft_count']+=count($drafts);
 $summary['source_capacity_count']+=$capacityCount;
 $summary['properties'][$property]=['linked_drafts'=>$drafts,
   'capacity_recorded'=>$capacityCount];
 $review=MAD4B_Package_Stage_Rate_Guard_V1::review($config['tour'],2354,
    '2027-02-01','2027-03-31');
 $check(($review['applies']??false)===true &&
   ($review['ready']??null)===false && !empty($review['blockers']),
   'new Unit drafts never approve source Tour Rate '.$config['tour']);
 $lab=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect($config['tour'],2354);
 $check(is_array($lab) && $lab['supplier_cost_total']===null &&
   $lab['generated_rate_ready']===false,'Pricing Lab remains unpriced');
 if(is_array($lab)){
  $matches=[];
  foreach($lab['stages'] as $stage){
   foreach(($stage['source_discovery']['properties']??[]) as $source){
    if((int)$source['id']!==$property)continue;
    $matches[]=$source;
   }
  }
  $check(count($matches)===1,
    'native source Property located in its original stage '.$property);
  if(count($matches)===1){
   $row=$matches[0];
   $check((int)$row['native_published_unit_count']===0 &&
     (int)$row['native_draft_unit_count']===$config['drafts'],
     'source inspector correctly separates drafts from published stock');
   $check(count($row['source_unit_draft_editors'])===$config['drafts'],
     'all original Unit edit links available');
   $summary['source_stages'][]=['tour'=>$config['tour'],
       'hotel'=>$property,'drafts'=>$row['native_draft_unit_count']];
  }
 }
}
if(in_array('aswan-city',wp_get_object_terms(36181,'destinations',['fields'=>'slugs']),true))
  $check(get_post_meta(36181,'property_map',true)==='Corniche El Nile St, Aswan, Egypt',
    'Obelisk address saved to original JetEngine map field');
$check($summary['draft_count']===17 && $summary['source_capacity_count']===11,
  'official source intake 17 drafts with 11 supported occupancies');
$after=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour-rates'");
$check($before===$after,'no Tour Rates written');
if($fail)WP_CLI::error(wp_json_encode([
 'checks'=>$checks,'failed'=>$fail,'summary'=>$summary],JSON_UNESCAPED_SLASHES));
WP_CLI::line(wp_json_encode(['checks_passed'=>$checks,'checks_failed'=>0,
 'no_commercial_prices_created'=>true,'rate_count_before'=>$before,
 'rate_count_after'=>$after,'summary'=>$summary],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
